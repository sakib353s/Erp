# What remains — module coverage after the redesign pass

Machine-readable companion: [`IMPLEMENTATION_STATUS.json`](IMPLEMENTATION_STATUS.json).
Per-row detail: [`TRACEABILITY/`](TRACEABILITY/). This page is the honest snapshot;
a module is only "done" when persistence + validation + authorization + branch
scope + business logic + workflow + effects + notifications + audit + UI + error
handling and its tests are all connected (see `TRACEABILITY/README.md`).

_Last updated: 2026-10-08, after the HRM (§10) and Purchase/Suppliers (§03/§06) slices._

## Where the product stands

| Module | Rows done / total | State |
|---|---|---|
| 01 Dashboard | 0 / 27 | widget frames + real-data contract in place; 25 widget queries pending |
| 02 Sales | 104 / 120 | essentially complete: orders, bulk actions, invoices, delivery, team, POS, reports |
| 03 Purchase | 18 / 73 | **the purchase cycle is closed both ways** — POs with approval, receipts posting real stock, bills posting the payable with a three-way match, payments settling it, returns taking goods back with their debit note; RFQ, LC and reports pending |
| 04 Inventory | 11 / 63 | products, adjustments, transfers, stock movements; purchase receipts now feed stock; opening stock/valuation reports pending |
| 05 Customers (CRM) | 14 / 23 | profile, ledger, ageing, credit control, feedback, referrals, blacklist; collections workflow + import pending |
| 06 Suppliers | 3 / 15 | **master + transactional controls built** — duplicate refusal, blacklist with reason, profile from real documents incl. payables ageing; GL ledger, statements and payments pending |
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

**Totals: 194 of 619 catalogued rows implemented.** (Sales 104, HRM 17, Masters 15, CRM 14, Purchase 18,
Inventory 11, Accounting 8, Returns 4, Suppliers 3.)

## The next three builds, in the order they unlock the most

1. **Supplier ledger and statement (06-08, 06-14, 03-63)** — the purchase cycle is now
   complete in both directions: order → receipt → bill → payment, and returns with their
   debit notes. Everything needed to answer "what does this supplier's account look like"
   exists as real documents and journal lines; what is missing is reading them back as one
   running account with a printable statement, plus the ageing screen company-wide
   (06-09) and report exports (03-51, 03-64). After that, supplier advances / BEFTN /
   payment schedule (03-53…03-55) are the remaining gaps in the money-out half.
2. **Accounts close-out (08 + 09 remainder)** — cash book and bank reconciliation
   first (they feed every collection screen), then customer/supplier ledgers, day book,
   and the VAT/Mushak report family.
3. **Inventory remainder (04)** — opening stock, valuation reports, reorder levels and
   barcode/bin support on top of the ledger that purchases now write to.

## Known deviations inside what *is* built (documented, not hidden)

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
  (no PHP runtime): `CustomerCrmTest`, `HrAttendanceTest`, `HrLeaveTest`,
  `PurchaseFlowTest` and the rest of the suite must be run where PHP + MySQL are
  available. Everything else here was verified statically (routes ↔ permissions ↔
  views ↔ CSS/JS contracts).
