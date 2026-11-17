# Validation record — 0.7.0-rc3

Validation date: 2026-10-28. Tests used a disposable local installation, never the production GLPI database.

## Environment

| Component | Tested version |
| --- | --- |
| GLPI | 11.0.7 official release, real native classes and bundled dependencies |
| PHP | 8.3.6 |
| MariaDB | 10.11.14, InnoDB |
| TCPDF | 6.11.2 bundled with GLPI |
| Starting plugin | Supplied ReservaPlus 0.6.1 |

The original 49 extracted source files were compared byte-for-byte with the supplied ZIP and remain unchanged. Work and the upgrade test used separate copies.

## rc3 final rerun — 2026-10-28

The disposable installation was updated and activated through GLPI's official plugin console as 0.7.0-rc3. The final run repeated the complete rc2 baseline and then exercised approval defaults, resource-specific pools and actions inside the actual native ticket tab. Native permissions, tickets, validation records, observers, rules and database transactions used the real GLPI classes.

| Suite | Final rc3 result |
| --- | --- |
| PHP syntax | 101 PHP files passed. |
| JavaScript syntax | Eight files passed `node --check`, including public mirrors. |
| Standalone tests | 129 checks across 16 files passed: the 98 prior checks plus approval administration 5, native approval boundary 5, approval policy 14 and ticket context/progress 7. |
| Upgrade preservation | All 40 original profile-right rows, including explicit denials, and the original linked asset/reservation item/reservation/legacy request remained intact after the official rc3 update. |
| Legacy live verifier | 95 checks passed with rolled-back fixtures. |
| Native integration | 53 scenario groups passed: all 38 prior groups plus 15 new approval-policy integration groups. |
| HTTP and browser tasks | 23 grouped scenarios passed: the 12 rc2 browser tasks, seven native-ticket tasks, one comprehensive approval-administration task and three forged-identity HTTP cases. |

All plugin PHP, JavaScript, CSS and SQL files had identical fingerprints before and after this consolidated run. There were no SQL errors or JavaScript page errors. Its strict diagnostic gate detected three identical warnings from GLPI's native `Document::isImage()`: the local PHP configuration had not enabled its installed EXIF extension. After enabling EXIF, all seven affected native-ticket browser scenarios were repeated with fresh fixtures and an unchanged source fingerprint; that focused rerun logged no new PHP warnings/errors or SQL errors. No plugin error or source fix was involved. Initial test-harness failures are excluded from the successful task counts.

### Approval and native-ticket acceptance

| Task or boundary | Checked behavior |
| --- | --- |
| Configure defaults | Multiple GLPI groups were selected through the actual native selectors, saved by resource type and reopened intact. Entity scope, workflow-administrator capability and stale configuration revisions were checked independently. |
| Customize one resource | A custom pool with two groups and one user saved and reopened. It replaced inherited defaults; invalid duration retained the chosen targets. The editor showed inheritance and the effective approvers. |
| Preserve existing policies | A resource whose old JSON omitted `approval_mode` retained its exact legacy group. A name-only edit during an active request remained possible; new default settings did not rewrite it. |
| Freeze submitted requests | A submitted inherited pool retained its original groups after configuration changes. Changing its dates reused that frozen pool, archived previous decisions and requested fresh decisions. |
| Reach an ordinary approver | Group and individual targets became deduplicated native ticket observers. Accounts without global ticket-read permission could open the actual ticket using their native profile rights. Observer creation failure rolled back the new booking and ticket. |
| Record genuine decisions | Pending inherited/custom alternatives created no native waiting validations. The selected person made one actual native User validation; alternative targets became covered. Mixed resources remained submitted after the first line, then approved only after all required lines. |
| Enforce the decision boundary | Nonmember administrators, revoked group members, inactive users, foreign lines and actors missing the required plugin/native ticket-type validation right could not decide. A custodian or ticket assignment did not imply approval. |
| Protect restricted resources | Mixed public pools worked. Disjoint private pools were rejected before any ticket or observer writes; a pool explicitly covered by every private resource succeeded. User membership was not assumed to authorize disclosure. |
| Keep native rules consistent | A real `RuleTicket` changed an unrelated control ticket to Solved when native validation became Accepted. On an active reservation it instead caused a complete rollback of the partial approval. A normal external `Ticket::update()` triggering the same rule was vetoed before the ticket write. Legitimate workflow completion still succeeded. |
| Work in the actual ticket tab | Two ordinary group members approved their own resource lines through the native Reserva Plus tab. An individual approver acted there too. Every successful action returned to that same ticket and selected tab. |
| Manage dates without approval | A scoped manager of an approval-free resource changed dates in the tab without plugin approval or native validation capability. No native vote was invented. |
| Complete physical return | A technician issued equipment, recorded item/accessory receipt, inspected it, prepared and signed the inspection document, then completed the reservation entirely from the native ticket workflow. |
| Give requesters their own actions | The borrower saw cancellation and applicable document actions, without staff approval/issue/inspection/date controls. Cancellation worked; responsibility PDF preparation, explicit acceptance, integrity verification and return to the same ticket worked. Original PDFs returned real PDF bytes. |
| Preserve context securely | Foreign ticket IDs, URL-shaped return values and removed/bound command context were rejected with HTTP 403, no redirect and no booking mutation. Unit checks additionally covered expired/foreign actors and independent native/plugin read permissions. |
| Close rejected requests clearly | Rejecting one eligible line closed the request, removed approval controls and labelled unanswered lines as closed without a decision. |
| Reject foreign selector identities | Forged default-group, custom-group/user and requester-group submissions returned generic errors without exposing their names anywhere in the HTTP response. Hashes of defaults/resources remained unchanged. |

