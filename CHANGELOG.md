# Histórico de alterações

## Em desenvolvimento — após 0.7.0-rc3 (sem nova versão instalada)

- Assinatura simplificada: apenas **um botão "Assinar termo" por conta GLPI e documento**. Se a mesma pessoa é beneficiário e recebedor/inspetor, uma confirmação registra o papel autorizado do perfil utilizado; não solicita segundo aceite para a mesma pessoa. Recebimento e conferência técnicos continuam sendo operações independentes e auditadas.
- O PDF de comprovante registra o papel efetivamente utilizado na assinatura; aceites históricos permanecem preservados e não são reescritos.

- Interface simplificada (solicitante): apresenta apenas o próprio aceite do beneficiário; não mostra ações ou avisos de assinatura do inspetor e oculta identificação técnica do PDF. A assinatura técnica continua obrigatória no backend quando prevista.
- Mensagem amigável de indisponibilidade ao solicitante se a migração obrigatória do esquema de aceites ainda não foi aplicada.

- **Aceites por papel:** a estrutura admite comprovantes por papel, mas o fluxo atual solicita **um aceite por usuário e documento**; o papel efetivo é escolhido conforme a interface e as permissões.
- Migração aditiva e controlada: coluna `role` na tabela de aceites e índice único por documento, usuário e papel. Aceites antigos preservados como `legacy`, mantendo os papéis previamente confirmados.
- O desafio de assinatura e o texto de aceite são específicos por papel; o PDF e as evidências registram cada aceite isoladamente. Um aceite de beneficiário não completa a assinatura de inspeção.
- **Atenção:** executar a migração `tools/migrate_role_acceptances.php --apply` após backup e antes de habilitar o novo código em homologação. Sem ela, os novos aceites são bloqueados de forma explícita.

- Retirada antecipada em até 15 minutos, com reserva aprovada, termo válido e verificação de conflitos no intervalo efetivo; o horário real é registrado sem alterar o período aprovado.
- Alteração que não modifica as datas é ignorada sem recriar aprovações ou invalidar termos. Mudanças efetivas de período continuam exigindo novo escopo e assinatura, preservando histórico.

- Preparação automática pós-transação dos termos aplicáveis na solicitação, após aprovação e na devolução/conferência. Reusa a geração idempotente e o versionamento existentes, sem remover verificações de assinatura.
- Destaque **Ler e assinar termo** nas reservas do solicitante, na listagem e no detalhe/aba do chamado. Documento manual permanece como contingência para falhas e reservas antigas.
- PDF inclui **Matrícula** com o login da conta GLPI do beneficiário, congelado na preparação do documento; sem alterar hashes de documentos previamente emitidos.
- Falha de preparação automática registra pendência, mas não desfaz a operação já confirmada; verificar logs e regularizar antes da entrega.


- Controles de aprovação e rejeição nas listas de solicitações, no painel operacional e no detalhe da reserva.
- Correção da detecção das aprovações pendentes nas consultas de listagem; rechecagem de direitos e alvos elegíveis.
- Ações rápidas adicionais Alterar período e Cancelar conforme perfil, situação e regras existentes; retorno à lista após operação.
- Interface detalhada com botões maiores e cores funcionais. Botões menores nas filas e tabelas.
- Interface do solicitante com atalhos Acompanhar, Termos e assinaturas, Reservar novamente e Cancelar quando permitido.
- Padronização de cores e estados de foco nos atalhos do solicitante.
- CI para sintaxe PHP 8.2/8.3 e testes independentes. Esses testes não certificam integração no GLPI real.
- Documentação em português para instalação, perfis, ações e homologação.

**Estado:** desenvolvimento em homologação; versão declarada em setup.php continua 0.7.0-rc3. A melhoria de assinaturas por papel exige a migração de banco descrita em `docs/INSTALACAO.md`.

# Changes

## 0.7.0-rc3

