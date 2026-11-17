# Reserva Plus 0.7.0-rc3 — base de desenvolvimento

> **Atenção:** esta ramificação contém trabalho em andamento para a próxima versão.
> A ação rápida de aprovação/rejeição na listagem foi adicionada, mas ainda não
> passou por testes de integração em um GLPI instalado. As demais melhorias da
> 0.8.0 permanecem planejadas e não devem ser tratadas como implementadas.

A GLPI resource reservation and custody workflow for equipment, rooms and other internal resources. This release extends the supplied 0.6.1 plugin with ticket-backed requests, group approvals, internal electronic acceptance and verified returns. It is a release candidate for staging validation; it has not been deployed to the production environment.

This revision adds configurable approval pools and actions inside the native ticket's **Reserva Plus** tab. It retains the rc2 usability corrections: the operational dashboard, category-first multi-resource selection, contextual resource registration and month/week/day calendar. See [APPROVALS.md](docs/APPROVALS.md) for approval setup and native GLPI boundaries, and [UX_REVIEW.md](docs/UX_REVIEW.md) for the page-by-page usability review.

## Compatibility

- GLPI **11.0.7 or later in the 11.0 branch**, below 11.1.0.
- PHP **8.2 or later**. Runtime validation used **PHP 8.3.6**, GLPI **11.0.7**, MariaDB **10.11.14** and GLPI's bundled TCPDF **6.11.2**. Other PHP/GLPI combinations have not been runtime tested.
- InnoDB plugin tables, writable GLPI private plugin document and upload directories, and GLPI automatic actions running in CLI mode.
- Portuguese workflow screens and document templates. The new workflow does not yet have complete English translations.

## What is implemented

| Area | Behavior |
| --- | --- |
| Internal resources | Choose a type, then configure identity, responsibility, reservation rules and review in separate sections. Room/equipment fields appear contextually. Optionally incorporate an existing GLPI reservable item or explicitly export supported equipment. |
| Responsibility | Separate organizational sector, ticket group, management group, approval group, custodian and default ticket assignee. The custodian is not automatically an approver. |
| Rooms | Building, floor, room number, wing, capacity, area, address, coordinates, directions and features; optional authorized GLPI Location link. |
| Request intake | Browse category/resource cards, choose one period and retain up to 32 selected resources across pages and categories. Usage details and final review are separate steps. Catalog, resource and calendar links seed this same flow; a ticket's Reserva Plus tab reuses that ticket. Viewing a form creates no booking or ticket. |
| Home and queues | Staff see scoped approvals, ready-to-issue requests, loans, overdue returns/accessories, inspections, signatures and integration failures. Requesters see category shortcuts, their active requests and signature prompts. Queue counts link to the same filtered request lists. |
| Approval | Entity-specific default groups by resource type, or a resource-specific replacement pool of groups and/or people. One eligible actor decides each required resource. Resolved approvers are frozen when the request is submitted; final allocation rechecks availability. |
| Native ticket tab | The ticket's Reserva Plus tab renders the same authorized request details and actions as the plugin. Actions return to the tab; secure PDF review/acceptance retains a return link to that ticket. |
| Calendar | Month/week/day views with date navigation, filters, selected-day details and a shortcut to reserve that period. Authorized legacy occupancy is included. Other users' occupancy is anonymous unless the booking itself is readable. Native GLPI Reservations projection remains subject to native visibility. |
| Custody | Issue conditions, physical receipt times, partial accessory receipts, inspection, quarantine/maintenance, reinspection and final completion. |
| Documents | Versioned Portuguese request, responsibility, receipt and inspection PDFs; authenticated explicit acceptance, signer roles, hashes, evidence and authorized verification. Final PDFs attach to the ticket. |
| Reliability | Transactional mutations, resource row locks, command keys, revision checks, native mutation guards, durable ticket follow-up/approval notification queue and automatic unsigned-hold expiry. |
| Existing installation | Additive database upgrade, existing reservations retained, and existing profile permissions preserved, including explicit denials. Legacy screens remain separately available. |

## Install or upgrade in staging

