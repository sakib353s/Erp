# MASTER ERP — PRODUCTION ARCHITECTURE

Authoritative source: `QWEN3_8_FLASH_PRODUCTION_GRADE_BANGLADESH_ERP_MASTER_PROMPT_V2.txt` (3,639 lines, read in full).
This document is the implementation contract for all subsequent phases. Status of every traced row lives in `docs/IMPLEMENTATION_STATUS.json` and `docs/TRACEABILITY/*`. Phases 0/A/B/C (bootstrap, foundation, RBAC, workflow engine) are DONE with a green 94-test suite; all 15 business modules remain `PLANNED` (next: Phase D — Accounting + ledger core).

---

## 0. Binding Design Decisions (referenced by D-ID throughout)

| ID | Decision | Rationale (spec source) |
|----|----------|--------------------------|
| D1 | Monorepo with two deployable planes: `erp/` (operational business plane, Laravel 13) and `platform/` (rental control plane, separate small Laravel app, separate database). | One-company-per-instance rule; control plane must not mix with tenant ledger. |
| D2 | Instance isolation = one MySQL database per rented instance + dedicated storage namespace. On cPanel: per-instance subdomain docroot + per-instance DB. Platform DB stores instance metadata only. | "Prefer separate schema/database/storage namespaces"; "no tenant request may cross an instance boundary". |
| D3 | Operational ERP contains exactly ONE `companies` row (guarded by DB constraint/unique). No multi-company code paths. Branch is the only organizational split. | Absolute one-company rule. |
| D4 | Decimal rules: `quantity DECIMAL(18,4)`, `unit_price/rate DECIMAL(18,6)`, `amount DECIMAL(20,2)`, `tax_rate DECIMAL(9,6)`, `exchange_rate DECIMAL(18,8)`. All math via BCMath value objects (`Money`, `Qty`, `Rate`); central `RoundingService` (currency minor units, half-up, configurable). JS calculations are display-only. | Safe decimal arithmetic invariant; real calculation engine section. |
| D5 | Every business table carries `company_id` (single value, indexed) and, where branch-sensitive, `branch_id` + `created_by`; global `BranchScope` global scope + explicit `ScopeContext` injected into query services. | Branch isolation invariant. |
| D6 | Documents have two orthogonal state axes: `workflow_state` (approval) and `posting_state` (draft → posted → reversed). GL/stock effects fire only at configured posting stage. | Accounting/inventory staging rules. |
| D7 | Statuses are DB rows (`statuses`, `status_transitions`) per entity type; state changes go through one `TransitionService` that validates against DB metadata + workflow. | Database-driven statuses; no controller conditionals. |
| D8 | Valuation methods: FIFO, LIFO (implemented faithfully as newest-layer consumption), Weighted Average, Standard — selectable per product/category; management valuation vs statutory valuation kept as separate flags/outputs. FEFO for expiry goods. | Inventory section; "do not fake LIFO" satisfied by faithful layer accounting. |
| D9 | Numbering: `numbering_rules` + `numbering_sequences` rows locked with `SELECT ... FOR UPDATE` inside the posting transaction; pattern tokens `{PREFIX}{BRANCH}{YYMM}{SEQ}`; fiscal-year/monthly reset. Never `MAX()+1`. | Concurrency-safe numbering. |
| D10 | Normal printed sales document title = **INVOICE**. `document_types.title` controls the printed title. Tax block rendered only when a tax rule resolves as applicable for that transaction. Mushak 9.1 / Mushak 11 are distinct `document_types` rows with their own templates. | Absolute invoice/tax rule. |
| D11 | Public tokens (QR verification, public document links): 32 random bytes (`random_bytes`), stored **hashed** (SHA-256) with `revoked_at`, `rotated_from` columns. Permanent = no auto-expiry; admin revoke/rotate; throttled + audited access. | Public verification section. |
| D12 | Notification truth: `outbox_messages.state ∈ {pending, provider_accepted, provider_failed, disabled_provider, cancelled}`. "sent" is only written after provider acceptance. No credentials → `disabled_provider` with human-readable reason shown in UI. | Fake provider behavior correction H. |
| D13 | External AI: none required. `Intelligence\` namespace uses deterministic statistics only. Optional provider adapters exist but no core flow depends on them. | No-external-AI-API rule. |
| D14 | No TOTP. Login security = configurable policy engine (throttle, lockout, rehash, sessions, risk signals). | No-TOTP rule. |
| D15 | Queue driver: `database` on cPanel, `redis` where available. Scheduler via cron. `dispatch_after_commit` for all external side effects. | HA/cPanel realities. |
| D16 | PDF via internal `DocumentRenderer` port with a maintained Laravel-13-compatible HTML→PDF adapter (DomPDF-class) + native HTML print views; Bangla font embedding (Noto Sans Bengali) required. | Document/print rules. |
| D17 | Search: normalized `search_index` table + MySQL FULLTEXT, rebuildable from source tables; permission and branch filtered at query time. | Search section. |
| D18 | Idempotency: `idempotency_keys (scope, key, request_hash, response_snapshot, UNIQUE(scope,key))` used by payments, invoice issuance, stock posting, warranty activation, outbox delivery, salary acknowledgement, delivery events, POS sync. | Idempotency section. |
| D19 | Audit: append-only `audit_events` with per-company hash chain (`seq`, `prev_hash`, `row_hash`), redacted snapshots, no DELETE/UPDATE granted to the application DB user where infrastructure permits. `audit.view` access is itself audited. | Audit/CCTV section. |
| D20 | Dashboard = exactly 25 widget containers; item 25 is the composite **"Branch Comparison & Recent Activity"** widget. Widget definitions, permissions, visibility, and order are DB rows. | Dashboard correction A. |
| D21 | i18n: `translations` table loaded into a DB-backed translator; all user-visible strings are keys. Bengali numerals, Bengali amount-in-words, lakh/crore formatting are services used by UI + documents. | Language rule. |
| D22 | Platform Super Admin ≠ tenant Company Admin. Platform app has no route into tenant business tables; tenant app has no platform powers. Suspension preserves tenant data; reactivation touches access flags only. | Rental control-plane rule. |
| D23 | First boot: installer flow creates first Super Admin from explicit setup credentials (one-time token, no default password), seeds master/system data only — never business/demo data. Onboarding checklist replaces fake dashboard numbers until real data exists. | First-boot + no-fake-data rules. |
| D24 | HA posture is tiered and honest: Tier A (InnoDB Cluster + MySQL Router + app nodes), Tier B (primary + replica + router), Tier C (cPanel single node) — labeled in system health UI. No ad-hoc promotion logic in controllers. | HA rule. |

---

## 1. Complete Production Architecture

### 1.1 Planes

```
PLATFORM CONTROL PLANE  (platform/ — own DB: erp_platform)
  instance provisioning · non-enumerable slug login URLs · packages/entitlements
  billing periods · payments/receipts · renewal/grace/overdue/suspended/active
  suspend/reactivate · health/backup/storage status · deletion request workflow
  tenant notifications (instance lifecycle only) · platform audit trail
        │ provisions / suspends (metadata only)
        ▼
ERP BUSINESS PLANE  (erp/ — one DB per instance: erp_inst_<slug>)
  ONE company → MANY branches → warehouses/users/transactions
  15 modules (Dashboard…Settings) · engines (workflow, accounting, inventory,
  warranty, documents, notifications, audit, intelligence)
```

### 1.2 Application layers (erp/)

```
Presentation   Blade components + JS modules (Vite) · thin Controllers · Form Requests
Authorization  Policies · Gates · PermissionService · ScopeContext (branch)
Application    Actions/UseCases · TransitionService · ApprovalEngine client
Domain         Eloquent models + Value objects (Money/Qty/Rate) · Domain events
Services       AccountingPostingService · StockLedgerService · NumberingService
               DocumentService · WarrantyService · NotificationDispatcher
               AuditRecorder · IntelligenceEngine · ReportQueryServices
Infrastructure MySQL · Queue · Cache(read-only) · Storage disks · Provider adapters
```

### 1.3 Canonical mutation lifecycle (enforced, not aspirational)

```
USER ACTION → ContextResolver (instance/company/branch/user/scope)
→ Authorizer (policy+permission+scope) → Validator (FormRequest + domain invariants)
→ Approval decision (ApprovalEngine: bypass / route / reject)
→ DB transaction { idempotency guard, row locks, TransitionService,
                   AccountingPosting (if posted), StockLedger (if stock-bearing) }
