# Implementation boundaries

## Ownership

`Resource` is the stable plugin identity. A resource may optionally reference a GLPI asset (`native_itemtype`, `native_items_id`) and a `ReservationItem`. A calendar identity does not itself create an inventory asset. `Sector` is an organizational unit within a GLPI entity; it is distinct from a GLPI Group, Location or Entity.

`BookingService` owns state transitions. A booking has one primary ticket, resource lines, per-line approval/receipt/inspection facts, versioned commands and an append-only event history. Requester, borrower, custodian, operator, receiver and inspector are separate roles. Resource configuration is snapshotted into each request; silently changing current metadata cannot rewrite a previously signed document.

`Store` centralizes plugin-table access, checked writes and transactions. Mutations lock the booking and resource rows, recheck authorization and conflicts, and use request fingerprints and revision checks. Each outer plugin transaction explicitly uses READ COMMITTED so a worker waiting for a resource lock sees the previous holder's committed allocation. The connection-wide default is unchanged. An inner failure marks the enclosing transaction for rollback even if a caller catches the exception. This implementation uses GLPI's database connection; it does not open a separate connection for ticket writes. Domain services must own their outer transaction: entry from an unrelated transaction fails closed rather than inheriting an unsafe isolation level.

## Native adapters

- `TicketBridge`: native `Ticket`, `TicketValidation`, `ITILFollowup` and notification operations. New requests create one native demand ticket; ticket-origin requests reuse the authorized ticket. Assignment/entity/state are checked after native rules run. Ticket follow-ups and approval notices are delivered from a committed outbox, with retries.
- `CalendarBridge`: conflict checking and checked native `Reservation` operations for suitable resources. Plugin lines remain the authoritative custody record. Reserved intervals use start-inclusive/end-exclusive overlap, including configured buffers. Actual equipment receipt is distinct from release after inspection.
- `WorkflowHooks`: reject unmanaged writes to managed reservations and reservation items; preserve native approval history; prevent premature ticket solution/deletion/entity moves and deletion of linked native assets. A `RULE_MATCHED` veto checks existing managed tickets before a native ticket rule can write a forbidden closure/entity/deletion change, including updates outside plugin commands. Trusted domain operations enter a private process-local depth boundary with `try/finally` cleanup; the rule veto remains active inside that boundary.
- `ServiceCatalogProvider`: implements GLPI 11's leaf provider for the native catalog and home search, returning scoped root-level offerings. It does not create a GLPI Form that redirects after creating a second ticket.
- `TicketTab`: reads through the booking service and renders shared detail/action controls, respecting both native ticket visibility and plugin scope. Ticket context is an authenticated booking/ticket identity, never an arbitrary return URL; command tokens bind it and action handlers revalidate it before mutation.

There is no unrestricted public workflow REST API. Generic native model CRUD cannot replace the domain service. Booking and Agreement generic collection discovery are disabled; access is through scoped plugin views. Native resource discovery is restricted where GLPI's generic SQL cannot enforce the plugin's requester-group policy.

## Task-oriented views

`BookingView` owns the common shell and role-scoped navigation, reusing the original `reservaplus-shell` visual tokens. `TechnicianDashboard` chooses staff or requester home from explicit capabilities. Its queue predicates are shared with `BookingQuery`; authorization is applied before counts and pagination. Preview limits do not become total counts. Overdue calculations keep outstanding accessories visible after the main equipment has been received and distinguish rooms awaiting closure from equipment awaiting return.

`RequestDraft` stores at most five two-hour, user/session-bound drafts. Browsing is not a database reservation. Each page merges only its visible checkboxes into the whole selection. `RequestIntakeView` rechecks current catalog eligibility, whole-selection availability and on-behalf eligibility. Final submission compares the hash displayed to the user against both the reviewed hash and current draft payload, then uses the existing domain command. The draft remembers the submitted booking for retry recovery. Domain validation remains authoritative. JavaScript improves feedback; ordinary CSRF-protected POST/redirect controls perform the same flow.

`ResourceAdminView` separates identity, responsibilities, rules and review. Type-dependent controls and optional sections change presentation, while `ResourceService` still validates permissions, policies, revisions and native identity. Multiple native dropdowns use GLPI 11's `value` array contract; empty native placeholders are removed before strict policy validation. Native redirect exceptions are rethrown before generic form errors are handled.

`WorkflowCalendarView` projects the requested visible interval instead of a page of bookings. It deduplicates native projections of plugin bookings, includes authorized legacy occupancy, and produces anonymous busy events where a resource is visible but its booking is not. Hidden resources and private booking metadata remain excluded. Month/week/day use the same scoped events; per-cell presentation limits link to the full selected-day details rather than dropping fetched occupancy.

`LegacyResourceCatalog` provides explicit enrollment from native `ReservationItem` identities. It checks plugin management, native read access, entity scope and legacy item-group policy. Creation and linking share a transaction; the resource starts as a draft and cannot widen legacy requester restrictions. Active/future native reservations prevent enrollment. Core native reservation writers do not participate in the enrollment lock, so this is a controlled handover that requires pausing concurrent native booking creation for the affected item. Historical native rows remain unchanged.

## Approval defaults, snapshots and native decisions

`ApprovalPolicy` owns entity/type defaults, strict target normalization and native reference validation. `approvaldefaults` stores the current group list and revision for each entity/type; `approvaldefaultchanges` records changes and the acting administrator. Updates use optimistic revisions and an entity row lock, including first creation. Submission takes the same defaults lock before resolving per-resource pools so concurrent configuration changes cannot mix revisions inside a single request.

