# Approval setup and ticket actions — 0.7.0-rc3

Approval authority is separate from the team receiving the ticket, the resource's management group, the custodian and the organizational sector. Assignment tells staff who handles the request; the configured approval pool determines who may authorize the resource.

## Choose the default once, override where needed

The plugin configuration has approval defaults for **equipment**, **rooms** and **other resources** in the selected GLPI entity. Each type can list several GLPI groups. Defaults are entity-specific: a parent or sibling entity's defaults are not silently reused. Recursive native groups may be selected where GLPI's entity scope permits them.

For each resource, choose one of these policies:

| Policy | Eligible approvers |
| --- | --- |
| Inherit type defaults | Groups configured for that resource's type and entity. |
| Custom resource pool | Only the groups and/or individual people selected on this resource. This replaces the default list. |
| Preserved previous group | The resource's existing single approval group from earlier versions. Upgrading does not silently broaden it. Switch explicitly when ready. |

A resource requiring approval needs at least one valid target before it can be published or requested. An unfinished draft can be saved while responsibilities and approval defaults are still being configured. Disabling required approval remains a separate controlled policy change, not the meaning of an empty list.

Example: the technical administration groups approve equipment by default, while the facilities management groups approve rooms. A particular auditorium can use a custom pool containing its manager and an event-support group. Any eligible member of that custom pool may decide; the default room groups are not additional approvers unless explicitly selected in the custom pool.

## What a decision means

The rule is **one eligible decision per required resource**. The pool is a set of alternative authorized approvers, not a sequence requiring everyone to sign off. On a request with several resources, an approver can select only the lines they are authorized to decide. The reservation is allocated only after every required line is approved and availability is rechecked. A refusal rejects the request and requires a reason.

Being assigned the ticket, being the resource's custodian, or holding workflow administration rights does not manufacture an approval on behalf of the designated pool. An actual decision requires the plugin approval capability, the appropriate native GLPI request/incident validation right, active entity access and eligibility in that request's pool. Current group membership and active user state are checked again when acting.

Multiple approval pools can share a request when the responsible ticket group is the same. Private resources add a visibility check: every approval recipient must also be covered by each restricted resource's approval policy or, for group targets, its explicitly allowed requester groups. Incompatible combinations must use separate requests. This check happens before the native ticket or observers are committed.

## Keep existing requests stable

Submission preserves the resolved approval targets and where they came from in each request line. Changing type defaults affects later submissions; it does not replace an existing request's approvers or erase its decisions. Removing a user's current group membership still removes their ability to act as a member of that group.

Existing resource-specific policies cannot be silently changed while the resource has open commitments. Existing single-group resources and requests retain their prior approval path until a resource is explicitly configured for the new policy after its open work is resolved.

Changing a request's dates before issue invalidates the applicable approvals and signatures. The new review uses the same frozen approver roster and retains the old decision history. A person who can approve only part of a request cannot use that partial authority to reschedule the entire request.

## Work inside the ticket

Open the ticket's **Reserva Plus** tab to view the linked reservation, per-resource approval progress, documents and permitted actions. The tab reuses the plugin's controls and domain services; it is not a second workflow with separate authorization rules.

Depending on the actor, resource and current stage, the tab offers approval/refusal, cancellation, date changes before issue, issue, receipt, inspection, completion and document preparation. Requesters see their permitted actions and document links; they do not receive technician controls merely because they can view the ticket.

Physical actions remain independent of PDF finalization. Receipt records what actually returned, inspection records what was checked, and outstanding accessories remain visible. Document review and acceptance use the existing protected PDF screen with a return link to the originating ticket. Actions return to the Reserva Plus tab rather than requiring the user to navigate back through the plugin.

Return context is limited to a verified booking/ticket relationship and a native ticket the current user can read. It cannot supply an arbitrary redirect or confer authority to mutate another request. Command tokens, revision checks, CSRF, ownership and operational-group checks remain in force.

## How this maps to native GLPI approvals

A native GLPI validation targets one User or Group. Treating every alternative pool member as an independent pending validation would either leave misleading requests open or require recording decisions that those people never made. This implementation therefore distinguishes two paths:

- Existing single-group policies retain the native pending validation behavior from earlier releases.
- New inherited/custom pools keep their pending candidates in Reserva Plus. Once an eligible person decides a resource, the plugin creates and answers one native validation targeted to that actual person. The other candidates receive no fabricated native acceptance.

The configured groups and individual approvers are added as native ticket observers through GLPI's actor objects, without replacing the assigned technician or responsible group. Existing actor relationships are reused. This provides ticket context; the person's native profile/entity permissions must still permit viewing it. Group members need the corresponding GLPI group-ticket visibility. The plugin does not rewrite profile permissions to achieve that access.

Pending pool work is visible in the Reserva Plus dashboard and ticket tab. Native ticket follow-ups and the committed outbox provide the normal GLPI notification path; actual recipient delivery depends on the site's notification configuration. Pending alternatives are not entries in GLPI's separate native approval queue.

The ticket's **global approval** field describes its native validation records. It is not proof that every resource in the Reserva Plus request has been approved, issued or returned. The plugin's per-resource progress and reservation state enforce those gates, and native hooks prevent bypassing them by prematurely solving or directly mutating the linked records. Unrelated native validations are not overwritten to make the global field match the plugin.

## Staging acceptance

Configure representative equipment and room defaults, then test a custom group-only, individual-only and mixed pool. Use ordinary approver profiles, not only Super-Admin. Confirm the actual native ticket tab, group visibility and notifications; check that an assignee outside the configured pool cannot approve. Test a multi-resource request requiring different eligible actors, and verify that the first partial approval does not allocate the request.

The tested release evidence is in `VALIDATION.md`. This package does not add unanimous/sequential approval stages, delegation exceptions, cross-ticket-group coordination or active-loan extensions.