→ committed DomainEvent → Outbox row (same transaction)
→ after-commit queued side effects (notifications, PDFs, search index, caches)
→ AuditRecorder event → Intelligence signal
```

### 1.4 Repository layout

```
/erp            Laravel 13 business plane
  app/Domain/{Sales,Purchase,Inventory,Accounting,Workflow,Hr,Service,Warranty,
              Marketing,BusinessMgmt,Reporting,Intelligence,Notification,Audit,
              Security,Documents,Settings,Foundation}/
  app/.../Actions · Services · Policies · Events · Listeners · Jobs · Providers
  database/migrations · seeders (system-only) · factories (tests-only)
  resources/{views,css,js,i18n} · routes/{web,api,public}.php
/platform       Laravel control plane (separate DB)
/docs           ARCHITECTURE.md · TRACEABILITY/ · IMPLEMENTATION_STATUS.json
/mockups        static visual reference only (never shipped as the app)
```

### 1.5 Request taxonomy

| Class | Example | Transport | Error contract |
|---|---|---|---|
| Page navigation | invoice list | full HTML render | polished 401/403/404/419/429/500 Blade pages |
| Internal AJAX | customer invoice loader | fetch JSON, CSRF + origin check | `{ok:false, error:{code,message,fields}}`, never stack traces |
| Public | QR verification, public doc link | throttled guest routes | policy-filtered payload, generic errors |
| Queue | SMS send, PDF build | workers | retry policy per job class, outbox state |

---

## 2. Module Dependency Architecture

### 2.1 Dependency graph (arrows = "may depend on")

```
Foundation (config, settings, i18n, RBAC/scope, audit, idempotency, search)
    ▲
Engines: Workflow · Accounting · Inventory · Documents(PDF/QR/links)
         · Notification(outbox) · Intelligence · Warranty
    ▲
Masters: units/categories/brands/attributes · customers · suppliers ·
         products · COA · branches/warehouses · geo/holiday masters · tax rules
    ▲
Verticals: Sales · Purchase · Cash&Bank · Returns · Employee/HR ·
           Technician/Service · Marketing · Business Management
    ▲