Resource `policy_json` identifies `legacy`, `inherit` or `custom` authority. Missing mode remains legacy and resolves exactly to the old `groups_id_approve`; custom lists replace other sources. New UI resources explicitly inherit. Resolved `_approval_policy` snapshots contain mode/source/revision and group/user targets. Current defaults never replace that frozen roster during an existing request or its pre-issue date revision.

`Access::canApproveLine` combines booking/entity visibility, the plugin approval capability, current target eligibility and native request/incident validation rights. `canReschedule` requires authority over every required line, with a scoped management path for approval-free bookings. Assignment, custody and workflow administration do not bypass actual approval target membership. A partial approver can read an aggregate only with native ticket visibility and independently readable other resource lines; ordinary operations roles keep their all-lines scope.

Legacy single-group requests retain their native pending validation. New pools have one plugin `bookingapprovals` row per Group/User target with no native validation until a decision is made. Under the booking/resource locks, the first eligible actor creates and answers one native User-targeted validation. The chosen plugin row stores the real decider; remaining alternatives become `covered` without a native vote. Subsequent attempts cannot replace the decision. Revisions preserve actual native records in `bookingapprovalhistory`, emit per-target history events and reset the frozen roster for a fresh decision; ID0 is never treated as a native validation or queued for native validation notifications.

Configured pool targets become native ticket observers through `Group_Ticket`/`Ticket_User`, deduping existing participation without changing assignments. Before the ticket or observers are created, disclosure validation requires every approval target to be authorized on each requester-restricted resource (its frozen approval pool, or an explicitly allowed requester Group). Incompatible private combinations fail transactionally and must be split. This protects native ticket attachments and content as well as plugin views.

Pending-pool notifications use the committed native ticket follow-up path; native notification settings still govern delivery. Native ticket global approval describes native records and is not rewritten to represent the plugin's multi-resource state. The Reserva Plus per-resource state and mutation guards remain the operational gate. This preserves unrelated native validations and avoids fabricated votes or deleted history.

Native observer, validation, follow-up and completion operations also check the resulting ticket's identity/entity/deletion/state inside their transaction. A conflicting customer rule aborts the operation rather than being silently overwritten. Outbox failures preserve prior physical/domain facts and remain available for retry. Native selectors validate preselected identifiers before GLPI resolves labels, including failed-form redisplays, so foreign-entity group/user names are not exposed through a forged value.

## Physical state and administrative state

A successful return receipt is committed independently of PDF rendering, signatures and final ticket solution. Per-accessory received quantities are deltas and cannot exceed issued quantities. An omitted accessory is not treated as returned. Inspection records who checked what, when and with what outcome. Failed inspection keeps the resource unavailable; repair/reinspection must precede normal final completion in this candidate.

The native ticket is the communication and assignment record; its status is not used as proof of custody. A native ticket cannot be manually solved while the linked plugin workflow remains active. A technical failure during final solution does not erase previously committed physical receipt or inspection.

## Documents and acceptance

`DocumentService` builds purpose-specific scope snapshots and uses `PdfRenderer` with GLPI's bundled TCPDF. It stores protected source, final and evidence artifacts under GLPI's private plugin document directory. Acceptance binds the exact source and consent to the current user/session, designated role and scope version. A content-changing revision invalidates prior acceptance for subsequent operational gates while preserving historical artifacts.

PDF rendering occurs outside booking mutation transactions. Finalization publishes artifact paths under row locks. Rendering and native attachment failures are recoverable without signing again. The final PDF can become an ordinary native ticket Document; the private evidence bundle is never attached to the ticket. Verification requires authentication and scoped booking access, even when a document locator is known.

The HMAC integrity key is server-controlled and must be backed up. It provides tamper detection against accidental or unauthorized artifact changes within this trust model; it is not an independently trusted personal digital certificate.

## Authorization

`Access` combines explicit plugin capabilities, active GLPI entities, current resource responsibilities and requester-group policy. Current group membership is rechecked in the database against the entity/profile-scoped session list, so removing a membership cannot leave stale-session approval authority. Operational aggregate access requires authorization for every resource line; partial approval additionally follows the frozen-pool and native-ticket checks described above. Being eligible to submit a request does not grant permission to cancel another user's request.

The actual beneficiary is checked on on-behalf requests. Resource managers cannot weaken approval/signature safeguards without workflow-administration permission. Native GLPI ticket/validation/state rights remain prerequisites. CSRF is handled once by GLPI 11's request listener; workflow command tokens additionally bind session, actor and action.

## Upgrade, recovery and operational assumptions

The schema upgrade adds tables/columns without deleting legacy rows. Existing permission values are preserved. Database rows, the private agreement directory and its integrity key form one backup unit. No production rollback migration that deletes new workflow data is provided.

The supported execution model is one active GLPI plugin installation with a functioning CLI scheduler and transactional InnoDB tables. Direct SQL, other plugins that bypass GLPI object hooks, disabled plugin execution and full server compromise are outside the workflow's enforcement boundary. Validate interactions with the site's real plugins, ticket rules and authentication setup in staging.

The original `g4freports`, GLPIChat adjustments and Deiure patterns informed the design: native GLPI document/ticket operations, scoped source data, internal consent evidence and failure isolation. This release does not require those systems to be installed and does not claim complete production interoperability testing with them.
