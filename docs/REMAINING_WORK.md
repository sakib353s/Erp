# What remains — module coverage after the redesign pass

Machine-readable companion: [`IMPLEMENTATION_STATUS.json`](IMPLEMENTATION_STATUS.json).
Per-row detail: [`TRACEABILITY/`](TRACEABILITY/). This page is the honest snapshot;
a module is only "done" when persistence + validation + authorization + branch
scope + business logic + workflow + effects + notifications + audit + UI + error
handling and its tests are all connected (see `TRACEABILITY/README.md`).

## Where the product stands

| Module | Rows done / total | State |
|---|---|---|
| 01 Dashboard | 0 / 27 | widget frames + real-data contract in place; 25 widget queries pending |
| 02 Sales | 104 / 120 | essentially complete: orders, bulk actions, invoices, delivery, team, POS, reports |
| 03 Purchase | 0 / 73 | **not started** — suppliers, POs, GRN, bills, payments, returns |
| 04 Inventory | 11 / 63 | products, adjustments, transfers, stock movements; opening stock/valuation pending |
| 05 Customers (CRM) | 14 / 23 | **built in this pass** — profile, ledger, ageing, credit control, feedback, referrals, blacklist |
| 06 Suppliers | 0 / 15 | **not started** |
| 07 Returns | 4 / 18 | sales returns exist; purchase returns + credit notes pending |
| 08 Cash & Bank | 0 / 22 | **not started** — cash book, bank reconciliation, transfers, cheque management |
| 09 Accounting | 8 / 46 | journals, COA, trial balance, opening entries; ledgers/statements/reports pending |
| 10 Employee (HRM) | 0 / 47 | **not started** — this is the biggest HR gap the user named |
| 11 Marketing | 0 / 22 | SMS/WhatsApp campaign engine pending |
| 12 Business management | 0 / 16 | branch P&L, targets, governance screens |
| 13 Reports | 0 / 13 | report centre pending (sales reports exist inside module 02) |
| 14 Masters | 15 / 15 | complete |
| 15 Settings | 0 / 35 | **not started** — company/branch/invoice/Bengali/payment-gateway settings screens |
| 16 Cross-cutting | 0 / 64 | search, notifications, backups, BI, i18n, API mgmt, public links |

**Totals: 156 of 619 catalogued rows implemented.**

## The next three builds, in the order they unlock the most

1. **Purchase → Inventory (03 + 04 remainder + 06 Suppliers)** — the ERP cannot
   hold stock truth without GRN/bills. Suppliers CRUD, purchase order →
   goods-received note → purchase bill → payment, plus opening stock and
   valuation reports.
2. **Employee / HRM (10)** — departments, designations, employee full profile,
   attendance (device import + manual), leave (types exist), payroll run with
   BD statutory deductions, acknowledgement inbox. The `employees`,
   `leave_types` and `holidays` tables already exist, so this is schema-light.
3. **Accounts close-out (08 + 09 remainder)** — cash book and bank
   reconciliation first (they feed every collection screen), then customer/supplier
   ledgers, day book, and the VAT/Mushak report family.

## Known deviations inside what *is* built (documented, not hidden)

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
* **Blacklist** blocks via `assertOrderable()` — same wiring caveat as above.
* **Customer import** (05-03) is not built; export is (CSV, scope + filter aware).
* **Collections workflow** (05-09…05-15): assignment, follow-up log,
  promise-to-pay, legal notice and EMI plans are not built.
* **Bulk action reason/courier dialogs** on the orders screen are wired to the
  existing bulk routes; per-action approval when configured is unchanged.