Consumers: Dashboard(25 widgets) · Reports · Search · Settings UI · Maintenance
```

### 2.2 Hard rules

- Verticals never talk to each other's tables directly; cross-vertical effects go through the target vertical's Action + Engine (e.g., technician sale → `Sales\Actions\CreateInvoiceFromService`).
- Engines never depend on Verticals (Engines receive typed commands/events).
- Only Accounting writes `journal_*`. Only Inventory writes `stock_movements`. Only Workflow writes `approval_*`. Only Audit writes `audit_events`. Only Notification writes `outbox_*`.
- `platform/` shares zero tables with `erp/`.
- Module registry (`modules` table) + `menu_items` define navigational coverage; permission keys are the authorization contract; both are seeded as system data.

### 2.3 Module list (15) with engine dependencies

| # | Module | Depends on engines | Owns |
|---|---|---|---|
| 01 | Dashboard | Intelligence, Reporting | widget registry/definitions, widget queries |
| 02 | Sales | Workflow, Inventory, Accounting, Documents, Notification, Warranty, Intelligence | quotations→orders→POS→invoices→delivery→pricing→coupons→sales team |
| 03 | Purchase | Workflow, Inventory, Accounting, Documents, Notification | PR→RFQ→PO→GRN→bill→supplier payments→returns→LC |
| 04 | Inventory | Inventory engine, Workflow, Accounting, Documents | products, stock, batch/serial, warehouses, damage, barcode, reorder, packaging |
| 05 | Customers | Accounting(ledger reads), Workflow | customer master, dues, collection, EMI, statements |
| 06 | Suppliers | Accounting(ledger reads) | supplier master, dues, contracts, performance |
| 07 | Returns | Workflow, Inventory, Accounting, Warranty, Documents | sales returns, exchange, refunds, warranty claims |
| 08 | Cash & Bank | Accounting, Workflow, Documents | cash sessions, banks, wallets, cheques, expenses, petty cash |
| 09 | Accounting | Accounting engine | COA, journals, AR/AP, VAT/TAX, reports, assets, budgets, closing |
| 10 | Employee | Workflow, Documents, Notification, Accounting(posting) | HR master, attendance, leave, payroll, acknowledgment |
| 11 | Marketing | Notification (as channel), Intelligence | campaigns, channels, leads, affiliates, promotions analytics |
| 12 | Business Management | Documents, Notification, Workflow | company/branch, compliance, docs vault, meetings, notices, tasks, assets, utilities, visitors |
| 13 | Reports | Reporting services (read-only) | report catalog, scheduled/saved reports |
| 14 | Masters | Foundation | shared master data (UOM, zones, banks, couriers, rates…) |
| 15 | Settings | Foundation, Security, Documents, Notification | settings engine, users/roles, tax, security, backup, maintenance |

Plus: **Service/Technician domain** (portal + jobs) is a cross-vertical domain hosted under module 10/07/02 boundaries per menu mapping (traced in `TRACEABILITY/10-employee.md` and `16-cross-cutting.md`), and **Platform Control Plane** is out-of-band (traced in `16-cross-cutting.md`).

---

## 3. Database / Domain Architecture

### 3.1 Schemas

- `erp_platform` — control plane only (§4.12).
- `erp_inst_*` — one per instance; contains all 15-module tables.
- Storage namespaces: `storage/app/instances/<slug>/{private,public,documents,backups}` (disks: `instance-private`, `instance-public`, `instance-documents`).

### 3.2 Invariants enforced by DB + code (never configurable off)

- FK integrity (`FOREIGN KEY` on all relations), `ON DELETE` conservative (RESTRICT for financial history, CASCADE only for pure child drafts).
- `UNIQUE(companies.id)` semantics: single active company row.
- `CHECK (debits >= 0 AND credits >= 0)` on journal lines; application asserts `SUM(debits)=SUM(credits)` pre-insert and DB `posting_checksum` column stores the asserted totals for post-hoc verification.
- Unique document numbers: `UNIQUE(numbering_sequences…)`, `UNIQUE(invoices.number)`, etc. scoped by `(company_id, doc_type, branch_id, fiscal_year)`.
- Unique idempotency: `UNIQUE(idempotency_keys.scope, key)`.
- Non-negative stock: balance rows `CHECK (qty_on_hand >= 0)` where `allow_negative_stock = false` (branch setting) — enforced by row lock + check.
- Secure token entropy: tokens never stored raw.
- Append-only guards: triggers where permitted, otherwise restricted grants (D19/D24).

### 3.3 Migration strategy

Migrations are additive-first, backward-compatible within a release; destructive changes use expand→migrate→contract. Every phase runs `migrate` under maintenance gate + smoke checks (spec §43 phase checklist).

---

## 4. Complete Entity & Relationship Design

(★ = also carries `company_id`; ⊕ = also carries `branch_id`.)

### 4.1 Foundation & tenancy
`companies` (exactly 1) · `branches` ⊕ (code, address, contact, operating_status) · `branch_settings` ⊕ · `branch_user` (user↔branch, scope_type: single/multi/all) · `users` · `roles` · `permissions` (stable key + translatable label) · `role_user` · `permission_role` · `user_permission` (direct grants/denials) · `menus` · `menu_items` (hierarchy, module_id, route, icon, sort) · `menu_item_permission` · `modules` · `widgets` (25 defs) · `widget_permission` · `widget_user_visibility` · `settings` (key, group, value, type, scope: global/company/branch, branch_id, secret_flag, encrypted_value) · `setting_history` · `translations` (key, locale, value, context) · `locales` · `currencies` · `exchange_rates` (effective-dated) · `statuses` · `status_transitions` · `document_types` (code, **printed_title**, template_id, tax_inclusive) · `numbering_rules` ⊕ · `numbering_sequences` ⊕ · `idempotency_keys` · `search_index` · `districts` · `upazilas` · `holidays` (BD calendar).

### 4.2 Workflow / approval engine
`workflow_definitions` (entity_type, action, scope, active, version) · `workflow_versions` · `workflow_triggers` · `workflow_conditions` (json rule tree: amount/branch/department/role/product/customer/supplier/type/history/risk) · `workflow_states` · `workflow_transitions` · `approval_requests` (morph approvable, snapshot_json, revision, status, sla_due_at) · `approval_steps` (order, mode: seq/parallel, due_at) · `approver_rules` (role/user/manager-of/delegation target) · `approval_step_approvers` · `approval_decisions` (approve/reject/return, comment, decided_at) · `approval_comments` · `approval_attachments` (→ documents) · `approval_delegations` · `approval_escalations` · `approval_reminders` · `approval_history`.
Relationship: `workflow_definition 1—n versions 1—n triggers/conditions/states/transitions`; `approval_request n—1 definition`, `1—n steps`, `1—n decisions`; approvable is morph (`entity_type`,`entity_id`) + frozen `snapshot_json`.

### 4.3 Accounting
`account_groups` · `accounts` (COA: code, type, control flags, currency) · `fiscal_years` ⊕ · `fiscal_periods` ⊕ · `journal_entries` ⊕ (entry_no via numbering, fiscal_period, source_type/source_id, posting_state, reversal_of_id, posted_by, posted_at, checksum) · `journal_lines` ⊕ (account_id, dc, amount, party_type/party_id, cost_center_id, dimension values, narration) · `dimensions`/`dimension_values` · `cost_centers` ⊕ · `budgets`/`budget_lines` ⊕ · `posting_rules` (event_type → ordered account-role assignments, effective-dated, per doc type/branch) · `payment_allocations` ⊕ (payment↔invoice/bill, amount) · `bank_reconciliations`/`reconciliation_lines` ⊕ · `bank_statement_imports`/`bank_statement_lines` ⊕ · `cheques` ⊕ (lifecycle status) · `cash_sessions` ⊕ (open/close counts, variance) · `cash_count_lines` ⊕ · `petty_cash_funds` ⊕/`petty_cash_transactions` ⊕ · `fixed_assets` ⊕ · `asset_depreciation_runs`/`_lines` ⊕ · `asset_disposals` ⊕ · `loans` (customer/supplier/employee advances) · `loan_repayments` · `emi_schedules` (customer EMI) · `recurring_journals` · `opening_balances` (journal header marker) · `closing_entries` (retained earnings) · `tax_rules` (rate, type, effective_from/to, applicability json, doc_template_version, account mapping, source_note, active) · `tax_groups` · `tax_documents` (Mushak 6.1/6.2/6.3/6.4/6.5/6.6/9.1/11 register/return snapshots) · `tds_registers`/`ait_registers` · `currency_gain_loss` (auto lines).
Key relations: `journal_entry 1—n journal_lines`; `journal_line n—1 accounts`, `n—1 cost_centers`; `payment_allocations n—1 invoices` and `n—1 payments`; party balance = derived over `journal_lines` where controlling account + party set; `running_balances` is a derived table with rebuild tool.

### 4.4 Inventory
`products` ★⊕(scope flags: company-wide vs branch) · `product_categories` (tree) · `brands` · `attributes`/`attribute_values` · `product_variants` · `variant_attribute_values` · `units` · `unit_conversions` · `product_files` (images/docs → documents) · `price_lists` ⊕ · `price_list_items` · `pricing_rules` (customer_group/quantity_break/geographic/time-based/promo, effective-dated, priority) · `product_reviews` · `product_cross_sell_links` · `warranty_policies` (product/category level: enabled, duration, unit, start_event, terms, exclusions, service role) · `shipping_configs` · `product_settings` · `warehouses` ⊕ · `warehouse_zones` ⊕ · `bins` ⊕ · `product_bins` · `batches` ⊕ (lot, expiry) · `serials` ⊕ (status lifecycle) · `stock_movements` ★⊕ (immutable: product/variant, warehouse, bin, batch, serial, movement_type, qty_signed, unit_cost, valuation_method, layer_ref, source_type/source_id, occurred_at, actor, idempotency_key) · `stock_balances` ★⊕ (derived cache: on_hand, reserved, in_transit, damaged, quarantined; rebuildable) · `stock_layers` ★⊕ (FIFO/LIFO/WAC layers: qty_remaining, unit_cost) · `stock_reservations` ★⊕ (order line, expiry) · `stock_transfers`/`_lines` ★⊕ (dispatch/receive/discrepancy states) · `stock_adjustments`/`_lines` ★⊕ · `cycle_counts`/`_lines` ★⊕ · `damage_loss_records` ★⊕ (write-off approval → accounting) · `insurance_claims` · `reorder_policies` ⊕ (min/max, ROP, safety stock, lead time, qty) · `reorder_suggestions` ⊕ (BI output, never auto-PO) · `packaging_types` · `packaging_usage`.
Movement types (reference data): OPENING, PURCHASE_RECEIPT, SALES_ISSUE, SALES_RETURN_IN, PURCHASE_RETURN_OUT, TRANSIT_OUT, TRANSIT_IN, ADJUST_IN/OUT, WRITE_OFF, DAMAGE_OUT, PACK_CONSUME, POS_ISSUE, PRODUCTION (future).

### 4.5 Masters — customers & suppliers
`customers` ★⊕ (type, group, credit_limit, blacklist, tin/bin) · `customer_groups` (group discounts) · `customer_addresses` (geo FK) · `customer_contacts` · `customer_credit_history` · `customer_feedback` · `customer_referrals` · `customer_wishlists` · `promise_to_pay` · `legal_notices` · `collection_assignments`/`collection_followups` · `suppliers` ★⊕ · `supplier_categories` · `supplier_contacts` · `supplier_bank_details` (encrypted) · `supplier_contracts` · `supplier_documents` · `supplier_performance_scores` (on-time, quality) · `supplier_settings`.

### 4.6 Sales documents
`quotations` ★⊕ (number, revision_of, valid_until, workflow_state, posting_state) / `_lines` · `sales_orders` ★⊕ (source quotation_id, approval state, fulfillment state: pending→confirmed→processing→ready→picked→in_transit→out_for_delivery→delivered→completed / cancelled / return_* / suspicious flag) / `_lines` (ordered_qty, delivered_qty, invoiced_qty — supports partials) · `shipments` ★⊕ / `_lines` · `tracking_events` · `delivery_challans` ★⊕ / `_lines` (serial/batch capture, POD status, courier/rider, dispatch/received ts) · `invoices` ★⊕ (document_type_id → printed title, tax_applicable flag, totals: subtotal, doc_discount, taxable_base, tax, shipping, rounding, grand_total, paid, due, qr_token_hash, verification state) / `_lines` (line discount, tax group, warranty flag) · `credit_notes`/`_lines` ★⊕ · `debit_notes`/`_lines` ★⊕ · `payments` ★⊕ (receipts: method/account, approval, allocation) — allocation via `payment_allocations` · `sales_returns`/`_lines` ★⊕ (inspection status, restock disposition) · `refunds`/`_lines` ★⊕ · `exchanges`/`_lines` ★⊕ · `return_reasons` (master) · `delivery_zones` ⊕ · `zone_charges` ⊕ · `courier_partners` (adapter config, credentials encrypted) · `rider_profiles` (employee link) · `rider_assignments` · `rider_cod_collections` · `cod_reconciliations` · `proof_of_deliveries` · `failed_deliveries` · `manifests`/`manifest_lines` · `shipping_labels` (generated docs) · `packaging_plans` · `sales_targets` (daily/monthly/yearly, employee) · `commission_rules`/`commission_calculations`/`commission_payments` · `sales_call_logs` · `field_visits` (GPS points) · `beat_plans` · `territories` · `coupons` (type: percent/fixed/free-shipping/buy-x-get-y, limits) · `coupon_usages` · `promotions`/`promotion_items` (seasonal, flash sale w/ window) · `pos_sessions` ★⊕ (opening float, closing count, X/Z reports) · `pos_transactions` (offline sync: client_uuid idempotency, conflict state) · `pos_holds` · `pos_settings` ⊕ · `suspicious_order_flags` (BI origin, manual review).

### 4.7 Purchase documents
`purchase_requests`/`_lines` ★⊕ · `rfqs`/`_lines` ★⊕ · `supplier_quotations`/`_lines` ★⊕ · `rfq_comparisons` (saved snapshot) · `purchase_orders` ★⊕ (amendment_of, sent state, receipt state, approval) / `_lines` (received_qty, billed_qty) · `grns` ★⊕ / `_lines` (condition, batch, expiry, bin, short/excess qty) · `purchase_bills` ★⊕ / `_lines` (match_state: 3-way PO↔GRN↔Bill tolerance results) · `purchase_returns` ★⊕ / `_lines` (+ auto credit/debit note links) · `return_authorizations` · `lc_records` ★⊕ (LC no/date, HS code, customs duty, clearing agent) · `landing_cost_items` (duty/freight/insurance → capitalized cost) · `supplier_payment_schedules` · `beftn_batches`/`beftn_lines` (file generation) · `import_batches` (CSV import jobs + history).

### 4.8 Returns, warranty, service
`warranty_claims` ★⊕ / `claim_events` · `warranties` ★⊕ (instances: product/variant, serial/batch, invoice_line_id, delivery_id, order_id, customer_id, start_date, end_date, duration, status active/expired/void, activated_at, activation_key UNIQUE for idempotency) · `service_jobs` ★⊕ (source: warranty claim/manual/AMC) · `service_job_assignments` ★⊕ (technician, state: assigned→accepted/declined/busy, reason) · `technician_location_states` (current + last **approved** sharing state, consent record) · `service_visits` ★⊕ (arrival/departure, geo) · `service_work_notes` · `service_parts_used` (links stock issue → sale line candidates) · `tool_requisitions` ★⊕ (operational record, NEVER auto invoice line) · `technician_requisitions` ★⊕ · `customer_signoffs` (signature file → documents) · `discount_requests` (→ ApprovalEngine) · `service_invoices` link table (`invoice.service_job_id`).

### 4.9 Employee / HR / payroll
`employees` ★⊕ (user_id optional, branch scope) · `employee_documents` · `employee_bank_details` (encrypted) · `id_cards` · `departments` · `designations` · `salary_grades` · `salary_grade_steps` · `service_book_entries` · `work_shifts` ⊕ · `attendance_records` ★⊕ (status: present/late/absent/**unapplied_absence**/on_leave/holiday, override audit) · `attendance_overrides` · `gps_config` (settings rows) · `leave_types` (master) · `leave_requests` ★⊕ (→ ApprovalEngine) · `leave_balances` ★⊕ (per type/period) · `leave_encashments` · `payroll_runs` ★⊕ (period, state: draft→approved→paid→ack_pending→acknowledged) · `payroll_items` ★⊕ (earning/deduction lines, formula eval) · `deduction_rules` ★ (DB-driven formula, effective date, actor) · `deduction_notifications` (breakdown) · `payroll_acknowledgements` ★⊕ (employee, acknowledged_at, device/session meta, payroll_ref — **DB-persisted**) · `overtime_requests` ★⊕ · `employee_loans` ★⊕ / `loan_repayments` / `emi_schedules` · `bonuses` (festival/Eid/performance) · `sales_commission` (link) · `kpi_definitions`/`performance_reviews`/`self_assessments` · `trainings`/`training_certificates` · `job_postings`/`applications`/`interviews`/`offer_letters` · `disciplinary_actions` · `hr_policies` · `gratuity_provisions` · `pf_accounts`/`pf_transactions` · `exits` (resignation, experience letter, exit settlement).

### 4.10 Marketing, business management, notifications, BI, audit, ops
`campaigns` (channel-agnostic) · `campaign_segments`/`segments` · `message_templates` (channel, locale, placeholders) · `messages` (campaign/transactional, state per D12) · `campaign_metrics` (provider-verified or local-tracked only) · `leads`/`lead_activities`/`lead_followups`/`lead_scores` · `affiliates` · `influencers`/`influencer_campaigns` · `social_integrations`/`pixel_configs` · `nps_responses` · `referrals`.
`company_documents` · `certificates` · `compliance_items` (calendar: trade license/TIN/BIN/RJSC/labour/insurance, reminders) · `document_library`/`contracts`/`agreements`/`brand_assets` · `meetings`/`meeting_minutes`/`action_items` · `notices`/`notice_acknowledgements` · `projects`/`tasks`/`task_comments` · `business_assets`/`vehicles`/`vehicle_trips`/`equipment` · `utility_providers` (DESCO/DPDC, WASA, TITAS, internet) · `utility_bills`/`renewal_reminders` · `visitors`/`visitor_registrations`.
`notification_rules` (event→channel→audience, DB-driven) · `outbox_messages` ★⊕ · `delivery_logs` · `provider_configs` (settings rows, encrypted) · `notifications` (Laravel DB notifications).
`documents` (metadata: type, owner morph, visibility private/public, checksum, size, mime, uploader, version, soft delete) · `document_versions` · `document_links` (token_hash, scope, revoked_at, rotated_from) · `public_access_logs` · `print_history` (doc, user, at).
`audit_events` ★⊕ (D19 fields: seq, prev_hash, row_hash, correlation_id, actor, role_at_time, action, entity_type/id, doc numbers, before/after redacted snapshots, amount, occurred_at, timezone, ip, ua, device/session, route, method, outcome, reason) · `audit_archives` · `security_alerts` (severity, routing) · `auth_events`.
`bi_rules` (conditions/operators/thresholds/schedule/action/branch scope/effective dates/active) · `bi_rule_runs` (execution log) · `bi_recommendations` (detected, period, sample_size, method, threshold, confidence, action, reason) · `bi_anomalies` · `forecast_snapshots` · `bi_metrics_daily` (materialized aggregates).
`report_definitions` · `saved_filters` · `scheduled_reports` · `report_runs`.
`backups` (type db/files/documents/config/audit, size, checksum, status, verification_state, retention_state, restore_test_state) · `maintenance_events` · `system_heartbeats` (scheduler/queue/disk/db) · `self_healing_actions` (allowed-action catalog + log) · `error_logs_index`.

### 4.11 Platform control plane entities (`erp_platform`)
`platform_admins` · `platform_roles`/`platform_permissions` · `instances` (id, **slug random non-sequential**, db_name, storage_ns, status: provisioning/active/grace/overdue/suspended/deletion_pending/deleted, health_state, backup_state, storage_used, created_at) · `instance_admins` (contact only, no tenant powers) · `packages`/`package_entitlements` (feature flags, limits: users/branches/storage) · `subscriptions` (instance, package, period, renewal_date, state) · `platform_payments`/`payment_receipts` · `instance_health_checks` · `instance_backup_status` · `deletion_requests`/`deletion_decisions` (workflow, dual control) · `tenant_notifications` (instance lifecycle) · `platform_audit_events` (hash-chained).

### 4.12 Relationship summary (core chains)

```
company 1—n branches 1—n warehouses/bins
users n—n roles n—n permissions ; users n—n branches
quotation n—n sales_order (convert) ; sales_order 1—n shipment/delivery_challan/invoice (partial)
invoice 1—n payment_allocations n—1 payments ; invoice 1—n credit_notes/debit_notes
delivery(challan delivered event) 1—n warranty instances 1—n warranty_claims 1—n service_jobs
grn n—1 purchase_order ; purchase_bill n—1 grn (3-way) ; payment n—1 purchase_bill
source document 1—n journal_entries (posting events) 1—n journal_lines n—1 accounts
source document 1—n stock_movements ; stock_movements 1—n stock_layers consumption
entity n—1 workflow_definition ; approval_request 1—n steps 1—n decisions
everything 1—n audit_events ; everything 1—n search_index ; events 1—n outbox_messages
```

---

## 5. RBAC + Permission + Branch-Scope Architecture

### 5.1 Permission model

- Permission key format: `<module>.<resource>.<action>` — stable in DB, labels translatable.
- Action vocabulary (exactly the spec list): `view, create, edit, delete, approve, reject, cancel, print, download, export, import, assign, process, post, refund, reconcile, configure, view_sensitive` + scope grants `view_branch, view_all_branches`.
- Three grants layers: `permission_role` → `user_permission` (direct grant/deny, deny wins) → scope filter.
- Seeded roles: Super Admin, Admin, Manager, Employee, Technician — rows in `roles`, never hardcoded in conditionals.

### 5.2 Branch scope

- `ScopeContext {companyId, branchIds[] | ALL, userId, permissions[], flags}` resolved per request from DB (never from client input beyond a validated branch selector checked against `branch_user`).
- Enforcement at four layers, all mandatory:
  1. `BranchScope` Eloquent global scope on branch-sensitive models.
  2. Query/report services receive `ScopeContext` explicitly; SQL always filtered.
  3. Policies: `viewAny`/`view` check scope + permission; IDOR impossible (record fetched within scope, 404 on out-of-scope).
  4. Menu/widget/API visibility uses the same context — hiding is UX only.
- Report exports, search, bulk actions, print/download, AJAX lookups all pass through the same scope filter — tested per spec §42.

### 5.3 UI action rendering contract

A rendered action button is computed server-side from: permission ∧ record status ∧ branch scope ∧ workflow state ∧ ownership ∧ SoD rule ∧ business conditions. One dominant primary action per state (context-aware action bar: primary / secondary / destructive / overflow). Unavailable-but-permitted actions show a reason tooltip; unauthorized actions are absent. Server re-validates every action.

---

## 6. Generic Approval / Workflow Engine Architecture

Single engine (tables §4.2) used by: sales orders, discounts/price overrides, purchase orders, purchase payments, returns/refunds, stock adjustments/transfers/write-offs, expenses, leave, overtime, salary/payroll, salary deductions, loans/advances, journal entries, asset actions, service actions, supplier payments, bad-debt write-offs, deletion requests (platform plane uses an analogous implementation in its own DB).

Flow: entity Action calls `ApprovalEngine->submit(entity_type, action, entity, snapshot, context)`:
1. Match `workflow_definitions` by entity/action/scope, evaluate `workflow_conditions` (amount min/max, %, branch, dept, role, product, customer, supplier, prior history, risk) → highest-priority active definition (versioned).
2. If no match → `bypass` (still audited). Else create `approval_request` with **frozen snapshot_json + revision**.
3. Instantiate `approval_steps` from `approver_rules` (role-based, user-specific, manager-of, delegate), sequential or parallel per definition.
4. Step resolution: eligible approver set minus maker (SoD/self-approval block when `block_self_approval`), delegation substitution.
5. Decision → comment/attachment recorded → next step or outcome:
   - approved → outcome event to the owning Action (state transitions, posting) + notifications;
   - rejected → entity state `rejected` with reason;
   - returned → entity becomes editable; **material change detection** (hash of approved snapshot vs new revision) invalidates prior approval and restarts as configured.
6. SLA `due_at` per step → `approval_reminders`/`approval_escalations` scheduled jobs → notifications.
7. Full `approval_history` + audit events for submit/decide/delegate/escalate/expire.

Concurrency: decisions take row lock on `approval_requests`; duplicate approval click → idempotent no-op. Every decision is maker-checker testable (spec §42).

---

## 7. Accounting Architecture

### 7.1 Core

- Real double-entry. `JournalPostingService::post(PostingContext)` is the **only** code path creating `journal_entries` (manual journals included, via the same service).
- Balance invariant: service computes lines, asserts Σdebits = Σcredits (BCMath), stores `checksum`, inserts within transaction that locks the `fiscal_periods` row (serializes same-period posting and prevents period-closed posting).
- Fiscal years/periods gate posting dates; closed periods reject posting (reversal must land in open period or via reopening workflow).

### 7.2 Source-document driven posting

`posting_rules` map event types (`sales_invoice_issued`, `sales_return`, `receipt`, `expense`, `purchase_bill`, `supplier_payment`, `stock_valuation`, `payroll_payment`, `depreciation`, `write_off`, `tax_payable`, `fx_gain_loss`, `opening_balance`, `closing_retained_earnings`, …) to ordered account roles with resolution by company/branch/doc-type/currency, effective-dated. Controllers never contain account codes.

Conceptual effects (per spec): invoice → Dr AR/Cash, Cr Sales (+Tax payable); perpetual cost → Dr COGS, Cr Inventory; receipt → Dr Cash/Bank, Cr AR; bill → Dr Inventory/Expense/Asset, Cr AP; supplier payment → Dr AP, Cr Cash/Bank; expense → Dr Expense, Cr Cash/Bank/AP.

### 7.3 Immutability & correction

`posting_state = posted` entries: no edit/delete endpoints exist; corrections via (a) **reversal** (`reversal_of_id`, auto-balanced mirror in open period), (b) **credit/debit notes**, (c) adjustment journals. All correction paths require permissions + approval where configured + audit.

### 7.4 Ledgers & balances

- Party (customer/supplier) and account ledgers are projections over `journal_lines` (controlling account + party dimension). `payment_allocations` provide invoice-level settlement truth.
- `running_balances` derived table for display speed, with **Rebuild/Reconcile** operation that recomputes from source entries (mandatory after backdated postings) and a reconciliation report comparing derived vs source.
- Printable ledger/statement documents via §19 pipeline.

### 7.5 Cash/bank specifics

Cash accounts, bank accounts, and mobile wallets (bKash/Nagad/Rocket/Upay) are `accounts` rows with instrument metadata. Cash sessions (open/close/count/variance), petty cash, cheque lifecycle, bank reconciliation with statement import matching (manual reconciliation core; API integrations optional adapters), bank charge auto-posting rules.

---

## 8. Inventory / Stock-Ledger Architecture

- **Source of truth**: immutable `stock_movements`. `stock_balances` is a derived cache with `rebuild_from_ledger()` and periodic reconciliation job.
- Every mutation = `StockLedgerService::post(MovementCommand)` inside transaction:
  1. lock balance row(s) (`SELECT ... FOR UPDATE`);
  2. negative-stock guard per branch setting (`CHECK` + lock = no oversell race);
  3. cost layer handling per method (FIFO/LIFO/WAC/Standard): consume/create `stock_layers`, compute `unit_cost`;
  4. insert movement (source doc, actor, idempotency key);
  5. update balance by state (on_hand, reserved, in_transit, damaged, quarantined).
- Reservations: order approval reserves; invoice/delivery consumes reservation; cancellation releases — prevents oversell of committed stock.
- Transfers: `TRANSIT_OUT` at dispatch (origin), `TRANSIT_IN` at receipt (destination), discrepancy lines → adjustment workflow.
- Perpetual valuation: layer costs feed COGS/Inventory journal lines via `posting_rules` when method = perpetual; reconciliation report `stock_value ↔ inventory GL balance`.
- FEFO picker for expiry-managed goods; batch/expiry enforced where product requires; serials have lifecycle (in_stock, sold, reserved, in_transit, returned, void) and unique-per-product constraints.
- Reorder: nightly job computes suggestions from on_hand, reserved, incoming (open POs), average demand (moving average window), lead time, safety stock, ROP, configured qty → `reorder_suggestions` (suggestion only; auto-PO is an explicit configured action with approval).
- Adjustment/write-off/damage/loss require approval (amount-based rules) → posting creates GL loss entries.

---

## 9. Sales / Purchase Document Lifecycle Architecture

### 9.1 Sales flow

```
Quotation ─convert→ Sales Order ─[approval if required]→ confirmed
  → Processing → Shipment → Delivery/Challan (POD) → Billing ─[invoice approval]→
  Issued Invoice → Payment/Allocation → Completion
