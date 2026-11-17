<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use RuntimeException;

/** Purpose-bound internal acceptance. No public bearer downloads or native PDF certificate claim. */
final class DocumentService
{
    public const CONSENT_VERSION = 'glpi-explicit-v1';
    public const PURPOSES = ['request', 'handover', 'return_receipt', 'return_inspection'];
    private const MAX_BYTES = 16 * 1024 * 1024;

    public static function prepare(int $bookingId, string $purpose): int
    {
        self::assertPurpose($purpose);
        if (Store::inTransaction()) {
            throw new RuntimeException('Prepare o documento após confirmar a operação da reserva.');
        }
        $booking = Store::row('bookings', $bookingId);
        Access::assertBooking($booking, 'read');
        $scope = self::scope($booking, $purpose);
        self::assertPreparation($booking, $purpose, $scope);
        $scopeHash = self::scopeHash($scope);
        foreach (Store::find('agreements', ['bookings_id' => $bookingId, 'purpose' => $purpose], ['ORDER' => 'id DESC']) as $existing) {
            $snapshot = Store::decode($existing['snapshot_json'] ?? null);
            if (hash_equals((string) ($snapshot['scope_hash'] ?? ''), $scopeHash)) {
                return (int) $existing['id'];
            }
        }
        $token = bin2hex(random_bytes(32));
        $snapshot = [
            'scope' => $scope, 'scope_hash' => $scopeHash, 'purpose' => $purpose,
            'title' => self::title($purpose, self::roomsOnly($scope)), 'statement' => self::statement($purpose, self::roomsOnly($scope)),
            'consent_text' => self::baseConsent(), 'template_version' => PdfRenderer::TEMPLATE_VERSION,
            'prepared_at' => Store::now(), 'timezone' => date_default_timezone_get(),
            'tickets_id' => (int) ($booking['tickets_id'] ?? 0), 'verification_token' => $token,
        ];
        $signers = self::signers($booking, $purpose, $scope);
        $bytes = PdfRenderer::render($snapshot);
        $sourceName = self::writeFile($token . '-source.pdf', $bytes);
        $inserted = false;
        try {
            $id = Store::transaction(function () use ($bookingId, $purpose, $scopeHash, $snapshot, $signers, $bytes, $sourceName, $token, &$inserted): int {
                $locked = Store::lock('bookings', $bookingId);
                Access::assertBooking($locked, 'read');
                $currentScope = self::scope($locked, $purpose);
                self::assertPreparation($locked, $purpose, $currentScope);
                if (!hash_equals($scopeHash, self::scopeHash($currentScope))) {
                    throw new \DomainException('A reserva mudou durante a geração. Atualize e prepare uma nova versão.');
                }
                foreach (Store::find('agreements', ['bookings_id' => $bookingId, 'purpose' => $purpose], ['ORDER' => 'id DESC']) as $row) {
                    if (hash_equals((string) (Store::decode($row['snapshot_json'] ?? null)['scope_hash'] ?? ''), $scopeHash)) {
                        return (int) $row['id'];
                    }
                }
                $id = Store::insert('agreements', [
                    'bookings_id' => $bookingId, 'entities_id' => (int) $locked['entities_id'],
                    'revision' => (int) ($locked['scope_revision'] ?? $locked['revision'] ?? 1),
                    'purpose' => $purpose, 'status' => 'prepared',
                    'source_path' => $sourceName, 'source_sha256' => hash('sha256', $bytes),
                    'snapshot_json' => Store::json($snapshot), 'signers_json' => Store::json($signers),
                    'consent_text' => self::baseConsent(), 'consent_version' => self::CONSENT_VERSION,
                    'template_version' => PdfRenderer::TEMPLATE_VERSION, 'verification_token' => $token,
                    'source_documents_id' => 0, 'final_documents_id' => 0, 'attachment_status' => 'pending',
                    'date_creation' => Store::now(), 'date_mod' => Store::now(),
                ]);
                Store::event($bookingId, 'document.prepared', ['agreements_id' => $id, 'purpose' => $purpose, 'source_sha256' => hash('sha256', $bytes)]);
                $inserted = true;
                return $id;
            });
        } catch (\Throwable $e) {
            @unlink(self::path($sourceName));
            throw $e;
        }
        if (!$inserted) {
            @unlink(self::path($sourceName));
        }
        return $id;
    }

    public static function forBooking(int $bookingId): array
    {
        $booking = Store::row('bookings', $bookingId);
        Access::assertBooking($booking, 'read');
        $rows = Store::find('agreements', ['bookings_id' => $bookingId], ['ORDER' => 'id DESC']);
        foreach ($rows as &$row) {
            $row['is_current'] = self::current($row, $booking);
            $row['can_sign'] = $row['is_current'] && self::roles($row, Access::userId()) !== [];
        }
        unset($row);
        return $rows;
    }

