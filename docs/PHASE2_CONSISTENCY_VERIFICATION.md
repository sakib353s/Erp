# PHASE 2 — Pre-Implementation Architecture Consistency Verification

Date: 2026-09-22
Authority: MASTER ERP SPECIFICATION (V2) + `docs/ARCHITECTURE.md` (D1–D24, §1–§22)
Purpose: Verify the approved architecture is internally consistent with the 18
non-negotiable rules **before any production code is written**.

Result: **CONSISTENT — no architecture redesign required.** All 18 rules map to
existing decisions; three clarifications (C1–C3, below) were recorded where the
implementation contract needed to be made explicit. No requirement was removed,
simplified, or reinterpreted.

---

## Rule-by-rule verification

| # | Non-negotiable rule | Architecture reference | Verification |
|---|---|---|---|
| 1 | Each operational ERP instance = ONE COMPANY with MULTIPLE BRANCHES | D1 (two-plane monorepo), D2 (one MySQL DB per instance), §3.3 (company is hard singleton), §7 (branches child of company) | `companies` table enforces a DB-level singleton (`singleton` column, UNIQUE). All operational tables carry `company_id` and, where branch-relevant, `branch_id`. No schema permits a second company in one instance. **PASS** |
| 2 | No multiple companies inside the same operational tenant | §3.3 ("MULTIPLE COMPANIES INSIDE ONE TENANT ERP ARE PROHIBITED"), D1 | No tenant-side API, model, route, or seeder can create a second company. `CompanyService::create()` throws if a company already exists; enforced again by the DB unique constraint and by a dedicated test (SingleCompanyTest). **PASS** |
| 3 | Rental/subscription platform = separate PLATFORM CONTROL PLANE provisioning isolated ERP instances | D1 (monorepo `erp/` + `platform/`), D2 (DB per instance), D21 (platform/tenant authority separation) | `platform/` is a distinct application with its own database and domain vocabulary (`PlatformInstance`, `Subscription`, `PlatformCustomer`). Tenant ERP has no subscription-billing authority; it only reads its own `instance_info` registration + feature entitlements. Provisioning creates an isolated instance (own DB), never a shared row set. **PASS** |
| 4 | Company, branch, users, roles, permissions, transactions, inventory, accounting, customers, suppliers, employees, service jobs, documents, notifications, audit records correctly scoped | §4 (identity & access foundation: company/branch keys, user_branch, warehouse scope), §7, §16, §17, §11 | Foundation migrations establish `company_id` (+ `branch_id` where applicable) on every scoped table created in Phase 2: users, roles, branches, warehouses, settings, workflows, approval requests, documents, notifications, audit events, outbox events, security events, feature entitlements. Business tables (inventory, accounting, customers, …) are Phase 3+ and inherit the same scoping rules from §4/§7 of the architecture; the branch-scope global scope + policies are built once in Phase 2 and reused. **PASS** |
| 5 | Branch access enforced server-side, not only in the UI | D6 (ScopeContext), §4.4 (server-side middleware; "client filter is UX only"), §15 (IDOR prevention) | `TenantContext` (branch/warehouse/company) is a server-side container-bound object rebuilt every request from DB state, never from client input. `SetTenantContext` middleware re-validates the session-selected branch against `user_branch`/`branch_scope` on every request; branch selector endpoint re-checks server-side; `BranchScope` global scope + `BranchScopedPolicy` checks enforce independently of UI. **PASS** |
| 6 | Permissions database-driven | D5, §4.3 ("permission keys must be stable in the database and labels must be translatable"), §2 (no hard-coded permission logic) | Permissions live in `permissions` table with stable keys; roles/role_user/permission_role/user_permission pivots in DB; `PermissionManager` loads effective permissions from DB (cached, invalidated on change). No `Gate::define` per business permission; middleware accepts only keys resolved from the DB definitions table. **PASS** |
| 7 | Sidebar visibility, page access, action access, widget visibility, export, print, download, approval, AJAX/API all respect authorization | §4.3 (3 enforcement layers: menu, page, action), §4.5, §4.6, §15 (AJAX cannot bypass), §10.2 (widget permission mapping), §18.4 (export/print/download permission keys) | Menu builder filters by effective permissions + portal + entitlement. Pages/actions gated by `CheckPermission` middleware (web + JSON responses return structured 403). Widgets filtered via `widgets.permission_id`. Export/print/download are permission-keyed actions (same middleware, distinct keys). Approval endpoints call `ApprovalAuthority` service → policy → permission check server-side. AJAX endpoints are inside the same authenticated+authorized route groups — no unguarded API island. **PASS** |
| 8 | Printed commercial sales document titled INVOICE, not TAX INVOICE | D8 (invoice title rule), §18.2 | `document_types.printed_title` for `sales_invoice` = `INVOICE` (seeded as structural data). Tax Invoice/Mushak 9.1/11 are distinct `document_type` rows with their own titles. Renderer reads title from the document type registry — the default normal sales print cannot become TAX INVOICE through configuration of the invoice type itself; tax invoice is a separate type. Invariant test asserts the title. **PASS** |
| 9 | Tax appears only when configured/applicable | D8, §18.2 (zero-rate documents do not print tax tables), Mushak 9.1 eligibility only when tax registered | Tax rendering is conditional in the document renderer (Phase O): tax table printed only when document has tax lines produced by configured rates/registration. Foundation seeds the document types and tax-conditional flag; tax computation itself is Phase E (Sales). Architecture §18 documents the conditional path; no unconditional tax block exists. **PASS** |
| 10 | Tax Invoice/Mushak documents remain separate document types | D8, §18.3 (Mushak as separate document types), §18.6 | `mushak_9_1` and `mushak_11` are separate rows in `document_types` (group `statutory`), separate numbering, separate routes, separate authorization. The normal invoice flow never emits them implicitly. **PASS** |
| 11 | No fake business data in production | D22, §21 (Phase 0 seed policy: no fake customers/invoices/sales/stock/employees/profit), implementation rule "no fake data" | Seeders create only structural/system rows: permissions, menu registry, document types, numbering rules, workflow definition templates (disabled), system settings, translations, feature entitlements defaults. Zero rows in any future business table. A seeder guard test asserts business tables are empty after `db:seed`. Phase 2 report will enumerate every seeded row class. **PASS** |
| 12 | Critical business operations use real DB transactions + concurrency protection | D4 (DECIMAL financial types), §6.4 (unit of work, SELECT FOR UPDATE, deadlock order, retry), §8.2 (DOUBLE-ENTRY-01), §9.3 (idempotency keys), D19 | Foundation establishes `UnitOfWork` conventions: all multi-write operations wrapped in `DB::transaction`; row-locked reads (`lockForUpdate`) ordered by stable key; `idempotency_keys` table + `IdempotencyGuard` for retries; unique constraints as backstop (numbering sequences, idempotency keys, workflow snapshot hash). Approval decision path uses a transaction + row lock on the approval request. Business phases (E, F, G) apply the same foundation. **PASS** |
| 13 | Accounting uses real double-entry | §8.2 (invariants DOUBLE-ENTRY-01..04), D9 | Architecture mandates journal lines with balanced debits/credits enforced at service + DB CHECK level; no balance-column ledger. Phase 2 does not build the ledger (Phase I), but the foundation (migrations style, constraint approach, unit-of-work, audit of financial transactions) is the substrate defined by §8.2. No rule in the Phase 2 foundation contradicts or bypasses double-entry. **PASS (foundation consistent; ledger implementation deferred to Phase I as sequenced)** |
| 14 | Inventory uses real stock movements/ledgers, not a mutable stock number | §7.4 (postings immutable, StockService is only writer), D10 (valuation), stock_ledger schema | Architecture defines `stock_ledger` as source of truth; `stock_on_hand` (if present) is a derived/cached projection recomputed from the ledger. Phase 2 does not create inventory tables (Phase F) but the outbox/audit/unit-of-work foundations it builds are the required writers' substrate. No foundation component writes a stock number. **PASS (foundation consistent; ledger implementation deferred to Phase F as sequenced)** |
| 15 | Approval uses the generic database-driven workflow engine | D7 (statuses DB-driven, TransitionService only), §12 (generic engine: definitions, versions, states, transitions, approver rules, thresholds, delegation, escalation), implementation rule "generic approval engine only" | Phase 2 builds the engine itself: workflow_definitions/versions/states/transitions/conditions/approver_rules, approval_requests/steps/actions/delegations — fully data-driven. No business module may embed approval logic; modules call `WorkflowEngine`. Self-approval blocking is a definition flag enforced by the engine. **PASS** |
| 16 | Important business/security actions auditable | §16 (audit events schema, hash chain, viewer permission), §13.5 (security events), D14 | `audit_events` captures actor/action/entity/company/branch/ip/UA/correlation/before-after/amount/result/reason with SHA-256 hash chain (D14) and chain verifier. `security_events` + `auth_events` capture authentication/suspicious activity. Audit recorder is a shared foundation service callable from every module; audit viewer permission-gated. Secrets redacted before persistence. **PASS** |
| 17 | Core ERP must not depend on an external AI API | D12 (no AI dependency), §17 (fallback logic), §19 (optional adapters) | Zero AI/LLM API clients in the foundation; intelligence layer is an in-process heuristic service (D12). No config key, service binding, or queue job requires an external AI endpoint for any core flow. **PASS** |
| 18 | External integrations optional adapters; must not corrupt or block core ERP | D13 (outbox-truthful states), §19 (ports & adapters, circuit breaker, feature-detect), integration state enum (ACTIVE/CONFIGURED_UNVERIFIED/DISABLED…), §16 audit exports | Notification/email/SMS providers are adapters behind interfaces with truthful per-message states; failures produce `FAILED_TRANSAULT` states (never fake `SENT`), retries via queue with backoff, and never throw into the core transaction (post-commit outbox dispatch). Core flows commit first; adapter delivery is asynchronous and isolated. No Phase 2 code path blocks on an external call. **PASS** |