```

- Each stage is a **separate document** with its own number, state, and lines; links are references, never mutation of one record into another.
- Partial delivery & partial invoicing: line-level `ordered/delivered/invoiced` quantities; over-delivery blocked unless explicitly allowed.
- Sales Order on save: commitment only — **no GL, no stock movement** (reservation only). Posting events fire at configured stages (delivery → stock out; invoice → GL).
- Cancel of posted docs → reversal/compensating entries; draft docs → status change + audit.
- Pricing resolves deterministically in `PricingService`: base price list → customer group → quantity break → geographic → time-based → promotion/coupon → authorized manual override (override permission + workflow; discount-edit permission gates invoice doc discount).
- POS: sessions with float, hold/resume, returns/exchange, quotation, layaway deposit, price check, customer display, drawer in/out, X/Z reports. Offline mode: service worker + IndexedDB queue, each mutation carries client UUID → server idempotency; conflicts surfaced explicitly; server remains authoritative for stock/GL (§18.7).
- Suspicious/fake orders: BI flags → review queue; **never auto-delete**.

### 9.2 Purchase flow

```
Purchase Request ─approval→ RFQ → supplier quotations → comparison (saved snapshot)
  → select winner → PO ─approval→ sent → GRN (condition/batch/expiry/bin, short/excess)
  → Purchase Bill → 3-way match (PO↔GRN↔Bill, tolerance from settings)
  → supplier payment → ledger