The actual native-ticket desktop and 390-pixel mobile views, default settings and resource-specific approval editor were visually inspected. Mobile tables scroll inside their cards. Fixtures exercised real native authenticated profiles; they did not bypass resource or ticket access checks in the production endpoints.

The consolidated command was `python3 runtime/with-db.py python3 runtime/rc3-validation.py`. Local routers, authentication fixtures, browser scripts, screenshots and generated fixture documents are validation intermediates and are excluded from the installable plugin. The packaged `tests/approval_policy_integration.php` uses the same explicit disposable-database safeguards as the other integration suites. The rc2 and rc1 records below remain historical baselines.

## rc2 final rerun — 2026-10-28

The disposable installation was updated and activated as 0.7.0-rc2. The complete suites were rerun against the final implementation, followed by task-oriented browser checks. This extends the rc1 baseline below: rendering a page did not establish that category discovery, a cart spanning pages, contextual administration and the technician work queues were usable.

| Suite | Final rc2 result |
| --- | --- |
| PHP syntax | 94 PHP files passed. |
| JavaScript syntax | Eight files passed `node --check`, including the public mirrors. |
| Standalone tests | 98 checks across 12 test files passed: access 12, calendar 4, dashboard 6, documents 5, intake 5, legacy catalog 3, legacy security 37, query 3, resource administration 5, resources 8, UI 3 and workflow 7. |
| Upgrade preservation | All 40 original profile-right rows, including explicit denials, and the original linked asset/reservation item/reservation/legacy request remained intact after the rc2 update. |
| Legacy live verifier | 95 checks passed; its fixtures rolled back. |
| Native integration | 38 scenario groups passed: workflow 11, resources 6, documents 8, concurrency 4, scheduler 2 and existing-resource adoption 7. |
| Browser tasks | 12 complete scenarios passed in Chromium against actual GLPI HTTP routes; the scenarios contain multiple assertions and interactions. |

The final consolidated run compared all plugin PHP, JavaScript, CSS and SQL files before and after execution: no source changed. It produced no new PHP warnings/errors and no SQL errors, including checks for logs created during the run. The browser tasks produced no JavaScript page errors.

### Task acceptance checked through the real interface

