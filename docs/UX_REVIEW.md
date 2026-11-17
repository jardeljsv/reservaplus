# Usage review and corrections — 0.7.0-rc2

The supplied 0.6.1 package is the reference for established interaction patterns. The rc1 workflow added custody and document controls, but several replacement pages made ordinary tasks harder. This revision compares the original source and supplied screenshots, restores useful patterns, and keeps the new ticket/return workflow behind them.

The rc3 extension preserves these layouts and adds actions inside the native ticket tab plus inherited/custom approval controls. Its detailed rules are in [APPROVALS.md](APPROVALS.md).

## What regressed

| Finding | Consequence | Correction |
| --- | --- | --- |
| New pages did not use the original `.reservaplus-shell` styling scope. | The original blue navigation, cards and layout disappeared even though its CSS remained. | One shared shell and scoped navigation for the workflow pages, with responsive spacing and consistent buttons, forms and tables. |
| The default route changed from dashboard to a request list. | Staff lost a useful operational starting point. | Staff land on an actionable dashboard; requesters get a distinct personal home. |
| Intake began with a search and a short result page. Selection was tied to that page. | Users needed to know a resource name and could lose selections while finding more items. | Category-first discovery, resource cards and a persistent selection across categories/pages. Search is a secondary refinement. |
| Resource registration showed identity, room, equipment, policies and native integration together. | The operator had to understand irrelevant fields before registering a simple item. | Choose the type first; use identity, responsibility, reservation rules and review sections. Optional integration/location detail is secondary. |
| The replacement calendar was a sparse month view over a paginated request list. | It lost navigation/context and could omit occupancy after the first 100 bookings. | A scoped interval projection feeds month/week/day views, day details and explicit crowded-day links. |
| Existing reservable GLPI items were outside the new registry. | An upgraded installation could present an empty new catalog despite existing resources. | An explicit incorporation browser helps administrators review and publish existing items without creating duplicate inventory. |

## Page responsibilities

| Page | Primary task | Information shown first | Contextual or secondary information |
| --- | --- | --- | --- |
| **Painel** — technician | Find the next work to perform. | Approval, issue, loan/overdue, inspection, signature and integration queues; today's agenda. | Exact scoped counts link to the corresponding list; previews are visibly limited. Category availability and legacy shortcuts follow. |
| **Início** — requester | Start a reservation or act on an existing one. | Category choices, own active reservations and pending signatures. | No resource administration or technician-only queues. |
| **Reservar** — selection | Discover and choose suitable resources. | Category cards, shared period, available resource cards and the complete current selection. | Optional name/location/features filters; room capacity/attendance only when relevant. Duration shortcuts and alternative periods reduce manual date entry. |
| **Reservar** — details/review | Explain the use and confirm exactly what will be sent. | Purpose, relevant participant data, then all resources and dates. | Authorized on-behalf selection and optional declared employee/unit fields. Changes require reviewing the current selection again. |
| **Agenda** | Understand availability at a useful scale. | Month/week/day navigation, resource/time/status labels and selected-day details. | Category/resource/status/mine filters and a period-prefilled reservation action. Other users' private details are not needed to show occupancy. |
| **Solicitações / Minhas solicitações** | Find a particular request or work queue. | Resource names, borrower, period, status and ticket. | Search by resource, location, borrower, purpose or ticket; additional date/sort filters stay secondary. |
| **Solicitação** — detail | Understand progress and perform the next allowed action. | Workflow stage, resources, dates and ticket connection. | Role/state-specific approval, issue, receipt and inspection controls; relevant document actions; collapsed document history. |
| **Recursos** | Find, inspect or register a resource. | Resource cards with type, location, state and responsibility. | Filters, type-based creation and explicit incorporation from GLPI. A requester's resource view is read-only. |
| **Recurso** — registration | Save an accurate internal resource and publish it deliberately. | Type selection, then one configuration section at a time. | Room location/capacity fields versus equipment identifiers/accessories; optional map/native links and advanced policy controls. Invalid input is retained. Drafts need not be published. |
| **Setores** | Find and maintain the organizational units responsible for resources. | The sector list and a deliberate create/edit action. | The form opens for the selected task instead of occupying the initial listing. |
| **Documento** | Read the exact document, accept it when designated, and inspect its state. | Purpose, version, PDF and applicable acceptance action. | Technical hashes and historical versions are secondary; artifact recovery remains visible when needed. |

## End-to-end usage

A requester can choose equipment, select items on different pages, switch to rooms and add a room without losing the equipment. The complete selection uses one period and proceeds through details and review into one booking and one ticket. Up to 32 individually identified resources are supported. Resources assigned to different responsible ticket groups still require separate requests. Since rc3, different per-resource approval pools may share a request when their resource visibility is compatible; the plugin does not silently split or reroute requests.

Staff use the dashboard to reach the pending action, then record physical facts in the booking detail. Receiving the main equipment does not hide outstanding accessories. Overdue accessories remain in the queue; a room past its planned end appears as awaiting closure/inspection rather than as an equipment return. PDF/signature completion remains separate from recording a real receipt.

Registration starts with a room, equipment or other-resource choice. The operator can save an internal draft without creating inventory, complete responsibilities and policies, review, then publish. For an existing native item, incorporation preserves its native identity and previous requester restrictions. Current/future legacy reservations block incorporation so those requests can finish under their original workflow. Pause simultaneous native booking creation for that item during enrollment.

## Preserved patterns and explicit limits

- Restored category discovery, select-visible/clear controls, multi-item selection, duration shortcuts, available-period suggestions, calendar scales and an operational dashboard.
- Added **Reservar novamente**, which seeds a new draft from currently eligible resources and purpose. Old dates, tickets, approvals and signatures are never copied.
- The original reservation, calendar and reporting screens remain under explicitly labeled legacy shortcuts where permissions allow. New signed workflow records and legacy reservations remain distinct.
- Recurrence remains available through the original reservation flow; it is not yet a feature of the new ticket/custody aggregate. Repeat-request convenience is not equivalent to recurring scheduling.
- Native calendar synchronization still targets GLPI **Reservations**, not staff Planning tasks. Group-restricted internal resources remain in the plugin calendar where native discovery would expose them.
- This is a functional and visual correction, not evidence of user acceptance in the customer's exact GLPI theme, extensions, data or permission configuration. The included validation record describes the actual environment and completed tasks.