```

- Draft PR/PO: no stock, no liability. Stock impact at GRN receipt; liability at bill posting.
- Amendments: new revision + approval restart (snapshot invalidation); duplication creates new doc with provenance.
- Supplier returns → return authorization → stock out + credit/debit note effect on supplier ledger.
- LC/import: landing cost components (duty, freight, clearing, insurance) capitalize into item cost via posting rules.

### 9.3 Money Receipt flow (exact spec steps)

Customer select → AJAX loads open invoices (number/date/total/paid/due) → NEW/OLD derived from first allocation date in `payment_allocations` (no hard-coded text) → multi-select → received amount → exact/partial allocations → overpayment per setting (advance on account) → method/account → approval if required → atomic posting (Dr Cash/Bank, Cr AR + allocations) → printable Money Receipt (concurrency-safe receipt number via D9).

---

## 10. Technician / Service Architecture

- `service_jobs` created from warranty claims, AMC/manual requests, or sales service events; branch-scoped.
- Assignment: dispatcher assigns → technician `accept / decline / busy` with reason+note persisted; state machine on `service_job_assignments`; notifications via outbox; reassignment audited.
- Location sharing: explicit consent records; only **current/last approved** sharing state visible; revocation immediate; access audited; no always-on tracking without approval.
- Visits: arrival/departure events (geo optional per policy) → travel/time records for performance.
- Work: diagnosis/work notes, attachments (secure upload pipeline §13), parts used (each part = stock issue request → `StockLedgerService` movement → optional sale line via Sales action), tools requisition (`tool_requisitions`, fulfilled from warehouse — **operational record only, never injected into customer invoice lines**), additional-technician requisition (`technician_requisitions`).
- Customer approval/signature where configured (`customer_signoffs`).
- Discount request from technician → `ApprovalEngine` (never direct mutation); on approval, originating service bill updates immediately via persisted state + AJAX refresh.
- Billing: `service_invoices` link → `Sales\Actions\CreateInvoiceFromService` produces a normal Invoice (title D10) with service + parts lines.
- Completion: checklist validation (required notes/signoff/parts) → job `completed` → warranty claim closure + service history on warranty instance.
- Technician portal: only assigned jobs, authorized customer/product/serial fields, own performance; enforced by policy scope — no unrelated company data.

---

## 11. Warranty / QR Verification Architecture

### 11.1 Warranty

- Config: `warranty_policies` on product/category/variant (enabled, duration+unit, start_event default `delivery_confirmed`, branch/customer eligibility, serial/batch requirement, terms, exclusions, assigned service role).
- Activation: listener on `DeliveryConfirmed` domain event → for each eligible invoice line: create `warranties` row with `activation_key = hash(invoice_line_id|serial|delivery_id)` UNIQUE → exactly-once idempotent activation (D18), start = delivery event datetime (instance timezone), end = start + duration. Activation **never** triggers on quotation/order/draft invoice.
- Remaining days computed from dates at read time (never stored counters); status derived active/expired/void (void on returned/cancelled delivery per policy → cancellation effect).
- Claims: `warranty_claims` link instance+customer+job; claim history stored on instance; invoice prints warranty badge + duration when configured.

### 11.2 QR + public verification

- On invoice issue: generate 32-byte token (D11), store SHA-256 hash on invoice + `document_links` row; QR payload = absolute URL `/verify/{token}`.
- Public page (throttled per IP+token): result, invoice no/date, company/branch identity, policy-filtered customer display, product list, serial/batch (if permitted), per-product warranty status + start/end + remaining days, paid/partial/due, payment status, creator display name, issue/posting state, download link.
- Download link = separate `document_links` token (persistent, revocable/rotatable, hashed at rest), renders a **public-safe** document view (no internal notes/audit/PII beyond policy).
- Accesses written to `public_access_logs` (token hash prefix, IP, UA, outcome) + rate-limited (`RateLimiter` buckets); log view itself audited. Never exposes sequential IDs — routes resolve only by token.

---

## 12. Notification / Outbox / Queue Architecture

```
DomainEvent (in transaction)
  → outbox row (channel-agnostic intent + template key + locale variants)
  → COMMIT
  → after-commit Job → NotificationDispatcher
      → resolve notification_rules (DB) → render template (placeholders:
        {customer_name} {supplier_name} {order_no} {invoice_no} {amount} {due}
        {branch_name} {verification_url} {document_url} …) in EN/BN
      → provider adapter (Sms/Email/WhatsApp/Push) selected from settings
         ├─ credentials present → send → provider accepted? state=provider_accepted
         │                          → rejected/error → provider_failed (+retry policy)
         └─ absent → state=disabled_provider + reason (UI shows "not configured")
  → delivery_logs (attempt, latency, provider ref) → audit where sensitive