    public static function get(int $id): array
    {
        $row = Store::row('agreements', $id);
        $booking = Store::row('bookings', (int) $row['bookings_id']);
        Access::assertBooking($booking, 'read');
        $row['snapshot'] = Store::decode($row['snapshot_json'] ?? null);
        $row['signers'] = Store::decode($row['signers_json'] ?? null);
        $row['acceptances'] = self::acceptances($id);
        $row['is_current'] = self::current($row, $booking);
        $row['roles'] = self::roles($row, Access::userId());
        return $row;
    }

    public static function consent(array $doc, int $userId): string
    {
        $roles = self::roles($doc, $userId);
        if ($roles === []) {
            throw new \DomainException('Este usuário não é um signatário designado.');
        }
        $text = (string) ($doc['consent_text'] ?? self::baseConsent());
        $snapshot = $doc['snapshot'] ?? Store::decode($doc['snapshot_json'] ?? null);
        $roomsOnly = self::roomsOnly((array) ($snapshot['scope'] ?? []));
        foreach ($roles as $role) {
            $text .= ' ' . match ($role) {
                'borrower' => in_array($doc['purpose'], ['return_receipt', 'return_inspection'], true)
                    ? ($roomsOnly ? 'Como beneficiário, confirmo ciência deste registro de encerramento do uso da sala. Posso contestar divergências no chamado; o aceite não atribui automaticamente culpa ou responsabilidade por danos.' : 'Como beneficiário, confirmo ciência deste registro de devolução. Posso contestar divergências no chamado; o aceite não atribui automaticamente culpa ou responsabilidade por danos.')
                    : ($roomsOnly ? 'Como beneficiário, aceito a responsabilidade pelo uso e conservação das salas descritas, no período indicado. Este aceite prévio não declara que o uso já ocorreu.' : 'Como beneficiário, aceito a responsabilidade de uso e devolução dos recursos descritos, no período indicado. Este aceite prévio não declara que a retirada física já ocorreu.'),
                'receiver' => 'Como recebedor, atesto os itens e quantidades que efetivamente recebi e os horários registrados, sem declarar aprovação de testes ainda não realizados.',
                'inspector' => 'Como inspetor, atesto somente as verificações, resultados e destinações registrados neste documento.',
                default => throw new \DomainException('Papel de assinatura inválido.'),
            };
        }
        return $text;
    }

    public static function issueChallenge(int $documentId): string
    {
        $doc = self::get($documentId);
        self::assertSignable($doc);
        $token = bin2hex(random_bytes(32));
        $consent = self::consent($doc, Access::userId());
        Store::insert('signing_challenges', [
            'agreements_id' => $documentId, 'users_id' => Access::userId(),
            'challenge_hash' => hash('sha256', $token), 'session_hash' => self::sessionHash(),
            'expires_at' => date('Y-m-d H:i:s', time() + 600), 'source_sha256' => $doc['source_sha256'],
            'revision' => (int) $doc['revision'], 'consent_sha256' => hash('sha256', $consent),
            'date_creation' => Store::now(),
        ]);
        return $token;
    }

    /** $consent is the exact displayed consent SHA-256, submitted only after an explicit checkbox. */
    public static function accept(int $documentId, string $challenge, string $consent, string $key): array
    {
        if (Store::inTransaction()) {
            throw new RuntimeException('O aceite precisa de uma transação própria.');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{16,128}$/D', $key) || !preg_match('/^[a-f0-9]{64}$/D', $challenge)) {
            throw new \DomainException('Confirmação inválida. Abra novamente o documento.');
        }
        $doc = self::get($documentId);
        self::assertActiveUser();
        $userId = Access::userId();
        Store::transaction(function () use ($doc, $documentId, $challenge, $consent, $key, $userId): void {
            $booking = Store::lock('bookings', (int) $doc['bookings_id']);
            Access::assertBooking($booking, 'read');
            $locked = Store::lock('agreements', $documentId);
            $prior = Store::find('acceptances', ['agreements_id' => $documentId, 'users_id' => $userId], ['LIMIT' => 1]);
            if ($prior !== []) {
                // The same authenticated signer cannot create another acceptance, even across retries.
                return;
            }
            self::assertSignable($locked, $booking);
            $rows = Store::find('signing_challenges', ['challenge_hash' => hash('sha256', $challenge)], ['LIMIT' => 1]);
            if ($rows === []) {
                throw new \DomainException('Confirmação inexistente ou expirada.');
            }
            $c = Store::lock('signing_challenges', (int) $rows[0]['id']);
            $expectedConsent = self::consent($locked, $userId);
            self::validateChallenge($c, $locked, $userId, self::sessionHash(), $consent, Store::now());
            if (!hash_equals(hash('sha256', $expectedConsent), $consent)) {
                throw new \DomainException('A declaração de aceite mudou. Reabra o documento.');
            }
            $served = $_SESSION['reservaplus_document_served'][$documentId] ?? [];
            if ((int) ($served['users_id'] ?? 0) !== $userId || (int) ($served['at'] ?? 0) < time() - 1800
                || !hash_equals((string) ($served['hash'] ?? ''), (string) $locked['source_sha256'])) {
                throw new \DomainException('Abra o PDF original desta versão antes de confirmar o aceite.');
            }
            self::readVerified((string) $locked['source_path'], (string) $locked['source_sha256']);
            Store::insert('acceptances', [
                'agreements_id' => $documentId, 'users_id' => $userId,
                'roles_json' => Store::json(self::roles($locked, $userId)),
                'consent_text' => $expectedConsent, 'consent_sha256' => hash('sha256', $expectedConsent),
                'source_sha256' => $locked['source_sha256'], 'booking_revision' => (int) $locked['revision'],
                'auth_method' => 'glpi_authenticated_explicit_consent_v1',
                'identity_json' => Store::json(self::identity($userId)),
                'accepted_at' => Store::now(), 'event_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'operation_key' => $key,
            ]);
            Store::update('signing_challenges', (int) $c['id'], ['consumed_at' => Store::now()]);
            $status = self::allAccepted($locked) ? 'accepted' : 'partially_signed';
            Store::update('agreements', $documentId, ['status' => $status, 'date_mod' => Store::now(), 'last_error' => null]);
            Store::event((int) $locked['bookings_id'], 'document.accepted', [
                'agreements_id' => $documentId, 'users_id' => $userId, 'purpose' => $locked['purpose'],
                'source_sha256' => $locked['source_sha256'], 'consent_sha256' => hash('sha256', $expectedConsent),
            ]);
        });
        self::finalize($documentId);
        return self::get($documentId);
    }

