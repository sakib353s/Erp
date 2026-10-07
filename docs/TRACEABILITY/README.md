# Requirement → Implementation Traceability Matrix

Covers every module, menu, submenu, sub-submenu, page, action, workflow, permission, database entity, service, and test from the supplied menu tree (Spec §47) plus cross-cutting requirements (Spec §16–41, 49–51).

Authoritative spec: `QWEN3_8_FLASH_PRODUCTION_GRADE_BANGLADESH_ERP_MASTER_PROMPT_V2.txt`. Architecture: `docs/ARCHITECTURE.md`.

## Files

| File | Module |
|---|---|
| `01-dashboard.md` | 01 Dashboard (exactly 25 widgets) |
| `02-sales.md` | 02 Sales |
| `03-purchase.md` | 03 Purchase |
| `04-inventory.md` | 04 Inventory |
| `05-customers.md` | 05 Customers |
| `06-suppliers.md` | 06 Suppliers |
| `07-returns.md` | 07 Returns |
| `08-cash-bank.md` | 08 Cash & Bank |
| `09-accounting.md` | 09 Accounting |
| `10-employee.md` | 10 Employee (incl. HR/leave/payroll/acknowledgement) |
| `11-marketing.md` | 11 Marketing |
| `12-business-management.md` | 12 Business Management |
| `13-reports.md` | 13 Reports |
| `14-masters.md` | 14 Masters |
| `15-settings.md` | 15 Settings |
| `16-cross-cutting.md` | Service/Technician, Warranty/QR/Public links, Print/PDF, Security, Audit, Notifications/Outbox, BI, HA/Backup/Maintenance, Search, i18n, Platform Control Plane, First-boot |

## Column definitions

| Column | Meaning |
|---|---|
| Ref | Stable traceability ID (`M-LL.NN`); never reused |
| Menu path | Exact menu › submenu › sub-submenu › action from the supplied tree |
| Route | Real HTTP route + page/endpoint. A route alone is NOT an implementation |
| Permission | Stable DB permission key(s) (`module.resource.action`), seeded in `permissions`, granted via roles/users, enforced server-side by Policy/Gate + middleware |
| Backend | Concrete Action/Service/Query/Policy/Job classes that must exist and be wired |
| DB entities | Tables that must exist with real persistence and relationships |
| WF / Effects | `WF` approval workflow required · `ACCT` GL posting effect · `STK` stock ledger effect · `NOT` notification/outbox · `AUD` audit event · `DOC` document/PDF · `BI` intelligence signal |
| Tests | Test classes that must pass proving the connected behavior |
| Status | `PLANNED` → `IN PROGRESS` → `DONE` → `VERIFIED`. A row is `DONE` only when persistence + validation + authorization + branch scope + business logic + workflow + effects + notifications + audit + UI + error handling are connected end-to-end and its Tests are green. `DONE` is forbidden if only a menu/route/button/table exists |

## Baseline (`BL`) — applies to EVERY row unless overridden

A row may not be marked `DONE` unless all baseline conditions hold:

1. **Persistence**: listed DB entities exist with migrations, FKs, indexes; state stored in DB (never localStorage/session/JS).
2. **Validation**: FormRequest/domain validation with translatable messages; server-authoritative money math (BCMath, D4).
3. **Authorization**: permission key checked server-side (Policy + middleware); route and AJAX endpoint both protected.
4. **Branch scope**: `ScopeContext` applied — list, detail, export, search, drill-down, print all filtered; cross-branch access returns 404/403 (D5).
5. **Business logic**: real domain Action/Service — no fake API responses, no client-trusted totals.
6. **Workflow**: where `WF` is listed, the generic ApprovalEngine (`app/Domain/Workflow`) drives persisted state; no module-specific hard-coded approval logic.
7. **Effects**: `ACCT`/`STK` effects occur only at configured posting stage, balanced, idempotent (D18), reversible via reversal — never destructive edit.
8. **Notifications**: `NOT` rows go through outbox with truthful provider states (D12) — never claim "sent" without provider acceptance.
9. **Audit**: `AUD` rows write `audit_events` with actor/action/target/snapshots (D19); audit itself append-only.
10. **UI**: page rendered inside the app shell from DB-driven menu (hidden if unauthorized, never disabled-locked), with loading/empty/validation/success/error/403/404 states, EN+BN strings (D21), responsive mode behavior.
11. **Errors**: polished HTML for page navigation, structured JSON for AJAX, no raw exception output.
12. **Tests**: the row's listed tests exist and pass.

## Status rules

- Menu item exists but backend/table/tests missing → status stays `PLANNED`.
- Fake/demo data, static statistics, placeholder controllers, or modal-without-persistence are **self-audit failures** (Spec §48), never `DONE`.
- Status source of truth: this matrix + machine-readable `docs/IMPLEMENTATION_STATUS.json`. Updated at the end of every phase (Spec §43/§52).
