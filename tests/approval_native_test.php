<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use GlpiPlugin\Reservaplus\{Access, BookingService, Store, TicketBridge};

function approval_resource(array $groups, array $users = [], array $requesters = [], bool $required = true): array
{
    return ['kind' => 'equipment', 'entities_id' => 1,
        'policy_json' => Store::json(['approval_required' => $required, 'approval_mode' => 'custom',
            'approver_groups_ids' => $groups, 'approver_users_ids' => $users, 'requester_groups_ids' => $requesters]),
        '_approval_policy' => ['mode' => 'custom', 'source' => 'resource', 'revision' => 1, 'groups_ids' => $groups, 'users_ids' => $users]];
}

rp_test('public resources can use different approval pools without sharing or fabricating targets', static function (): void {
    $a = approval_resource([3, 5], [7]); $b = approval_resource([11], [13]);
    BookingService::assertApprovalDisclosure([$a, $b]);
    rp_same([['itemtype' => 'User', 'id' => 7], ['itemtype' => 'Group', 'id' => 3], ['itemtype' => 'Group', 'id' => 5]], TicketBridge::approvalTargets($a));
});

rp_test('private resources reject external approvers before a shared ticket can expose their attachments', static function (): void {
    $private = approval_resource([3], [], [8]);
    BookingService::assertApprovalDisclosure([$private, approval_resource([3])]);
    rp_throws(static fn() => BookingService::assertApprovalDisclosure([$private, approval_resource([9])]), DomainException::class, 'restrito');
    // Explicit requester-group access is sufficient for the same Group target.
    BookingService::assertApprovalDisclosure([approval_resource([3], [], [9]), approval_resource([9])]);
});

rp_test('personal approval targets need explicit private-resource authorization, not assumed group membership', static function (): void {
    rp_throws(static fn() => BookingService::assertApprovalDisclosure([approval_resource([3], [], [9]), approval_resource([], [7])]), DomainException::class);
    BookingService::assertApprovalDisclosure([approval_resource([3], [7], [9]), approval_resource([], [7])]);
});

rp_test('approval-free private lines cannot silently inherit disclosure from another resource', static function (): void {
    rp_throws(static fn() => BookingService::assertApprovalDisclosure([approval_resource([], [], [8], false), approval_resource([3])]), DomainException::class);
    BookingService::assertApprovalDisclosure([approval_resource([3], [], [8], false), approval_resource([3])]);
});

if (!class_exists('Session', false)) {
    class Session
    {
        public static function getLoginUserID(): int { return 7; }
        public static function haveRight(string $name, int $mask): bool { return false; }
    }
}
rp_test('a target match requires the actual person or current scoped group, not another candidate', static function (): void {
    $_SESSION = ['glpigroups' => [3]];
    rp_assert(TicketBridge::targetMatchesActor(['target_itemtype' => 'User', 'target_id' => 7]));
    rp_assert(TicketBridge::targetMatchesActor(['target_itemtype' => 'Group', 'target_id' => 3]));
    rp_assert(!TicketBridge::targetMatchesActor(['target_itemtype' => 'User', 'target_id' => 8]));
    rp_assert(!TicketBridge::targetMatchesActor(['target_itemtype' => 'Group', 'target_id' => 9]));
    rp_assert(!TicketBridge::targetMatchesActor(['target_itemtype' => 'Other', 'target_id' => 7]));
    $_SESSION['glpigroups'] = [];
    rp_assert(!TicketBridge::targetMatchesActor(['target_itemtype' => 'Group', 'target_id' => 3]));
});