1. Back up the GLPI database, the existing `plugins/reservaplus` directory and GLPI's document storage. Keep the original package available.
2. Extract the release so the plugin directory is exactly **`reservaplus`** under GLPI's `plugins` directory. Replace the old code as a complete directory; do not merge unrelated runtime files into it. Do not uninstall the old version first.
3. In GLPI, open **Configuration → Plugins**, update/install Reserva Plus and activate it. The schema upgrade preserves previous records and adds workflow tables. Run the upgrade before opening the new workflow pages.
4. Open **Administration → Profiles → Reserva Plus** and assign the new workflow capabilities explicitly. Existing profile rows are never broadened by profile-name matching. Only a verified installing administrator may receive missing default rights; CLI installs without that authenticated context create missing rights with no grants.
5. Configure the current entity's default approval groups for equipment, rooms and other resources in the plugin configuration. Then open **Tools → Reserva Plus → Recursos**. Create an internal resource by type or choose **Incorporar do GLPI** for an existing reservable item. Configure responsibilities, eligible requesters and signature policy; inherit the type's approval defaults or choose a custom replacement pool, then review and publish. Existing resources retain their previous single-group policy until explicitly changed. Incorporation starts as a draft and retains native identity/requester restrictions without duplicating inventory. Items with current/future native reservations must finish that legacy cycle before incorporation. Pause simultaneous native booking creation for the affected item during enrollment. Confirm native group/user permissions before publishing.
6. In **Configuration → Automatic actions**, check `ExpireHolds` (300 seconds) and `DeliverOutbox` (60 seconds). They are registered for CLI execution. Ensure the site's normal GLPI scheduler processes CLI tasks; merely enabling the plugin does not start an operating-system scheduler.
7. Test a complete request, approval, PDF acceptance, issue, partial return, inspection and completion with real representative profiles before production rollout. Check native ticket rules, validation rights, state transitions and notification configuration.

The private agreement directory is `GLPI_PLUGIN_DOC_DIR/reservaplus/agreements`. Back up its PDFs, evidence files and **`integrity-v1.key` together with the database**. The key must survive upgrades and restoration. Use GLPI's protected document storage outside the public web root; `.htaccess` is only defense in depth and does not configure Nginx.

### Capabilities and native rights

| Plugin capability | Scope / native prerequisites |
| --- | --- |
| Read / Request | Published resources within active entities and eligible groups; native ticket creation is required to submit. Existing legacy read/create rights remain a compatibility path. |
| Manage resources | Resource management group (or ticket group fallback). Changing mandatory controls to a weaker setting requires workflow administration. |
| Approve | An explicit user or current member of a group in the request's frozen approval pool, the plugin approval capability and the native request/incident validation right. Custom targets replace type defaults. Assignment, custody and administration alone do not make someone an eligible approver. |
| Issue / Receive / Inspect | Matching operations group plus the specific capability. These are independent rights. |
| Audit | Scoped operations/ticket team; private evidence requires the booking's audit authorization. |
| On behalf of another user | Explicit capability and resource operations scope. The actual beneficiary's eligibility is checked too. |
| Administer workflow | Explicit broad plugin capability within the active GLPI entity scope. Does not grant native GLPI rights. |

Native assignment, validation, ticket visibility and state-transition rules still apply. If the preferred assignee is not eligible, new tickets use the responsible group queue. A ticket-origin request preserves the ticket's existing assignment. Creating a ticket does not silently change the asset's custodian or sector.

## Daily workflow

1. The requester chooses a category and browses visible resource cards; a name search is optional. Select several resources, change category/page as needed, set a shared period, add purpose and contextual attendance/beneficiary details, then review the complete selection. Compatible resources share one request and one primary ticket. Unavailable selections remain visible with a reason; the user can remove them or choose a suggested alternative period.
2. If required, the requester accepts the request document. Eligible approvers act directly in the ticket's Reserva Plus tab or the plugin detail. Each actor selects the resources they may decide; approval can be partial until every required resource has a decision. A refusal rejects the request. Pending requests do not guarantee an allocation; the final approval rechecks conflicts.
3. Approval allocates the period. Mandatory responsibility acceptance must be complete before issue. Unsatisfied required acceptance has a 24-hour hold after allocation; the automatic action releases an expired unsigned allocation. This is not an automatic no-show or overdue-return policy.
4. An authorized operator records the actual issue and condition. Pre-issue responsibility acceptance does not falsely attest that physical delivery has already occurred.
5. Staff record physical receipt promptly, even if PDF generation/signing is unavailable. Equipment receipt and accessory quantities are separate; missing accessories remain outstanding. A receipt can be generated before inspection and must not certify an inspection that has not happened.
6. An inspector records findings. Passing inspection makes the resource available; quarantine or maintenance prevents reuse. Repair/reinspection is required before normal completion. Rooms can be closed through an inspection of use without an equipment-style physical return.
7. The final inspection record requires the inspector's acceptance and, when configured, the borrower's acknowledgement. Completion also requires reconciled accessories and a native ticket transition that GLPI actually accepts. Physical facts remain recorded if administrative completion is blocked.

The borrower can contest findings in the ticket. Acknowledgement does not automatically assign fault for damage.

## Internal acceptance and documents

Acceptance is tied to the authenticated GLPI user, designated role, exact PDF source hash, displayed consent and document version. The original PDF must be opened before signing. A short-lived single-use challenge binds the user and session, and native GLPI CSRF protection applies to POST actions. Retries do not create another acceptance. Material changes require a new document and new applicable acceptance; previous versions remain available as history.