```

- Order/PO notification semantics per spec: pending-approval wording ≠ confirmation; separate configurable "confirmed" notification on approval; PDF snapshot generated by document engine and attached when provider supports attachments.
- In-app `notifications` (Laravel DB) for users + persistent overlays (e.g., salary acknowledgment) reading **DB state**, never JS-only state.
- Queue: database/redis driver; job classes idempotent; retries exponential; poison messages → `failed_jobs` + BI maintenance rule (failed queue spike alert).
- Marketing sends reuse the same outbox with campaign audience expansion, opt-out checks, and cost tracking; metrics only from provider webhooks or local events (no fake opens/clicks).

---

## 13. Security Architecture

| Threat | Control |
|---|---|
| SQLi | Eloquent/query builder only; no raw concatenation; bindings; tests with payload probes |
| XSS | Blade escaping `{{ }}`, `{!! !!}` audited, CSP headers, JSON served with safe encoding |
| CSRF/forgery | Laravel CSRF + `VerifyCsrfToken` + SameSite cookies + origin check on AJAX |
| SSRF | outbound HTTP only via `Http` client with allow-list of configured provider hosts |
| Path traversal | no user path segments; generated filenames (UUID/hash) stored; storage disks only |
| Unsafe uploads | authz → extension+MIME+content sniff → size limit → safe name → private disk → checksum → optional AV/CDR hook → WebP/thumbnail derivatives → served via controlled route |
| Mass assignment | explicit `$fillable`/`$guarded` + FormRequest per endpoint |
| IDOR | scope-filtered fetch inside policies; 404 for out-of-scope |
| Privilege escalation | permission changes require `settings.users.permissions.edit` + password confirmation + audit; deny-wins semantics |
| Session fixation/theft | regenerate on login, secure/httpOnly/sameSite cookies, session timeout + concurrent-session cap from settings, device/session list + revoke |
| Brute force/stuffing | login throttling, per-IP+per-account limiters, lockout policy, credential-stuffing heuristics (D14 configurable), suspicious-login detection (IP/device change) |
| Enumeration | generic login/reset messages, random public tokens (D11) |
| Replay/duplicate | idempotency keys (D18), signed+timestamped webhooks |
| Webhook spoofing | HMAC signature verification + timestamp window on courier/SMS/provider webhooks |
| AJAX abuse | every JSON endpoint behind same middleware chain: auth, permission, scope, rate limit |
| Rate-limit abuse | route-group limiters incl. public verification/downloads/search |
| Unsafe deserialization | no `unserialize` of user data; signed cookies only |
| Malicious filenames/content | § file pipeline above; uploads never executed (handler disabled for upload dirs) |
| Oversize processing | upload cap, PDF/image dimension caps, queue-based heavy processing |
| Secrets | encrypted settings (`encrypted` cast), never in logs/audit (redaction list), password hashing Argon2id/BCrypt + opportunistic rehash on login |

Additional: password policy + history, optional email verification, recovery flows, security settings UI, impersonation only if implemented → always audited with reason, polished error pages (no raw exceptions; JSON AJAX errors without traces).

---

## 14. Audit / CCTV Event Architecture

- `audit_events` written by `AuditRecorder` from domain events + explicit calls; covers: auth lifecycle, session events, CRUD, sensitive view, delete request/void, approve/reject/submit/cancel, print/download/export/import, payment/refund, stock movement, attendance in/out, salary acknowledgment, config change, permission change, workflow change, document access, public link access.
- Fields exactly per spec §26 (seq, user, role_at_time, company, branch, action, entity type/id, doc no, ref no, before/after redacted diff, amount, txn date, action ts, timezone, IP, UA, device/session, route, method, correlation id, outcome, reason).
- Redaction: denylist (passwords, tokens, cookies, secrets, payment credentials) applied before snapshot persist.
- Integrity: per-company hash chain `row_hash = H(seq‖prev_hash‖payload)`, nightly chain verification job; verified status visible in UI; `audit_archives` store periodic sealed copies (signed export).
- Protection: no update/delete application paths; DB grants restrict to INSERT/SELECT (D19); retention setting (`Audit Log Retention`) governs archival, not deletion, and purge is privileged + audited.
- Viewer: search/filter/detail/printable report under `audit.view`; **every audit view/export itself audited**; security alerts by severity routed to configurable admin audience (Super Admin default).

---

## 15. AI / Business-Intelligence Architecture (no external API)

- `Intelligence\` services compute from live data: moving/WMA/explosive smoothing, trend slope, seasonality (min-obs gate), IQR/z-score outliers, demand velocity, coverage days, ROP, lead-time demand, gross margin, overdue-risk scoring, payment behavior, suspicious-order scoring, branch variance, campaign cost/revenue, target achievement, warranty expiry.
- **Explainability contract**: every `bi_recommendations` row stores detected, data period, sample size, method/formula id, threshold, confidence/quality, recommended action, reason. Insufficient data → explicit "Not enough historical data" state (no invented predictions).
- Rules engine: `bi_rules` (condition tree + operators + thresholds + schedule + action + branch scope + effective dates) → `bi_rule_runs` log; actions are *safe* only: create suggestion, create flag, send notification, raise alert. Never mutates GL/stock/permissions/payroll.
- Cadence: scheduler computes `bi_metrics_daily` aggregates (nightly) so dashboards read pre-aggregated data (§18 performance); on-demand recompute bounded.
- Optional external AI = adapter behind `IntelligencePort`, disabled by default, never in login/sales/purchase/inventory/accounting/payroll/approval paths.

---

## 16. Database HA / Failover / Recovery Architecture

- **No fake HA** from multiple Laravel connections (spec prohibition).
- Tier A (infrastructure supports): app → MySQL Router → InnoDB Cluster (primary + 2 replicas, Group Replication, Router in HA proxy role) → independent backup system. App config: single router endpoint; read/write intent separation via router roles; cluster manages failover.
- Tier B: primary + async replica(s) + router/proxy; promotion is an **operational runbook**, not application code.
- Tier C (cPanel): single node, labeled honestly in System Health; backups + restore tests are the recovery story.
- Application obligations in all tiers:
  - connection health checks + startup probe; status surfaced in maintenance UI;
  - transaction boundaries around multi-table mutations; row locks as specified;
  - retry **only** idempotent operations (D18) with bounded attempts;
  - **fail-fast** for unsafe financial mutations on ambiguous connection state (no blind retry of `post()`);
  - never write when cluster reports read-only/unknown primary → request fails with retryable page (no split-brain);
  - recovery visibility: `system_heartbeats` + `maintenance_events`.
- Recovery: point-in-time capability depends on binlog + backups (Tier A/B); documented runbook in `docs/` with restore drill (§17).

---

## 17. Backup / Storage Architecture

- Backup classes (separate jobs + records): `database`, `uploaded_files`, `generated_documents`, `configuration_metadata`, `audit_archives`.
- Record fields per spec: backup_id, type, source, created_at, size, checksum, status, verification_state, retention_state, restore_test_state.
- Retention: configurable daily/weekly/monthly/yearly tiers; grandfather-father-son pruning; **pruning never touches the newest verified backup**.
- Verification: checksum validation + row-count/spot-restore check → `verification_state=verified`. Backup UI text distinguishes `completed` vs `verified` vs `restore_tested` — a completed job is never presented as restorable.
- Storage: local + offsite adapter (SFTP/compatible object storage), per-instance namespace (D2), encryption of DB dumps at rest, platform plane records per-instance backup status.
- Platform control plane: reads backup/health status via instance heartbeat files/endpoint; cannot read tenant business data.
- **Cache-clear guard**: maintenance cache/session/temp cleaners operate on explicit allow-lists (framework cache paths, `storage/framework/*`, `storage/logs/*` index) and can never path into `uploads/`, `documents/`, `backups/`, `audit/` (enforced by path assertion tests).

---

## 18. Responsive Premium ERP UI Architecture

### 18.1 Design system (original; Bootstrap 5.3.8 as utility base only)

- Tokens: `--canvas` white base, near-white layered surfaces, neutral borders, **one** configurable accent (settings-driven), dark readable ink, subtle elevation, medium radius, tabular numerals for money.
- Typography scale + density variants; status badges (semantic, not rainbow); compact action bars; skeletons; inline validation; toasts only for transient feedback.
- Forbidden: Nexus/AdminLTE/Metronic/CoreUI/Argon/Tabler or any theme imitation; purple/dark-only identity; flashing/pulsing/jumping/parallax/autoplay motion. Transitions ≤200ms opacity/transform; `prefers-reduced-motion` respected.
- Optional dark mode = settings preference (tokens swap), not a second design.
- Accessibility: WCAG AA contrast, focus rings, keyboard navigation, ARIA on composite widgets, screen-reader labels on icon buttons.

### 18.2 Primitives (reusable Blade components)

`AppShell, TopBar, BranchSwitcher, NavigationTree, Breadcrumbs, PageHeader, SmartToolbar, FilterBar, KpiCard, DataTable, EmptyState, DetailPanel, Timeline, ApprovalStepper, StatusBadge, AuditDrawer, DocumentPreview, ConfirmDialog, FormSection, CalculationPanel, LedgerTable, PrintableDocumentFrame` + global overlay system (modal/drawer/toast/confirm/loading/notification share one interaction+a11y contract).

### 18.3 Navigation

DB-driven tree (module → submenu → sub-submenu), collapsible groups, search/filter, favorites + recent, permission-filtered (unauthorized items absent), active trail, breadcrumbs, page title, contextual action bar. No wall-of-text sidebar; no disabled/locked unauthorized items.

### 18.3.1 Navigation curation (implemented 2026-10-07)

The §47 catalog is the **coverage authority**; it is not a sidebar layout. Curation
happens in `NavigationBuilder` from `config('erp.navigation')`, never in Blade:

| Concern | Mechanism |
|---|---|
| Destination vs action | `CatalogImporter` seeds verb-first leaves with `location = 'action'`; only `sidebar` rows may render |
| Grouping | job-to-be-done `sections`; second-level groups are *promoted* to siblings (`max_depth`) |
| One screen, one link | `dedupe_by_path` + `collapse_query_variants` |
| Rail length | `max_children` quota; the remainder is announced as “n more — search” |
| Deep pages | ⌘K palette indexes every permitted, routable entry (`palette()`) |
| Query variants | page-level saved views (`viewsForPath()`), e.g. the order status ladder |
| Sibling screens | “More in this module” rail (`related()`) |
| Personal | server-side pins (`menu_item_favorites`, `navigation.pin`) |
| Orientation | breadcrumb trail (`trail()`) rendered by the TopBar |

Invariants preserved: only `status = active` rows render; unauthorized items are
absent (never disabled); portal and entitlement filters still apply; the whole
tree remains database-driven (`php artisan menu:sync` after each phase).

`php artisan menu:sync` must be run once after this change so existing rows pick
up the new `location` classification.

### 18.4 Responsive modes (behavioral adaptation, not scaling)

| Mode | Width | Behavior |
|---|---|---|
| Mobile compact | <576 | app shell, offcanvas nav, sticky header, optional bottom quick-actions, tables→cards, full-width forms, ≥44px touch targets |
| Mobile large | 576–767 | same as compact with relaxed padding |
| Tablet | 768–991 | two-pane where useful, condensed toolbar |
| Desktop | 992–1199 | persistent nav, dense tables, keyboard-first |
| Large/XL | 1200–1919 | multi-column workspace, side detail panels |
| TV | ≥1920 | edge-to-edge density, larger type, higher contrast, no hover-only affordances, wide dashboard |

One component implementation + mode CSS; never duplicated pages.

### 18.5 Overlay rule

Small focused ops (quick create, one-line edit, filters) → modal/drawer. Complex workflows (order, invoice, payroll run, journal) → full-page workspaces inside the app shell (no new tab).

### 18.6 Page state contract

Every page implements: loading (skeleton), empty (professional, explains next step), validation, success, recoverable error, authorization (403), not-found, server-error — plus polished global 401/403/404/419/429/500 pages.

### 18.7 JS module architecture (Vite, ES modules)

`ajax` (CSRF, cancellation, structured errors), `forms` (unsaved-change guard), `search` (debounced), `lookup` (customer/invoice/product), `scanner` (barcode/QR), `lineItems` (inline calc — display only, server authoritative), `dependentSelect`, `crud` (modal CRUD where appropriate), `table` (pagination/filter/bulk), `toasts`, `poller` (approvals/notifications), `print`, `dashboard` (widget refresh + drill-down), `pos-offline` (service worker + IndexedDB queue, idempotency UUIDs, conflict UI, sync on reconnect, server authoritative). No anonymous one-off JS blobs in Blade.

---

## 19. Print / PDF / Document Architecture

- One `DocumentRenderer` port: `render(document_type, entity, context, options) → {html|pdf}` + storage of generated artifact as `documents` row with version + checksum + print/download history.
- Template registry: `document_types` + template configs in DB (header/footer, logo, seal, signature image, watermark, paper size A4/thermal, numbering, versioning, EN/BN variant).
- Document set (each an explicit template, title from `document_types.printed_title`): Invoice (title **INVOICE**), Tax Invoice (statutory, e.g. Mushak 9.1), Proforma, Retail Invoice (Mushak 11), Delivery Challan, Quotation, Sales Order, Purchase Order, GRN, Money Receipt, Credit Note, Debit Note, Ledger/Statement, Payslip, Salary Sheet, Audit report, generic report, certificates/letters, packing slip, shipping label, courier manifest, X/Z report.
- Tax rendering rule (D10): tax breakdown rendered iff resolved tax rule applicable; otherwise rows/labels entirely absent (never a 0% line).
- Pipeline: server-side HTML with print CSS → PDF adapter (D16) with pagination rules, Bangla/English fonts, QR/barcode injection; heavy builds queued when large; watermark/digital-signature image per config.
- Public artifacts served only through controlled routes using tokens (D11).

---

## 20. Testing Architecture

- Stack: PHPUnit/Pest feature+unit; factories **only** in tests (never seeded to DB); static analysis (PHPStan/Pint level configured) in CI; browser tests for critical flows where environment allows.
- Layers:
  1. **Unit**: Money/Qty/Rate math, rounding, amount-in-words (EN/BN, lakh/crore), Bengali numerals, pricing resolution, tax resolution, costing layers (FIFO/LIFO/WAC), warranty date math, workflow condition evaluator, BI formulas (with min-sample gates), permission evaluation, hash-chain audit verification.
  2. **Feature**: auth, throttling/lockout, menu visibility, action permissions, branch scope (cross-branch 404/403 matrix), IDOR probes, approval workflows incl. maker-checker + snapshot invalidation, sales calc/discount/tax/totals, payment allocation/partial/overpayment, ledger balance, double-entry balance (property: every posting ΣD=ΣC), stock movements, transfer discrepancy, serial/batch, warranty activation exactly-once on delivery, remaining-days, QR verification payload privacy, public link revoke/rotate + rate limits, money receipt, payroll + leave/absence reconciliation + retroactive leave, salary acknowledgment persistence (refresh/logout/login), notification outbox truthful states (no creds → disabled_provider), duplicate job protection, document numbering concurrency (parallel processes), report totals vs source, audit records + chain tamper detection.
  3. **Integration**: posting rules → journals, perpetual stock ↔ GL reconciliation, 3-way match, offline POS sync conflicts, backup verification/restore drill (scripted).
  4. **Security suite**: upload abuse, SQLi/XSS payload probes, CSRF absence, mass-assignment attempts, secret-redaction assertions in logs/audit.
  5. **Performance**: dashboard aggregate budgets (no 25 full scans — asserted via query counts), N+1 detection, pagination on large fixtures.
- Coverage gate: all spec §42 items map to named test classes in `TRACEABILITY/*` (Tests column).
- Phase rule: each implementation phase ships with its tests green before the next phase starts (spec §43).

---

## 21. Deployment / cPanel Architecture

- Runtime: PHP 8.3 (Laravel 13 requirements), MySQL 8, extensions (pdo_mysql, mbstring, openssl, bcmath, gd/imagick, zip, intl, fileinfo).
- Layout per instance: `public_html` → app `public/`; `app/` (code) and `storage/`, `database/` outside web root; `.htaccess` denies dotfiles, `storage/`, `vendor/`; upload dirs disable script execution; HTTPS forced; secure cookie flags; correct `APP_ENV=production`, `APP_DEBUG=false`.
- Processes on shared hosting (no systemd): cron entries — `schedule:run` every minute; queue worker loop cron (`queue:work --stop-when-empty` every minute with lock file to avoid overlap); backup cron per retention plan; verification/BI/heartbeat scheduled entries. Redis optional (falls back to database driver, D15).
- Environments: `.env` per instance; config cache/route cache/optimize on deploy; migrations run via maintenance gate (artisan wrapper script with one-time token) — never auto-migrate on request.
- Multi-instance: platform (main domain) provisions instance = create DB + user (least-privilege grants on own DB only) + docroot/subdomain from slug (D2) + run installer (D23) + write platform record. Suspension = disable app access flag + optional maintenance page at slug; **data untouched** (D22).
- Release process: build assets (Vite) → upload code → run migrations under maintenance → smoke tests (health route, login, one posting dry-run) → disable maintenance; rollback = previous code + migration rollback only when safe (expand/contract policy).
- cPanel limitations are declared in System Health (Tier C) — no pretending of HA (§16).

---

## 22. Complete Implementation Sequence

Phases A–T from spec §43, expanded with control plane and gates. Each phase = migrations + routes + authorization + tests green + lint + no-fake-data check + docs/status update.

| Phase | Scope | Gate (must pass to advance) |
|---|---|---|
| 0 | Repo bootstrap: Laravel 13, Vite+Bootstrap 5.3.8, structure, CI (tests+static analysis), `IMPLEMENTATION_STATUS.json` | boots, health route, empty test suite green |
| A | Foundation: company/branches/users/auth/session/security settings, settings engine, i18n (D21), first-boot installer (D23), scope context | auth+scope tests, no default creds |
| B | RBAC + menu/action/widget registry + permission middleware + BranchScope + menu visibility | permission/scope/IDOR test matrix green |
| C | Approval/workflow engine + TransitionService + status registry | engine tests incl. SoD, snapshot invalidation, concurrency |
| D | Accounting core: COA, fiscal, posting service, journals, ledgers, running balance + rebuild, payment allocations | ΣD=ΣC property, reversal, reconciliation tests |
| E | Inventory core: products/UoM/variants, stock ledger, layers, balances, reservations, transfers, adjustments, batch/serial, FEFO | stock tests, negative-stock guard, GL↔stock recon |
| F | Masters: customers, suppliers, geo/holiday/bank/courier masters, price lists, tax rules, document types, numbering | master CRUD + scope + numbering concurrency |
| G | Sales: quotation→order→challan→shipment→invoice→payment→returns/refunds, POS (+offline), pricing/promos, sales team | full sales cycle tests, invoice title/tax rules (D10) |
| H | Purchase: PR→RFQ→PO→GRN→bill→3-way→supplier payment→returns→LC | full purchase cycle + match tests |
| I | Cash & Bank: sessions, receipts/payments, bank/wallet/cheque, petty cash, reconciliation, expenses | cash GL effects, X/Z, reconciliation tests |
| J | Customers/Suppliers dashboards: dues aging, collection flow, money receipt, statements, EMI, credit limits | allocation/aging/statement tests |
| K | Employee/HR: masters, attendance, leave, payroll, OT/loans/bonuses, **salary acknowledgment**, deduction rules | leave/absence reconciliation, ack persistence tests |
| L | Service/Technician portal + Warranty engine + QR/public verification/links | activation idempotency, portal scope, public rate-limit tests |
| M | Marketing + Business Management modules (real records, adapters w/ truthful states) | opt-out, truthful delivery state tests |
| N | Reports + Dashboard (25 widgets, drill-down, aggregates/cache) | widget query budget, totals-vs-source tests |
| O | Documents/PDF/QR pipeline + print templates EN/BN | document snapshot tests incl. tax visibility rule |
| P | Intelligence/rules engine (explainability fields) + anomaly/min-data gates | formula unit tests, no-mutation guard tests |
| Q | Notification/outbox/integrations (all channels, disabled-provider truth) | outbox state machine, after-commit, duplicate-job tests |
| R | Maintenance/self-healing/backup/restore tooling + cache-clear guard | guard path tests, backup verify/restore drill |
| S | UI polish: responsive modes, overlays, error/empty/loading states, a11y pass | responsive + a11y checklist, no forbidden themes |
| T | Platform control plane: instances, packages, billing, suspend/reactivate, deletion workflow, platform audit, slug isolation | isolation tests (no cross-instance), suspend/reactivate data-preservation tests |
| U | Performance + security hardening pass + full §42 suite + **self-audit** (omissions, fake impls, permission leaks, cross-branch access, imbalance, stock mismatch, broken notifications, doc inconsistencies, responsive defects, missing tests) | all gates green; traceability matrix statuses truthful |

Implementation status for every traced row lives in `docs/IMPLEMENTATION_STATUS.json` and `docs/TRACEABILITY/*.md` — updated at the end of each phase per spec §52.
