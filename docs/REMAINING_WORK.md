# What remains — module coverage after the redesign pass

Machine-readable companion: [`IMPLEMENTATION_STATUS.json`](IMPLEMENTATION_STATUS.json).
Per-row detail: [`TRACEABILITY/`](TRACEABILITY/). This page is the honest snapshot;
a module is only "done" when persistence + validation + authorization + branch
scope + business logic + workflow + effects + notifications + audit + UI + error
handling and its tests are all connected (see `TRACEABILITY/README.md`).

_Last updated: 2026-10-08, after the batch/expiry/FEFO slice (§04-37…04-41), the transfer approval slice (§04-28), the adjustment approval slice (§04-26), the warehousing slice (§04-42/43/45), the stock count slice (§04-31), the damage & loss slice (§04-46…04-51), the dashboard slice (§01) and the inventory alert/reorder, reservations and report-family slices (§04)._

## Where the product stands

| Module | Rows done / total | State |
|---|---|---|
| 01 Dashboard | 26 / 27 | **all 25 containers answer from real documents** — ok / empty / unavailable, one read service, visibility by the module's own view permission; only the widget admin CRUD in 12 Settings is pending |
| 02 Sales | 104 / 120 | essentially complete: orders, bulk actions, invoices, delivery, team, POS, reports |
| 03 Purchase | 19 / 73 | **the purchase cycle is closed both ways** — POs with approval, receipts posting real stock, bills posting the payable with a three-way match, payments settling it, returns taking goods back with their debit note; RFQ, LC and reports pending |
| 04 Inventory | 48 / 63 | products — including **product settings whose keys each have a consumer** (the default cost method, stock and batch switches preselect the add-product form, and `sku_from_code` lets a shop label products with their code, changing what the forms insist on and what the request derives — a setting nobody reads is a decoration, so none of these are), **the product cluster** and **the catalogue as CSV in and out** (import with a preview that writes nothing, per-row transactions so one bad line does not undo four hundred good ones, a receipt for every run inside `product_imports` with the failed lines and their reasons, and an export whose columns are the import template's own so a file can be exported, edited and imported back — plus: duplicating a product copies its configuration and no stock, categories are a real tree (parent select from the same table, a row can be neither its own parent nor under its own descendant, a branch with children refuses to delete, and the flat list shows the depth), brands and units are served by the masters registry rather than duplicated controllers, **a product cost change is now an append-only record with its reason and its author** (written only when the figure or the method actually moves, never rewriting a posted valuation layer, readable per product next to the layers it is not), and the price history has a per-product screen on the same render path as the general one; adjustments, transfers, movements, **alerts against reorder policies, the reservations desk, and the stock report family** (ageing, dead stock, stock report — all derived from the ledger, all exportable), **the damage & loss family** (damage/loss entries valued from the layers, a maker-checker write-off that posts the cost, and the damage analytics report) **stock/cycle counts** (a snapshot sheet, blind counting, and a posting that writes the variance as one adjustment) **the two approval gates (adjustments 04-26, transfers 04-28) with the adjustment history**, **and the warehouse layout** (zones, bins, one primary pick face per product per warehouse, a map that names what nobody has touched, a code that may be renamed while the branch may not change under stock, and one default warehouse per branch), **and the batch register with the expiry desk** (a batch hangs off the valuation layer so its quantity is the ledger's own; an inbound movement names the batch and a batch-tracked product must; a date already recorded moves only with a reason and keeps its trail; FEFO issues earliest-expiry-first for batch-tracked products behind the `inventory.fefo_picking` setting; expiry alerts are a daily deduped digest, and the desk hands an expired batch to the existing write-off document); unit conversion at the document edge is still missing (the unit master carries a base unit and factor, but the ledger stores the quantity as received — the row stays PLANNED), as is serial tracking. **The reorder family is closed end to end** (an alert now carries demand — real outbound movements per product per warehouse over a window the settings name, with days of cover shown beside the level — the reorder desk proposes one quantity from one explainable ladder: the policy's own answer as a floor, demand over the lead time plus safety as the need, the maximum as a ceiling, and what is already on open purchase orders subtracted; **the proposal is written down before the purchase order exists**, so the order can always say which figures asked for it; accepting drafts through the purchase module and posts nothing, `strict_auto_mode` refuses to draft at all, and the history keeps every answer beside three settings that each have a consumer). **Packaging is now inventory, not just a step in dispatch (§04-59…04-62)**: the types register sits on the table 02-98 already used, but the rules that make it a record are new — a type points at a real stock-managed product (checked in the request *and* in the service, so a picker and a service cannot disagree silently), its identity freezes once it has consumed anything (the consumption priced what it took; renaming it now would rewrite that), a used type is retired rather than deleted, and the register says out loud when a live type stopped being usable. Packaging stock is the same ledger as everything else — with “lasts about”, on hand ÷ the rate the type has actually been consumed at — and packaging cost keeps two averages apart on purpose: what the shelf is worth and what actually went out cost, because a shelf may hold last year's boxes while dispatch takes today's. The monthly report answers the only question packaging money raises — more units, or dearer units? — with quantity and unit cost on every row. Not scheduled by design: **product SEO (04-19)** — this ERP has no public storefront, so slugs, meta tags and OG images would be columns and screens nobody reads, which is the fake work the project rules forbid; it stays PLANNED until a storefront exists to need it. Still PLANNED for real reasons rather than cosmetic ones: **variants/attributes (04-08)**, **product units of measure (04-09)** — the unit master is real but nothing resolves a purchase unit to a base unit at the ledger edge, and that resolution has to happen there or not at all — and **digital downloads (04-18)**, which need a paid-order entitlement plus a controlled file route, not a field on the product form |
| 05 Customers (CRM) | 14 / 23 | profile, ledger, ageing, credit control, feedback, referrals, blacklist; collections workflow + import pending |
| 06 Suppliers | 6 / 15 | **master + the whole account built** — duplicate refusal, blacklist with reason, profile from real documents, running ledger, company-wide ageing and a printable/CSV statement; contracts, scoring and documents pending |
| 07 Returns | 4 / 18 | sales returns exist; purchase returns + credit notes pending |
| 08 Cash & Bank | 0 / 22 | **not started** — cash book, bank reconciliation, transfers, cheque management |
| 09 Accounting | 8 / 46 | journals, COA, trial balance, opening entries; ledgers/statements/reports pending |
| 10 Employee (HRM) | 17 / 47 | **attendance, leave, structure and service book built**; payroll, overtime, loans, bonuses, performance, recruitment, training, exit settlement pending |
| 11 Marketing | 0 / 22 | SMS/WhatsApp campaign engine pending |
| 12 Business management | 0 / 16 | branch P&L, targets, governance screens |
| 13 Reports | 0 / 13 | report centre pending (sales reports exist inside module 02) |
| 14 Masters | 15 / 15 | complete |
| 15 Settings | 0 / 35 | **not started** — company/branch/invoice/Bengali/payment-gateway settings screens |
| 16 Cross-cutting | 0 / 64 | search, notifications, backups, BI, i18n, API mgmt, public links |

**Totals: 261 of 619 catalogued rows implemented.** (Sales 104, Dashboard 26, Purchase 19, Inventory 48,
HRM 17, Masters 15, CRM 14, Accounting 8, Suppliers 6, Returns 4.)

## The next three builds, in the order they unlock the most

1. **Inventory remainder (04)** — the ledger is written to by purchases, sales, returns,
   damage and loss, and the reading half now covers alerts, reorder levels, the reservations
   desk, the report family (ageing, dead stock, stock report), damage analytics and the
   count family, the adjustment approval gate, the warehouse layout, the batch register with its
   expiry desk and FEFO issue order, and the **pick and putaway lists that walk the racks**: a pick
   list takes each order line's undelivered quantity, names the pick face and the batch FEFO says goes
   first, and finishes the walk without touching the ledger; a putaway list starts from a posted receipt
   and writes the home it learns back into the bin map. Still to build in this module: serial tracking
   (products already carry `track_serial`; a write path and a lifecycle do not exist yet), packaging
   stock, barcode labels, the attribute/variant and review families, and the insurance register behind 04-50.
2. **Accounts close-out (08 + 09 remainder)** — cash book and bank reconciliation first
   (they feed every collection screen), then the day book, the customer/supplier ledgers read
   from the control accounts, and the VAT/Mushak report family.
3. **The untouched domains** — cash & bank (§08) and the accounting remainder above, then
   reports (§13), settings (§15 — it also owns the widget admin screen 01-27 waits on)
   and the cross-cutting layer (§16: search, notifications, backups, i18n). The dashboard
   (§01) is already built on the queries the modules expose, so new modules light their
   panels up without touching the dashboard.

## Known deviations inside what *is* built (documented, not hidden)

* **Dashboard containers** are answered by one read service (`App\Domain\Dashboard\Services\DashboardMetrics`)
  rather than the planned per-widget classes and per-widget `dashboard.widget.*` keys: a container is visible
  exactly when the caller holds the view permission of the module whose documents it reads, and each container
  reports `ok`, `empty` or `unavailable` — the last one naming what is missing. No panel is left naming an unbuilt
  module: the expiry panel (01-21) reads the batch register as of the §04-39 slice, and cash position's
  `unavailable` is a real statement about this company's chart of accounts, not about missing code. The plan, the shipped shape and the reasons are side by
  side in `docs/TRACEABILITY/01-dashboard.md`. While wiring it, a real defect was fixed: `WidgetSeeder` produced
  mixed-case container codes (`Todays_Sales`) while the translations table, the label keys and the dashboard all
  key off lowercase slugs — codes are lowercase now and the seeder prunes containers outside the catalogue.
* **Damage, loss and write-off** are two documents, not one (see `docs/TRACEABILITY/04-inventory.md`):
  entries state facts (damage moves goods into the `damaged` compartment with no valuation effect;
  a loss consumes the layers and posts Dr 5260 / Cr 1140), while the write-off is the decision that
  removes value and needs a second person. `damaged` and `quarantined` are compartments held outside
  sellable stock and are valued all the same — and the ledger's state machine (now on
  `StockMovement::applyDelta()`) was corrected here: an outbound movement from the damaged compartment
  used to decrement `on_hand` a second time. Nothing posts to insurance: 04-50 has no register to
  link a claim to.