    /** Pure validator shared with regression tests. Times use the GLPI application timezone. */
    public static function validateChallenge(array $c, array $doc, int $userId, string $sessionHash, string $consentHash, string $now): void
    {
        if ((int) ($c['agreements_id'] ?? 0) !== (int) $doc['id'] || (int) ($c['users_id'] ?? 0) !== $userId
            || !empty($c['consumed_at']) || (string) ($c['expires_at'] ?? '') <= $now
            || !hash_equals((string) ($c['session_hash'] ?? ''), $sessionHash)
            || !hash_equals((string) ($c['source_sha256'] ?? ''), (string) ($doc['source_sha256'] ?? ''))
            || (int) ($c['revision'] ?? 0) !== (int) ($doc['revision'] ?? -1)
            || !hash_equals((string) ($c['consent_sha256'] ?? ''), $consentHash)) {
            throw new \DomainException('A confirmação não corresponde ao usuário, sessão ou versão atual, ou expirou.');
        }
    }

    public static function isSatisfied(int $bookingId, string $purpose): bool
    {
        self::assertPurpose($purpose);
        $booking = Store::row('bookings', $bookingId);
        foreach (Store::find('agreements', ['bookings_id' => $bookingId, 'purpose' => $purpose, 'status' => 'complete']) as $doc) {
            if (self::current($doc, $booking) && self::allAccepted($doc)) {
                try {
                    self::readVerified((string) $doc['source_path'], (string) $doc['source_sha256']);
                    self::readVerified((string) $doc['final_path'], (string) $doc['final_sha256']);
                    return true;
                } catch (\Throwable) {
                    return false;
                }
            }
        }
        return false;
    }