---

## Cross-rule internal consistency checks

1. **Singleton company × provisioning (rules 1, 2, 3):** platform control plane
   provisions by creating a *new isolated instance* (new database + new
   application config), never by inserting a second company row into an existing
   tenant DB. Consistent with D1/D2/D21.
2. **Server-side branch scope × workflow approvers (rules 5, 15):** approver
   resolution evaluates the requester's and approver's effective branch scope
   from the DB at decision time; the engine never trusts a branch id supplied by
   the client. Consistent with D6/§12.
3. **Authorization on AJAX × idempotent jobs (rules 7, 12):** idempotency keys
   are scoped per user+endpoint so one user's key can never replay another
   user's response (IDOR-safe retries). Consistent with §9.3/§15.
4. **INVOICE title × audit (rules 8, 9, 16):** printing/downloading any document
   (including INVOICE and Mushak types) is an audited action with document type
   and number recorded. Consistent with §16/§18.
5. **No fake data × seeder-driven navigation (rules 6, 11):** menu registry and
   permissions are *structural* rows (allowed); they reference only implemented
   routes — unimplemented children are seeded as `status='planned'` and are
   filtered from the sidebar, so the UI never shows dead placeholder entries.
   Consistent with §2/§4.5.
6. **Outbox truthfulness × external adapters (rules 13/14 foundations, 18):**
   core accounting/stock effects commit in the same DB transaction as the
   business row; only *external delivery* (email/SMS/webhook) goes through the
   outbox after commit. Consistent with D13/§13.