* **Two alert readers exist.** `StockQuery::alerts()` came with the inventory core and
  reports a company-wide balance against thresholds; the alert screens read
  `ReorderService::alertRows()`, which resolves the policy that actually governs a product
  in a warehouse (a warehouse row beats the company-wide one) and adds the shortage and a
  suggested order quantity. The screens are the single source of truth for what the user
  sees; `StockQuery::alerts()` is left as a balance-only summary rather than deleted, so
  nothing that calls it breaks. Products with no active policy are never alerted.
* **Reorder suggestions** (04-56) are *not* auto-posting purchase orders, and there is no
  standing "auto reorder" switch: the alert's Order action opens a prepared purchase order
  for a human to approve, which is the honest behaviour until a schedule and a strict mode
  are configured (04-58 still open).
* **Purchase approval** runs on the direct permission `purchase.orders.approve` with a
  creator-cannot-approve rule in the service, not through the shared Workflow engine
  (`approval_requests`). Same seam as HRM leave approval; one call to re-point.
* **Over-receipt** is a hard refusal with the numbers in the error. The configurable
  tolerance of 03-29 is not implemented.
* **Three-way match** is computed on bill approval and stored with a human summary, but
  with no configurable tolerance it *records* a mismatch instead of blocking it — a real
  liability is never hidden, and the mismatch is shown in full on the bill.