The final PDF contains the acceptance evidence summary. Private evidence records and the server HMAC seal support internal integrity checking. They are **not a personal certificate signature, PAdES/ICP-Brasil signature, external timestamp, MFA attestation or a guarantee against a fully compromised server**. This release uses the existing GLPI authentication session and does not integrate an external signature provider.

If rendering or attachment fails after acceptance, the receipt/acceptance is retained and the document screen provides a retry. Final PDF attachment is separate from private evidence access. Document generation uses fixed versioned templates, not executable user-provided templates.

## Release boundaries

- A multi-resource request still uses one responsible ticket group, but may have different approval pools. Any eligible pool member can decide a required resource; sequential/unanimous approval stages and coordinated child tickets are not implemented. An incomplete partial approval does not allocate the resources.
- Restricted resources cannot share a request with approval recipients who would gain unauthorized native ticket visibility. Incompatible private-resource approval pools must be split into separate requests; the backend rejects them before committing a ticket or adding observers.
- New approval pools keep pending candidates in Reserva Plus and create a native User-targeted approval only for the actual decision-maker. Native observers provide configured groups/people ticket context; native profile/entity visibility still applies. GLPI's global approval field describes its native validation records, not completion of every resource's plugin approval. The Reserva Plus state and native mutation guards enforce the reservation gate. Existing legacy single-group approval records retain their native pending behavior.
- An internal resource restricted to requester groups stays **plugin-calendar-only**, avoiding leaks through native reservation item discovery. Its UI shows the calendar mode. Linked native inventory continues to follow GLPI's own inventory visibility; the plugin cannot make an already visible native asset private.
- Native synchronization targets **Reservations**, not technician **Planning** tasks. Outlook/Google/ICS subscriptions, automatic inspection tasks and a reconciliation dashboard are not implemented.
- Date changes are supported **before issue** and invalidate applicable prior approvals/signatures. Extending an active loan is not supported in this candidate.
- Active commitments block resource responsibility/location/policy transfer, retirement and native relinking. An assisted transfer/migration workflow is not implemented.
- Native export is explicit for supported equipment types; room export, pooled interchangeable quantities, parent-child room occupancy and recurring new-workflow series are not implemented. Accessories are counted components attached to an individual resource.
- **Reservar novamente** starts a fresh request with the source's currently eligible resources and purpose. It does not copy approvals, signatures, tickets or old dates. Recurring series remain on the separately labeled legacy reservation screen; repeat is not a recurring series.
- The `approval` and `handover` acceptance settings both enforce a post-approval, pre-issue responsibility document. Neither creates a second claim of actual physical delivery.
- Employee registration number and organizational-unit text entered by the requester are stored as **declared data**. Verified HR/custom-field mapping still needs environment-specific configuration.
- New workflow policies are configured per resource. The old duration/rule/notification controls remain for legacy reservations; they are not all automatically inherited by new bookings. Shared legacy calendar blocks are checked where applicable.
- Notifications use GLPI's configured native mechanisms. No external email delivery guarantee, scheduled borrower reminders, automatic damage/maintenance child-ticket creation or no-show automation is claimed.
- Existing 0.6.1 reservations remain legacy records; they are not automatically converted into new signed custody requests. Legacy entries whose native asset/reservation item was physically purged are hidden when their access scope cannot be established. Legacy list scans and beneficiary pickers retain their existing display caps; backend beneficiary authorization is independent of the picker cap.
- Unclassified legacy assets appear in the new calendar under **Todos os tipos** until incorporated/configured. Enrollment refuses an already linked item or current/future reservation and rolls back a failed link. Native core writers do not share the enrollment row lock: avoid concurrent native reservation creation during this controlled handover.
- Automatic actions and native guards require the plugin to remain active. Deactivating it bypasses plugin enforcement. Uninstallation is blocked when new booking/document/native links exist and otherwise retains data; planned decommissioning must preserve historical access.

## Validation and development

See [UX_REVIEW.md](docs/UX_REVIEW.md) for the task and page design review, [VALIDATION.md](docs/VALIDATION.md) for tested evidence, [ARCHITECTURE.md](docs/ARCHITECTURE.md) for ownership and integration boundaries, and [CHANGELOG.md](CHANGELOG.md) for changes.

Run standalone behavior tests and PHP syntax checks:

```bash
PHP_BIN=php bash tools/run-tests.sh
```

The integration scripts write fixtures and are for a **disposable GLPI database only**. Each checks an explicit write opt-in and expected database name before writing. Do not run them against production. They need an installed/active plugin, a suitable administrator and a self-service fixture user; see each script's environment variables. `tools/verify.php` is also a write-test harness and is guarded accordingly.

Workflow assets in `css/` and `js/` have corresponding copies in `public/`; keep them synchronized for GLPI 11 routing. Do not ship test database snapshots, signing keys, generated agreements, credentials or runtime dependencies with the plugin.

## License

GPL-3.0-or-later. Original plugin by Celso, extended with Codex. Original attribution is retained; the GPL text is included in LICENSE.