    public static function finalize(int $documentId): void
    {
        if (Store::inTransaction()) {
            throw new RuntimeException('Finalize documentos após o commit da reserva.');
        }
        $doc = self::get($documentId);
        if (!self::allAccepted($doc)) {
            return;
        }
        if ($doc['status'] === 'complete') {
            self::attachFinal($documentId);
            return;
        }
        $finalName = $evidenceName = null;
        try {
            self::readVerified((string) $doc['source_path'], (string) $doc['source_sha256']);
            $snapshot = Store::decode($doc['snapshot_json'] ?? null);
            $snapshot['source_sha256'] = $doc['source_sha256'];
            $acceptances = self::acceptances($documentId);
            $pdf = PdfRenderer::render($snapshot, $acceptances);
            // Unique staging names make parallel finalizers harmless; the row lock publishes one pair.
            $nonce = bin2hex(random_bytes(16));
            $finalName = self::writeFile($nonce . '-final.pdf', $pdf);
            $manifest = [
                'version' => 1, 'agreements_id' => $documentId, 'bookings_id' => (int) $doc['bookings_id'],
                'purpose' => $doc['purpose'], 'scope_hash' => $snapshot['scope_hash'],
                'verification_token' => $doc['verification_token'],
                'source_sha256' => $doc['source_sha256'], 'final_sha256' => hash('sha256', $pdf),
                'acceptances' => $acceptances, 'finalized_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'method' => 'glpi_authenticated_explicit_consent_v1', 'certificate_signature' => false,
            ];
            $manifest['server_integrity_hmac'] = hash_hmac('sha256', self::canonical($manifest), self::integrityKey());
            $evidence = Store::json($manifest);
            $evidenceName = self::writeFile($nonce . '-evidence.json', $evidence);
            $published = Store::transaction(function () use ($documentId, $doc, $finalName, $pdf, $evidenceName, $evidence): bool {
                Store::lock('bookings', (int) $doc['bookings_id']);
                $locked = Store::lock('agreements', $documentId);
                if ($locked['status'] === 'complete') {
                    return false;
                }
                if (!self::allAccepted($locked)) {
                    throw new RuntimeException('O conjunto de aceites está incompleto.');
                }
                Store::update('agreements', $documentId, [
                    'final_path' => $finalName, 'final_sha256' => hash('sha256', $pdf),
                    'evidence_path' => $evidenceName, 'evidence_sha256' => hash('sha256', $evidence),
                    'status' => 'complete', 'last_error' => null, 'completed_at' => Store::now(), 'date_mod' => Store::now(),
                ]);
                $booking = Store::row('bookings', (int) $doc['bookings_id']);
                if ($booking['status'] === 'approved' && !empty($booking['hold_expires_at'])
                    && BookingService::signaturesSatisfied((int) $booking['id'])) {
                    Store::update('bookings', (int) $booking['id'], ['hold_expires_at' => null]);
                }
                Store::event((int) $doc['bookings_id'], 'document.finalized', ['agreements_id' => $documentId, 'final_sha256' => hash('sha256', $pdf)]);
                return true;
            });
            if (!$published) {
                @unlink(self::path($finalName));
                @unlink(self::path($evidenceName));
            }
            self::attachFinal($documentId);
        } catch (\Throwable $e) {
            // Consent and physical events are already committed; failure is recoverable without re-signing.
            Store::transaction(function () use ($documentId, $doc, $e): void {
                Store::lock('bookings', (int) $doc['bookings_id']);
                $locked = Store::lock('agreements', $documentId);
                if ($locked['status'] !== 'complete') {
                    Store::update('agreements', $documentId, ['status' => 'artifact_error', 'last_error' => mb_substr($e->getMessage(), 0, 1000), 'date_mod' => Store::now()]);
                }
            });
            // A failed render/seal must not accumulate unpublished files across retries.
            // Never remove a path already committed by this or another finalizer.
            $published = Store::row('agreements', $documentId);
            foreach (['final_path' => $finalName, 'evidence_path' => $evidenceName] as $field => $name) {
                if (is_string($name) && $name !== ($published[$field] ?? null)) {
                    @unlink(self::path($name));
                }
            }
        }
    }

    public static function artifact(int $id, string $kind): array
    {
        $doc = self::get($id);
        if (!in_array($kind, ['source', 'final', 'evidence'], true)) {
            throw new \DomainException('Tipo de documento inválido.');
        }
        if ($kind === 'evidence') {
            Access::assertBooking(Store::row('bookings', (int) $doc['bookings_id']), 'audit');
        }
        $name = (string) ($doc[$kind . '_path'] ?? '');
        $hash = (string) ($doc[$kind . '_sha256'] ?? '');
        $bytes = self::readVerified($name, $hash);
        if ($kind === 'source') {
            $_SESSION['reservaplus_document_served'][$id] = ['users_id' => Access::userId(), 'hash' => $hash, 'at' => time()];
        }
        return ['bytes' => $bytes, 'mime' => $kind === 'evidence' ? 'application/json' : 'application/pdf',
            'filename' => 'reservaplus-' . $id . '-' . $kind . ($kind === 'evidence' ? '.json' : '.pdf'), 'sha256' => $hash];
    }