- Add entity-specific default approval groups by resource type and custom per-resource pools of multiple GLPI groups and/or users. Custom pools replace defaults; existing single-group resources retain their previous authority until explicitly switched.
- Freeze resolved approval targets into submitted requests. Approval checks current actor/group/entity rights against that preserved roster, including native request/incident validation permissions.
- Support different per-resource approval pools within a shared responsible ticket group. Every required resource must be approved; a partial approver cannot decide or reschedule other resources without authorization.
- Track new pending alternative candidates in Reserva Plus. Record one native validation for the actual decision-maker, without fabricating votes for the other candidates. Add configured approvers as native observers through native GLPI objects, preserving assignments and preventing incompatible private-resource combinations.
- Expose the existing authorized workflow actions directly inside the native ticket's Reserva Plus tab. Bind return navigation to verified ticket/booking context, including secure document review and verification.
- Validate selector preselection before native label lookup, preserving rejected inputs without exposing foreign identities. Veto conflicting native ticket rules before they close or relocate an active reservation, and verify native write postconditions transactionally.
- Preserve the rc2 resource registration, category browsing, multi-selection, calendar and dashboard layouts. Add native/browser regression evidence for the new approval and ticket-tab behavior.

## 0.7.0-rc2

- Restore the original shared visual theme and the role-aware dashboard as the main entry. Technician queues include approval, issue, return/accessory delays, inspection, signatures and integration recovery; requester home contains category discovery and personal actions.
- Replace search-first intake with category/resource cards, a persistent multi-resource selection, duration presets, contextual room filters, usage details and a final review. Selections survive category/page changes; calendar/resource shortcuts and repeat requests enter the same flow. JavaScript-disabled controls remain functional.
- Bind final submission to the exact displayed review and retain a submitted draft receipt to recover retries without another booking or ticket.
- Redistribute resource configuration into type choice and four sections, hiding irrelevant room/equipment fields. Add structured accessory rows, draft saving, input preservation and readable durations. Make sectors list-first.
- Add explicit incorporation of existing GLPI reservable items as drafts with preserved native identity and requester restrictions. Refuse current/future legacy reservations and roll back failed links.
- Rebuild calendar month/week/day navigation, filters, selected-day details and booking shortcuts. Include authorized legacy occupancy and anonymous busy periods, without truncating the month at 100 bookings. Crowded days offer explicit detail links.
- Make request lists resource-oriented and searchable by resource, location, borrower, purpose and ticket. Match dashboard queue counts to their authorized list destinations.
- Show document preparation actions according to the current workflow stage, separate current documents from history, and preserve the original PDF/acceptance controls.
- Fix GLPI 11 redirect exceptions being shown as errors after successful resource, sector and acceptance actions. Fix multiple-group dropdown initialization and empty placeholder handling so saved restrictions survive editing.
- Use the shared URL builder for document/verification links under GLPI's scoped route loader; return from a document to its existing booking detail instead of starting another draft.
- Add task-based browser validation, new standalone tests and real GLPI enrollment scenarios. No production deployment was performed.

## 0.7.0-rc1

- Add plugin-owned resource/sector registries and separate custody, assignment, approval and organizational responsibilities.
- Add ticket-backed reservation aggregates, native group validation, service catalog offerings, scoped calendar, inventory linking and explicit equipment export.
- Add transactional allocation, optimistic revision checks, idempotent commands, native mutation guards and durable post-commit ticket integration.
- Add issue, receipt, counted accessory reconciliation, inspection, quarantine/maintenance, reinspection and documented completion.
- Add internal electronic acceptance, immutable version snapshots, original/final PDF hashes, protected evidence, integrity verification and native final-document attachments.
- Preserve legacy data and existing profile grants/denials on upgrade. Replace profile-name-based automatic grants with explicit configuration.
- Harden legacy item/entity scope, calendar privacy, independent cancellation/deletion permissions, target-user selectors and native write failure handling. Remove direct native-table write fallbacks.
- Register unsigned-hold expiry and integration retry automatic actions.
- Add standalone tests, guarded real-GLPI integration tests and release/setup documentation.
- Raise supported minimum to GLPI 11.0.7 for the verified integration surface. New workflow text is primarily Portuguese.

This candidate does not implement every longer-term item in the preceding project assessment. The README lists explicit release boundaries. No production deployment was performed.
