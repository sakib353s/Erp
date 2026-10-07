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
| 03 Purchase | 9 / 73 | **purchasing core built** — suppliers, POs with approval, goods receipts posting real stock; bills, payments, returns, RFQ, LC and reports pending |
| 04 Inventory | 11 / 63 | products, adjustments, transfers, stock movements; purchase receipts now feed stock; opening stock/valuation reports pending |
| 05 Customers (CRM) | 14 / 23 | profile, ledger, ageing, credit control, feedback, referrals, blacklist; collections workflow + import pending |
| 06 Suppliers | 3 / 15 | **master + transactional controls built** — duplicate refusal, blacklist with reason, profile from real documents; ledger/due/payments need bills |
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

**Totals: 185 of 619 catalogued rows implemented.** (Sales 104, HRM 17, Masters 15, CRM 14, Inventory 11,
Purchase 9, Accounting 8, Returns 4, Suppliers 3.)

## The next three builds, in the order they unlock the most

1. **Purchase bills → supplier payments (03-44…03-58 + the money half of §06)** — the
   goods-received note already moves stock; a bill is what creates the AP liability,
   the three-way match, the supplier ledger and the ageing buckets. Until it exists
   `06-08`/`06-09` cannot be honest, which is why they are untouched rather than stubbed.
   Purchase returns (`03-59…`) belong in the same change: a posted GRN is immutable
   and a return is its only correction path.
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