| Task | Evidence from rc2 |
| --- | --- |
| Find and reserve several items | A Self-Service account opened an equipment category without knowing a name, selected two equipment items on different catalog pages, switched to rooms, added a room and reviewed all three. Browsing/review created no booking or ticket; final submission created exactly one booking, one native ticket and the three reviewed resource lines. |
| Complete the request without JavaScript | The same cross-page and cross-category selection, details, review and final three-item submission worked with JavaScript disabled. It also created exactly one booking and ticket. |
| Work from the technician dashboard | All seven queue totals matched the complete paginated request lists. The five-row preview did not cap totals. Outstanding custody appeared as overdue; the fixture whose item and accessories were received did not. Self-Service saw its personal home and categories without technician queues. |
| Open an existing bookmark | An authorized rc1 `booking.form.php?id=…` link issued a 303 to the existing booking. A private foreign record returned 403 without a redirect. Neither navigation created a booking or ticket. |
| Read availability in the calendar | Month, week and day views retained the selected date; occupied items had recognizable resource labels. Another user's occupation did not reveal its purpose or booking link, and inaccessible resources were absent. The crowded feed returned more than 100 events without pagination truncation. Invalid periods returned an error instead of an apparently empty schedule. |
| Use calendar navigation without JavaScript | Category filtering and week navigation remained functional through server-rendered forms and links. |
| Register the right type of resource | The first page offered equipment/room/other choices. The editor opened one section at a time; room capacity was hidden and disabled for equipment and became available for rooms. Structured accessory rows and entered values survived a rejected submission. Self-Service could not open the administrator editor. |
| Save an internal-only draft | With JavaScript disabled, accessory rows could be added and the draft saved through an actual success redirect. Exactly one plugin resource was created, with no Computer inventory record. |
| Edit restricted resources safely | An ordinary form edit preselected and preserved two requester groups and the configured approval group. This covered both the native dropdown's array value and the submitted multiple-field name. |
| Create a sector | The listing opened a separate creation page. Saving returned to the listing and displayed the persisted sector rather than reporting the redirect as an error. |
| Adopt an existing reservable item | Browsing and preview caused no writes. Explicit adoption preserved the name, room capacity, both authorized requester groups, Computer identity and ReservationItem identity, and created one unpublished plugin draft without duplicating inventory. Native integration also exercised permission/entity denial, active/future reservation blocking and rollback after association failure. |
| Prepare, accept and reopen a document | The booking showed the applicable handover action. Preparation opened the scoped document; the original PDF returned real PDF bytes. Explicit acceptance returned an HTTP redirect and exposed the final artifact. Integrity verification returned to that same document, and its back link returned to the existing booking. |

Desktop layouts and 390-pixel mobile layouts were visually inspected. Mobile checks also measured usable content width after GLPI's sidebar transition settled, rather than relying only on the absence of horizontal overflow. The resource owner separately exercised the native resource editor in JavaScript and no-JavaScript modes and its mobile layout.

The task checks found and drove corrections to swallowed GLPI redirect exceptions, requester-group preselection/array submission, document URLs under GLPI's controller include scope, and existing-booking links. The final results above were obtained after those corrections; initial failing runs are not counted as final successes.

The consolidated local command was `python3 runtime/with-db.py python3 runtime/rc2-validation.py`. The validation-only runtime, fixture session route, screenshots and generated fixture documents are not included in the installable plugin. The record below preserves the earlier baseline rather than presenting it as new rc2 coverage.

## rc1 baseline — automated results

| Suite | Result / scope |
| --- | --- |
| PHP syntax | All 79 packaged PHP files passed lint. |
| Standalone tests | Six test files passed with 72 checks: authorization (12), resources (8), workflow (7), documents (5), UI (3) and legacy behavior (37). |
| Upgrade preservation | Original 0.6.1 was installed first. All 40 existing profile-right rows, including explicit denials, and the original native asset, reservation item, reservation and legacy request survived upgrade. |
| Legacy live verifier | 95 checks passed using actual GLPI objects and database rows; fixtures rolled back. |
| Workflow live integration | 11 grouped scenarios passed: self-service submission, one native ticket, retry identity, group approval, calendar allocation, native mutation guards, partial return, inspection, signed completion, room closure, ticket reuse, revisions and hold handling. |
| Resource live integration | Six grouped scenarios passed: internal-only inventory identity, restricted calendar mode, policy strengthening/weakening, revision conflicts, scoped discovery, stale-session membership revocation and entity isolation. |
| Document live integration | Eight grouped scenarios passed: viewed source/exact consent, actor scope, actual native attachment, replay, tampering, material versions, multiple return/inspection signers, failed finalization recovery and historical key preservation. |
| Concurrency | Four grouped multi-process scenarios passed, covering distinct submissions, identical retry keys, competing native approvals and forced-wait private-resource approvals. Only one competing allocation committed. |
| Native automatic actions | Two grouped scenarios passed for unsigned-hold expiry and outbox delivery using native GLPI CronTask execution without a logged-in user. |