7. **Audit hash chain × high-volume writes (rules 12, 16):** chain serialization
   locks the company singleton row (already unique and low-contention) instead
   of a separate mutex table — one writer-critical section per company,
   consistent with the deadlock-ordered `SELECT FOR UPDATE` policy in §6.4.

## Clarifications recorded (no requirement added or removed)

- **C1 — Menu status flag:** `menu_items.status` (`active`/`planned`) was added
  to the navigation registry so the *complete* supplied menu tree can be
  catalogued from day one while the sidebar renders only `active`,
  permission-passing entries. Prevents dead links (rule: no placeholder UI)
  without deferring the registry itself.
- **C2 — Warehouse scope default:** users default to
  `warehouse_scope='all_within_branch'` (all warehouses of their current
  branch); `assigned` restricts to explicitly assigned warehouses. The rule
  "one branch, multiple branches, all branches" (rule 5) is mirrored for
  warehouses with the same server-side enforcement.
- **C3 — Feature entitlements location:** entitlement rows are *mirrored into
  the tenant DB* by the control plane at provisioning/upgrade time, so the
  tenant menu/authorization path never makes a synchronous platform call
  (rules 3, 18).

## Conclusion

The architecture as recorded in `docs/ARCHITECTURE.md` is internally consistent
with all 18 non-negotiable rules. Phase 2 implementation proceeds against it
without redesign.