* **Purchase returns** post through `posting_rules` (`purchase_return_posted` → Cr inventory
  1140, `purchase_return_expense_posted` → Cr purchases & services 5225, both Dr accounts
  payable 2110 with Cr tax payable 2120 for the input tax reversed). A return tied to a bill
  credits that bill in the same transaction (`credited_amount`), so `due_amount =
  total − paid − credited` is the single definition of what a bill still owes and the AP
  control account never disagrees with the sum of the bills.
* **Supplier payments** post through `posting_rules` (`supplier_payment` → cash 1110,
  `supplier_payment_bank` → bank 1120) into the shared `payments` table with direction
  `out`; a payment is always allocated to one posted bill, never left floating, and the
  same idempotency key can never pay twice.
* **Purchase bill posting** runs through `posting_rules` (`PurchaseCoreSeeder`): goods-backed
  bills debit Inventory (1140), direct/service bills debit Purchases & Services (5225),
  input tax debits Tax Payable (2120, net-VAT treatment) and the credit is Accounts Payable
  (2110). Approval is `purchase.bills.approve` with maker ≠ checker; the Workflow engine is
  still not wired to purchasing.
* **No outbox** anywhere in purchasing, so there is no "sent to supplier" state, no PO
  print/email and no reminder — the UI never claims any of them.
* **Goods receipt conditions** — only `batch_no` is captured per line; condition,
  expiry and bin allocation (03-34) are missing, so FEFO and the quality-reject report
  have no input yet.
* **Supplier contacts/bank** live on the supplier record as plain attributes: no
  multi-contact table, no encrypted bank sub-record, nothing described as verified.
* **Customer ledger** is derived from `invoices` + `payment_allocations` — the
  same rows the accounting engine posts — rather than reading `journal_lines`
  directly. Figures reconcile, but a true GL-side ledger view
  (`LedgerService::accountLedger` by AR control account) is still pending (05-07).
* **Statement/ledger printing** uses the browser print pipeline and the
  §18.2 PrintableDocumentFrame; a server-side PDF renderer (05-17 `DOC PDF EN/BN`)
  is not wired yet.
* **Credit-limit enforcement** is available as `CustomerService::creditProjection()`
  and `assertOrderable()`; the sales order/invoice entry points do not call them
  yet, so today the limit *warns on the CRM screens* but does not block a document
  (05-16 config `block|warn` decision still open).
* **Customer/supplier import** (05-03, 06-03) is not built; CRM export is (CSV,
  scope + filter aware).
* **Collections workflow** (05-09…05-15): assignment, follow-up log,
  promise-to-pay, legal notice and EMI plans are not built.
* **Bulk action reason/courier dialogs** on the orders screen are wired to the
  existing bulk routes; per-action approval when configured is unchanged.
* **HRM payroll** (10-25…10-33) is deliberately out of scope so far: no salary run,
  no statutory deduction engine, no acknowledgement inbox. Attendance and leave are
  the inputs it will consume, and they are built.
* **Attendance capture** is manual/import only — the `device` source is reserved and
  nothing writes it; GPS configuration (10-13) does not exist.
* **Tests** in this repository are written but not executed in the build sandbox
  (no PHP runtime): `DashboardWidgetTest`, `InventoryDamageTest`, `StockAlertTest`, `StockReservationTest`,
  `StockReportTest`, `SupplierLedgerTest`, `CustomerCrmTest`, `HrAttendanceTest`,
  `HrLeaveTest`, `PurchaseFlowTest` and the rest of the suite must be run where PHP +
  MySQL are available. Everything else here was verified statically (routes ↔
  permissions ↔ views ↔ CSS/JS contracts).