Grouped scenarios include multiple assertions and are not counts of individual browser interactions.

The rc1 consolidated run used an unchanged production-source fingerprint and produced no new PHP error-log entries or SQL error log.

The forced-wait private-resource approval test originally reproduced two allocations under a stale REPEATABLE READ snapshot. The release fixes that failure with per-transaction READ COMMITTED plus the resource lock, and the same reproducer passes. The database connection's default isolation setting is not changed globally.

## rc1 baseline — HTTP, browser and PDF checks

- Native GLPI catalog cards rendered and opened resource-kind-specific intake. An actual Self-Service profile saw catalog/intake without staff actions or the on-behalf selector.
- Queue, detail, native ticket tab, calendar and static assets rendered through GLPI 11 HTTP routing. Mirrored assets matched.
- Missing native CSRF was rejected with HTTP 403. Valid CSRF reached the domain action and returned a 303 redirect. A successful HTTP form submission created a booking and its native ticket.
- Desktop and 390-pixel mobile layouts were inspected in a browser with no JavaScript page errors in the checked flows.
- Generated source/final Portuguese PDFs were rendered and visually inspected. Accented identities and room/location fields remained readable.
- Native final-document creation and the actual Ticket `Document_Item` association were checked in the database. Private evidence was not attached to the ticket.

Screenshots and generated fixture documents are validation intermediates, not included in the installable plugin.

## Repeating tests

Standalone checks do not require a database:

```bash
PHP_BIN=php bash tools/run-tests.sh
```

Live tests deliberately write fixtures. Provision a disposable GLPI 11.0.7 installation, install and activate this plugin, and configure an administrator and self-service fixture account. Use an exact expected database name; each test checks it before writes. A representative invocation is:

```bash
GLPI_ROOT=/path/to/disposable/glpi \
RP_TEST_DATABASE=disposable_glpi \
RP_TEST_ALLOW_WRITES=1 \
RP_TEST_NATIVE_GUARDS=1 \
RP_TEST_ADMIN=fixture_admin \
RP_TEST_EMPLOYEE=fixture_employee \
php tests/integration_workflow.php
```

The same guarded setup is used by `tests/resources_integration.php`, `tests/documents_integration.php`, `tests/integration_concurrency.php`, `tests/integration_cron.php`, `tests/legacy_catalog_integration.php`, `tests/approval_policy_integration.php` and `tools/verify.php`. Check each script's fixture assumptions before running. Some suites retain uniquely named fixtures for inspection; do not point them at production. The concurrency suite starts separate PHP workers.

## Limits of this evidence

This is a tested release candidate, not production certification. No production installation, deployment, database migration or external message delivery was performed. The tests do not establish compatibility with every GLPI 11 patch, PHP 8.2/8.4/8.5, MySQL, production data volume, external SMTP delivery, every native ticket rule/profile combination, SSO/MFA provider or third-party plugin combination.

The browser checks used native authenticated fixture sessions; they did not exercise an external login provider. Responsive checks used Chromium viewport emulation, not every physical phone or browser. Core no-JavaScript request and draft-save paths were checked; native GLPI AJAX dropdown searches retain GLPI's JavaScript requirement. No production-scale load or exhaustive accessibility audit was performed.

The site's real permissions, ticket state matrix, notification configuration, backup/restore procedure, scheduler, private file serving and document wording still require staging acceptance. Functional release boundaries are listed in the README. Generic native API writes, disabling the plugin, direct SQL and unrelated outer transactions are not supported ways to execute this workflow.