    public static function verify(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \DomainException('Referência de verificação inválida.');
        }
        $rows = Store::find('agreements', ['verification_token' => $token], ['LIMIT' => 1]);
        if ($rows === []) {
            throw new \DomainException('Documento não encontrado.');
        }
        $doc = self::get((int) $rows[0]['id']);
        self::readVerified((string) $doc['source_path'], (string) $doc['source_sha256']);
        $valid = true;
        if ($doc['status'] === 'complete') {
            self::readVerified((string) $doc['final_path'], (string) $doc['final_sha256']);
            $evidence = json_decode(self::readVerified((string) $doc['evidence_path'], (string) $doc['evidence_sha256']), true, 64, JSON_THROW_ON_ERROR);
            $seal = (string) ($evidence['server_integrity_hmac'] ?? '');
            unset($evidence['server_integrity_hmac']);
            $valid = hash_equals($seal, hash_hmac('sha256', self::canonical($evidence), self::integrityKey(false)));
            $valid = $valid && hash_equals((string) ($evidence['source_sha256'] ?? ''), (string) $doc['source_sha256'])
                && hash_equals((string) ($evidence['final_sha256'] ?? ''), (string) $doc['final_sha256']);
        }
        return ['id' => (int) $doc['id'], 'bookings_id' => (int) $doc['bookings_id'], 'purpose' => $doc['purpose'],
            'title' => (string) ($doc['snapshot']['title'] ?? self::title((string) $doc['purpose'])),
            'status' => $doc['status'], 'is_current' => $doc['is_current'], 'integrity_valid' => $valid,
            'source_sha256' => $doc['source_sha256'], 'final_sha256' => $doc['final_sha256'] ?? '',
            'completed_at' => $doc['completed_at'] ?? null, 'acceptances' => array_map(static fn (array $a): array => [
                'users_id' => $a['users_id'], 'name' => $a['identity']['name'] ?? '', 'roles' => $a['roles'], 'event_utc' => $a['event_utc'],
            ], $doc['acceptances'])];
    }

    public static function title(string $purpose, bool $roomsOnly = false): string
    {
        if ($roomsOnly) {
            return match ($purpose) {
                'request' => 'Termo de solicitação e uso de sala',
                'handover' => 'Termo de autorização e responsabilidade de uso da sala',
                'return_inspection' => 'Termo de encerramento e conferência da sala',
                default => self::title($purpose),
            };
        }
        return match ($purpose) {
            'request' => 'Termo de solicitação e responsabilidade',
            'handover' => 'Termo de responsabilidade para retirada',
            'return_receipt' => 'Termo de recebimento para conferência',
            'return_inspection' => 'Termo de devolução e conferência',
            default => throw new \DomainException('Finalidade de documento inválida.'),
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'prepared' => 'Aguardando aceites', 'partially_signed' => 'Aceites parcialmente registrados',
            'accepted' => 'Aceites registrados; arquivo em preparação', 'complete' => 'Documento concluído',
            'artifact_error' => 'Aceites preservados; publicação pendente', 'superseded' => 'Versão substituída',
            default => $status,
        };
    }

    public static function roleLabel(string $role): string
    {
        return match ($role) {
            'borrower' => 'Beneficiário', 'receiver' => 'Recebedor', 'inspector' => 'Inspetor', default => $role,
        };
    }

    public static function scopeHash(array $scope): string
    {
        return hash('sha256', self::canonical($scope));
    }

    public static function canonical(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v, SORT_STRING);
            }
            foreach ($v as $k => $child) {
                $v[$k] = $sort($child);
            }
            return $v;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function scope(array $booking, string $purpose): array
    {
        $borrower = Store::decode($booking['borrower_snapshot_json'] ?? null);
        if (empty($borrower['name'])) {
            // The identity is the selected borrower, never the preparing technician.
            $borrower['name'] = self::identity((int) $booking['users_id_borrower'])['name'];
        }
        $scope = ['bookings_id' => (int) $booking['id'], 'users_id_borrower' => (int) $booking['users_id_borrower'],
            'scope_revision' => (int) ($booking['scope_revision'] ?? 1), 'begin' => (string) $booking['begin'], 'end' => (string) $booking['end'],
            'purpose' => $purpose, 'purpose_text' => (string) ($booking['purpose'] ?? ''), 'people_count' => (int) ($booking['people_count'] ?? 1), 'borrower' => $borrower, 'lines' => []];
        foreach (Store::find('bookinglines', ['bookings_id' => (int) $booking['id']], ['ORDER' => 'id ASC']) as $line) {
            $snapshot = Store::decode($line['snapshot_json'] ?? null);
            $resource = (array) ($snapshot['resource'] ?? $snapshot);
            $allowed = array_fill_keys(['id', 'name', 'kind', 'inventory_number', 'serial', 'manufacturer', 'model', 'sectors_id',
                'sector_name', '_sector_name', 'custodian_users_id', 'custodian_name', '_custodian_name', '_location_name', '_ticket_group_name',
                'building', 'floor', 'room_number', 'wing', 'directions', 'locations_id', 'capacity', 'area_m2', 'features', 'address'], true);
            $item = ['lines_id' => (int) $line['id'], 'resources_id' => (int) $line['resources_id'], 'resource' => array_intersect_key($resource, $allowed)];
            if (in_array($purpose, ['request', 'handover'], true)) {
                $accessories = is_array($resource['accessories'] ?? null) ? $resource['accessories'] : Store::decode($resource['accessories_json'] ?? null);
                $item['accessories'] = array_map(static fn (array $a): array => ['name' => (string) ($a['name'] ?? ''), 'quantity' => (int) ($a['quantity'] ?? $a['issued'] ?? 1)], $accessories);
            } else {
                if (($purpose === 'return_receipt' && empty($line['received_at']))
                    || ($purpose === 'return_inspection' && empty($line['inspection_at']))) {
                    continue;
                }
                $item['received_at'] = $line['received_at'] ?? null;
                $item['accessories'] = Store::decode($line['accessories_json'] ?? null);
                if ($purpose === 'return_inspection') {
                    if (empty($line['inspection_at'])) {
                        continue;
                    }
                    $item['inspection_at'] = $line['inspection_at'];
                    $item['inspector_users_id'] = (int) ($line['inspector_users_id'] ?? 0);
                    $item['inspection'] = Store::decode($line['inspection_json'] ?? null);
                    $item['state'] = (string) $line['state'];
                }
            }
            $scope['lines'][] = $item;
        }
        return $scope;
    }

    private static function signers(array $booking, string $purpose, array $scope): array
    {
        $roles = [];
        $add = static function (int $id, string $role) use (&$roles): void {
            if ($id > 0) {
                $roles[$id] ??= [];
                if (!in_array($role, $roles[$id], true)) {
                    $roles[$id][] = $role;
                }
            }
        };
        $borrowerRequired = in_array($purpose, ['request', 'handover'], true);
        foreach (Store::find('bookinglines', ['bookings_id' => (int) $booking['id']]) as $line) {
            $snap = Store::decode($line['snapshot_json'] ?? null);
            $resource = (array) ($snap['resource'] ?? $snap);
            $policy = is_array($resource['policy'] ?? null) ? $resource['policy'] : Store::decode($resource['policy_json'] ?? null);
            $borrowerRequired = $borrowerRequired || !empty($policy['return_signature_required']);
        }
        if ($borrowerRequired) {
            $add((int) $booking['users_id_borrower'], 'borrower');
        }
        if ($purpose === 'return_inspection') {
            foreach ($scope['lines'] as $line) {
                $add((int) ($line['inspector_users_id'] ?? 0), 'inspector');
            }
        } elseif ($purpose === 'return_receipt') {
            foreach (Store::find('bookingevents', ['bookings_id' => (int) $booking['id']], ['ORDER' => 'id ASC']) as $event) {
                if (in_array((string) ($event['type'] ?? ''), ['receive', 'received', 'booking.received', 'line.received'], true)) {
                    $add((int) ($event['users_id_actor'] ?? 0), 'receiver');
                }
            }
        }
        $staff = array_filter($roles, static fn (array $v): bool => array_intersect($v, ['receiver', 'inspector']) !== []);
        if (str_starts_with($purpose, 'return_') && $staff === []) {
            throw new \DomainException('O registro de devolução precisa identificar o recebedor/inspetor real antes da emissão.');
        }
        ksort($roles, SORT_NUMERIC);
        $signers = [];
        foreach ($roles as $id => $r) {
            $signers[] = ['users_id' => (int) $id, 'roles' => $r, 'name' => self::identity((int) $id)['name']];
        }
        return $signers;
    }

    private static function assertPreparation(array $booking, string $purpose, array $scope): void
    {
        self::assertHold($booking);
        if (in_array((string) $booking['status'], ['cancelled', 'rejected'], true)) {
            throw new \DomainException('Esta reserva não aceita novos documentos operacionais.');
        }
        if ($scope['lines'] === []) {
            throw new \DomainException('Ainda não há fatos registrados para esta finalidade de documento.');
        }
        if ($purpose === 'handover' && !in_array($booking['status'], ['approved', 'ready', 'issued', 'returning', 'completed'], true)) {
            throw new \DomainException('Prepare o termo de retirada após a aprovação.');
        }
        if (!in_array(Access::userId(), [(int) $booking['users_id_requester'], (int) $booking['users_id_borrower']], true)
            && !Access::booking($booking, 'issue') && !Access::booking($booking, 'receive')
            && !Access::booking($booking, 'inspect') && !Access::booking($booking, 'approve') && !Access::right('admin')) {
            throw new \DomainException('Sem permissão para emitir este documento.');
        }
    }

    private static function current(array $doc, array $booking): bool
    {
        try {
            $snapshot = Store::decode($doc['snapshot_json'] ?? null);
            return hash_equals((string) ($snapshot['scope_hash'] ?? ''), self::scopeHash(self::scope($booking, (string) $doc['purpose'])));
        } catch (\Throwable) {
            return false;
        }
    }

    private static function roles(array $doc, int $userId): array
    {
        foreach (Store::decode($doc['signers_json'] ?? null) as $signer) {
            if ((int) ($signer['users_id'] ?? 0) === $userId) {
                return (array) ($signer['roles'] ?? []);
            }
        }
        return [];
    }

    private static function assertSignable(array $doc, ?array $booking = null): void
    {
        self::assertActiveUser();
        $booking ??= Store::row('bookings', (int) $doc['bookings_id']);
        Access::assertBooking($booking, 'read');
        self::assertHold($booking);
        if (self::roles($doc, Access::userId()) === [] || !self::current($doc, $booking)
            || in_array((string) $booking['status'], ['cancelled', 'rejected'], true)) {
            throw new \DomainException('O documento não está vigente ou este usuário não é um signatário autorizado.');
        }
        foreach (self::roles($doc, Access::userId()) as $role) {
            if ($role === 'receiver' && !Access::booking($booking, 'receive') && !Access::right('admin')) {
                throw new \DomainException('A permissão atual de recebimento é necessária para atestar o registro.');
            }
            if ($role === 'inspector' && !Access::booking($booking, 'inspect') && !Access::right('admin')) {
                throw new \DomainException('A permissão atual de inspeção é necessária para atestar o laudo.');
            }
        }
    }

    private static function allAccepted(array $doc): bool
    {
        $signers = Store::decode($doc['signers_json'] ?? null);
        if ($signers === []) {
            return false;
        }
        $accepted = [];
        foreach (Store::find('acceptances', ['agreements_id' => (int) $doc['id']]) as $a) {
            if (hash_equals((string) $a['source_sha256'], (string) $doc['source_sha256'])) {
                $accepted[(int) $a['users_id']] = true;
            }
        }
        foreach ($signers as $s) {
            if (!isset($accepted[(int) $s['users_id']])) {
                return false;
            }
        }
        return true;
    }

    private static function acceptances(int $id): array
    {
        $rows = Store::find('acceptances', ['agreements_id' => $id], ['ORDER' => 'id ASC']);
        foreach ($rows as &$a) {
            $a['identity'] = Store::decode($a['identity_json'] ?? null);
            $a['roles'] = Store::decode($a['roles_json'] ?? null);
            unset($a['operation_key']);
        }
        unset($a);
        return $rows;
    }

    private static function assertActiveUser(): void
    {
        global $DB;
        $id = Access::userId();
        $row = $id > 0 ? $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $id, 'is_active' => 1, 'is_deleted' => 0], 'LIMIT' => 1])->current() : null;
        if (!$row) {
            throw new \DomainException('É necessária uma conta GLPI ativa para assinar.');
        }
    }

    private static function identity(int $userId): array
    {
        global $DB;
        $user = $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['id' => $userId], 'LIMIT' => 1])->current();
        if (!$user) {
            throw new RuntimeException('O usuário designado não foi encontrado.');
        }
        $name = trim((string) ($user['firstname'] ?? '') . ' ' . (string) ($user['realname'] ?? ''));
        return ['users_id' => $userId, 'name' => $name !== '' ? $name : (string) $user['name'],
            'login' => (string) $user['name'], 'authentication' => 'glpi_session', 'mfa_verified' => false];
    }

    private static function sessionHash(): string
    {
        $session = session_id();
        if ($session === '') {
            throw new RuntimeException('Sessão autenticada indisponível.');
        }
        return hash('sha256', 'reservaplus-signing|' . $session . '|' . Access::userId());
    }

    private static function baseConsent(): string
    {
        return 'Li o documento original desta versão e confirmo meu aceite eletrônico, vinculado à minha conta GLPI e ao papel indicado. O sistema registra a versão, o hash, a declaração e o horário da confirmação.';
    }

    private static function statement(string $purpose, bool $roomsOnly = false): string
    {
        if ($roomsOnly) {
            return match ($purpose) {
                'request' => 'Esta solicitação descreve a sala, o período e as responsabilidades de uso propostos. Não comprova aprovação nem garante disponibilidade antes da confirmação.',
                'handover' => 'Este termo registra responsabilidade pelo uso da sala no período aprovado, observadas as condições, capacidade e orientações informadas. O encerramento e a conferência de uso são registrados separadamente; não há declaração de retirada física de equipamento.',
                'return_inspection' => 'Este termo registra a conferência de encerramento do uso da sala, seus resultados e pendências. Não representa recebimento físico de um equipamento. O aceite dá ciência aos achados, permitindo contestação no chamado.',
                default => self::statement($purpose),
            };
        }
        return match ($purpose) {
            'request' => 'Esta solicitação descreve recursos, período e responsabilidades propostos. Não comprova entrega física, aprovação ou garantia de disponibilidade. Alterações materiais exigem nova versão e os aceites aplicáveis.',
            'handover' => 'Este termo autoriza e registra responsabilidade pela retirada dos recursos aprovados. Não declara que a entrega física já ocorreu. A entrega real, seus componentes e condições são registrados pelo operador no evento de retirada da reserva.',
            'return_receipt' => 'Este recibo registra os itens e quantidades efetivamente recebidos nos horários indicados. Não certifica funcionamento, ausência de danos ou conclusão de conferência. Componentes ainda não recebidos permanecem pendentes.',
            'return_inspection' => 'Este termo registra somente os recebimentos e conferências efetivamente concluídos e seus resultados. Divergências permanecem explícitas. Ciência do laudo não representa atribuição automática de culpa ou concordância com todos os achados.',
            default => throw new \DomainException('Finalidade inválida.'),
        };
    }

    private static function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \DomainException('Finalidade de documento inválida.');
        }
    }

    private static function roomsOnly(array $scope): bool
    {
        if (empty($scope['lines'])) {
            return false;
        }
        foreach ($scope['lines'] as $line) {
            if (($line['resource']['kind'] ?? '') !== 'room') {
                return false;
            }
        }
        return true;
    }

    private static function assertHold(array $booking): void
    {
        if (($booking['status'] ?? '') === 'approved' && !empty($booking['hold_expires_at'])
            && (string) $booking['hold_expires_at'] <= Store::now()
            && !BookingService::signaturesSatisfied((int) $booking['id'])) {
            throw new \DomainException('O prazo de aceite/retirada expirou. Solicite nova confirmação da reserva.');
        }
    }

    private static function directory(): string
    {
        if (!defined('GLPI_PLUGIN_DOC_DIR')) {
            throw new RuntimeException('O diretório privado de documentos de plugins não está configurado.');
        }
        $dir = rtrim(GLPI_PLUGIN_DOC_DIR, '/\\') . '/reservaplus/agreements';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar o diretório privado de documentos.');
        }
        if (!is_file($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n", LOCK_EX);
        }
        return $dir;
    }

    private static function path(string $name): string
    {
        if (!preg_match('/^[a-f0-9]{32,64}-(source\.pdf|final\.pdf|evidence\.json)$/D', $name)) {
            throw new RuntimeException('Referência de arquivo inválida.');
        }
        return self::directory() . '/' . $name;
    }

    private static function writeFile(string $name, string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('O documento excede o limite permitido.');
        }
        $path = self::path($name);
        $handle = fopen($path, 'x+b');
        if (!$handle) {
            throw new RuntimeException('Não foi possível criar um artefato imutável.');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Falha ao gravar o artefato completo.');
            }
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            fclose($handle);
        }
        chmod($path, 0600);
        return $name;
    }

    private static function readVerified(string $name, string $sha256): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new RuntimeException('O artefato ainda não foi publicado.');
        }
        $path = self::path($name);
        if (!is_file($path) || filesize($path) > self::MAX_BYTES || is_link($path)) {
            throw new RuntimeException('Arquivo indisponível ou inválido.');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || !hash_equals($sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('A integridade do documento não confere. Interrompido o acesso ao arquivo.');
        }
        return $bytes;
    }

    private static function integrityKey(bool $create = true): string
    {
        $path = self::directory() . '/integrity-v1.key';
        if (!is_file($path) && $create) {
            if (Store::find('agreements', ['status' => 'complete'], ['LIMIT' => 1]) !== []) {
                throw new RuntimeException('A chave histórica de integridade está ausente. Restaure o backup antes de publicar novos documentos.');
            }
            $handle = @fopen($path, 'x+b');
            if ($handle) {
                try {
                    if (fwrite($handle, random_bytes(32)) !== 32 || !fflush($handle)) {
                        throw new RuntimeException('Falha ao criar a chave de integridade.');
                    }
                } finally {
                    fclose($handle);
                }
                chmod($path, 0600);
            }
        }
        $key = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('Chave de integridade indisponível. Restaure o backup; não substitua a chave histórica.');
        }
        return $key;
    }

    /** Publish only the ordinary final PDF to the ticket. Private evidence never enters Document_Item. */
    private static function attachFinal(int $id): void
    {
        $doc = self::get($id);
        if ($doc['status'] !== 'complete' || (int) ($doc['final_documents_id'] ?? 0) > 0) {
            return;
        }
        $booking = Store::row('bookings', (int) $doc['bookings_id']);
        $ticketId = (int) ($booking['tickets_id'] ?? 0);
        if ($ticketId < 1 || !defined('GLPI_UPLOAD_DIR')) {
            Store::update('agreements', $id, ['attachment_status' => 'pending', 'last_error' => 'PDF preservado; vinculação ao chamado pendente.']);
            return;
        }
        $tempPath = null;
        try {
            $bytes = self::readVerified((string) $doc['final_path'], (string) $doc['final_sha256']);
            $filename = bin2hex(random_bytes(16)) . '_ReservaPlus_' . $id . '.pdf';
            $tempPath = rtrim(GLPI_UPLOAD_DIR, '/\\') . '/' . $filename;
            if (file_put_contents($tempPath, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Falha ao preparar anexo nativo.');
            }
            chmod($tempPath, 0600);
            // Serializes retries; native attachment work is short and contains no PDF rendering.
            Store::transaction(function () use ($id, $doc, $booking, $ticketId, $filename): void {
                global $DB;
                Store::lock('bookings', (int) $doc['bookings_id']);
                $locked = Store::lock('agreements', $id);
                if ((int) ($locked['final_documents_id'] ?? 0) > 0) {
                    return;
                }
                $ticket = new \Ticket();
                if (!$ticket->getFromDB($ticketId) || (int) $ticket->fields['entities_id'] !== (int) $booking['entities_id']) {
                    throw new RuntimeException('O chamado vinculado não corresponde à entidade da reserva.');
                }
                $old = $GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK'] ?? false;
                $GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK'] = true;
                try {
                    $native = new \Document();
                    $nativeId = $native->add(['name' => 'ReservaPlus #' . (int) $booking['id'] . ' - ' . (string) ($doc['snapshot']['title'] ?? self::title((string) $doc['purpose'])),
                        'entities_id' => (int) $booking['entities_id'], 'is_recursive' => 0,
                        'itemtype' => \Ticket::class, 'items_id' => $ticketId, 'upload_file' => $filename, '_only_if_upload_succeed' => 1]);
                } finally {
                    $GLOBALS['GLPICHAT_SUPPRESS_DOCUMENT_HOOK'] = $old;
                }
                if (!$nativeId) {
                    throw new RuntimeException('O GLPI recusou a criação do anexo.');
                }
                $relation = $DB->request(['FROM' => 'glpi_documents_items', 'WHERE' => ['documents_id' => (int) $nativeId, 'itemtype' => \Ticket::class, 'items_id' => $ticketId], 'LIMIT' => 1])->current();
                if (!$relation) {
                    throw new RuntimeException('O GLPI não confirmou a associação do documento ao chamado.');
                }
                Store::update('agreements', $id, ['final_documents_id' => (int) $nativeId, 'attachment_status' => 'attached', 'last_error' => null]);
            });
        } catch (\Throwable $e) {
            Store::update('agreements', $id, ['attachment_status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
