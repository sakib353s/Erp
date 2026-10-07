# 06. SUPPLIERS — Traceability

**Implemented slice (2026-10-08):** the supplier master as a real party record — list (06-01), create/update with
duplicate refusal (06-02), and a profile that shows the truth about the relationship: outstanding deliveries from
open purchase orders, recent orders, recent receipts and spend by month (06-05), plus transactional controls that
the earlier party table did not have (blacklist with a mandatory reason, reinstate on the same screen, and a
`scopeOrderable` guard that stops a barred supplier being put on a new PO).

**Since the payment slice (03-47, 03-52) the profile can also show money going out:** the same posted bills are
settled by real payments (Dr accounts payable / Cr cash or bank), so what the screen reports as owed is the
unsettled balance of documents rather than an estimate.

**Since the bill slice (03-44…03-50) the money half of the profile is real:** the supplier screen now shows what is
owed (`PurchaseQuery::supplierPayables`), the ageing buckets those bills fall into, and the list of open bills — all
derived from posted `purchase_bills` rows, never from a cached balance.

**Since the account slice (06-08, 06-09, 06-14) the supplier has a real account:** the ledger index lists every
supplier the company has done money with and the balance those documents leave, each supplier opens into a running
account (bills increase what we owe, payments and returns take it back — one row per document with the balance after
it), the company-wide ageing screen folds every open bill into the bucket its own due date puts it in, and the
statement is printable and downloadable as CSV from the same data the screen shows.

**Deliberately out of these slices** (no screens claim otherwise): CSV import of suppliers as a queued job (06-03),
payments *ahead* of a bill and advance adjustment (06-10 — money out is always allocated to a posted bill),
contracts (06-11), performance scoring (06-12), documents (06-13) and the report family as a set of exports (06-15).

**Known deviations in the implemented slice (honest, not silent):**
- Contacts and bank details live **on the supplier record** (`contact_person`, `phone`, `phone_alt`, `email`;
  `bank_name`, `bank_account_no`, `mobile_wallet`). There is no `supplier_contacts` / encrypted bank sub-table, and
  nothing is described as verified.
- Categories are a fixed vocabulary (`Supplier::CATEGORIES`) enforced on write and used as a filter — there is no
  category CRUD screen. Adding a category is a code change, and the UI says so rather than offering a dead button.
- Blacklisting is a permission of its own (`suppliers.blacklist`) distinct from edit; the reason, actor and time are
  recorded and displayed on the profile. Reinstating is audited on the same trail.
- **The ledger is derived from documents, not from the general ledger.** Rows are posted bills, recorded payments
  and approved returns, so the closing balance equals the sum of the open bills' outstanding amounts. A GL-side
  account (posting by the AP control account and its party tag) would also show adjustments made directly in
  journals, which this view cannot see yet — stated here rather than papered over.
- **A supplier has no opening-balance field.** The account therefore starts with the first document recorded in the
  system; a company migrating mid-year has to record a bill for what was already owed. (The customer side has an
  opening balance, so the asymmetry is deliberate and visible, not accidental.)
- "Supplier performance" is spend derived from real posted receipts; there is no score, rating or grade field
  anywhere, because the formula (03-12) is not implemented.

Baseline (`BL`) applies. Shared with `03-purchase.md`: the same `SupplierService`, `PurchaseQuery` and the same
`s/Suppliers` surface — §06 has no parallel tables and no parallel service, by design.

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 06-01 | Suppliers › All Suppliers | `GET /app/suppliers` | `suppliers.view` | `SupplierController@index` + `PurchaseQuery::suppliers` — search (name/code/phone/TIN/BIN), category, status (`orderable` / `inactive` / `blacklisted`), sort; KPI grid from real rows | `suppliers` | AUD | `PurchaseFlowTest::test_purchase_screens_render_for_an_authorised_user`, `::test_purchase_screens_are_permission_gated` | DONE |
| 06-02 | Suppliers › Add Supplier | `GET|POST /app/suppliers/create`, `GET|PUT /app/suppliers/{supplier}/edit` | `suppliers.create` / `suppliers.edit` | `StoreSupplierRequest` (code upper-cased + unique per company ignoring self, phone/BIN format, terms 0–365), `SupplierService@create/@update` — duplicate name / BIN / phone refused with the reason | `suppliers` | AUD diff; `SUP-00001` via `NumberingService` | `PurchaseFlowTest::test_blacklisting_needs_a_reason_and_blocks_new_documents` | DONE |
| 06-03 | Suppliers › Import / Export Suppliers | — | — | not built | — | — | — | NOT STARTED |
| 06-04 | Suppliers › Supplier Categories | (filter + picker) | `suppliers.view` | `Supplier::CATEGORIES` = goods / service / transport / utility / other | `suppliers.category` | — | — | PARTIAL — vocabulary, not CRUD |
| 06-05 | Suppliers › Supplier Profile | `GET /app/suppliers/{supplier}` | `suppliers.view` | `SupplierController@show` — party/tax/bank/terms block, outstanding delivery lines (`PurchaseQuery::openLines`), recent POs, recent receipts, monthly spend | `suppliers`, `purchase_orders`, `purchase_order_lines`, `goods_receipts` | AUD | `PurchaseFlowTest` | DONE |
| 06-06 | Suppliers › Supplier Contacts | (fields on the party) | `suppliers.edit` | `contact_person`, `phone`, `phone_alt`, `email` | `suppliers` | AUD diff | — | PARTIAL — one contact per supplier; no multi-contact table |
| 06-07 | Suppliers › Supplier Bank Details | (fields on the party) | `suppliers.edit` | `bank_name`, `bank_account_no`, `mobile_wallet` shown to whoever may edit the party | `suppliers` | AUD diff | — | PARTIAL — plain attributes, no encryption-at-rest sub-record, no verification trail |
| 06-08 | Suppliers › Supplier Ledger | `GET /app/suppliers/ledger`, `GET /app/suppliers/{supplier}/ledger` | `suppliers.view` | `PurchaseQuery::supplierBalances` (three grouped aggregates — billed / paid / credited, one row per supplier) and `supplierLedger` (running account from posted bills, recorded payments and approved returns, with the balance before the range as the opening figure) | `purchase_bills`, `payments`, `purchase_returns` | — | `SupplierLedgerTest` (7 cases) | DONE — document-derived, not GL-derived (deviation above) |
| 06-09 | Suppliers › Supplier Due › All / 0-30 / 31-60 / 60+ | `GET /app/purchase/payables?bucket=` , plus buckets on the profile and the ledger | `purchase.bills.view` / `suppliers.view` | `PurchaseQuery::payablesAgeing` — one row per supplier with current / 1–30 / 31–60 / 61–90 / 90+ columns and totals, filtered by bucket; `supplierPayables` gives the same buckets for a single supplier | `purchase_bills` | — | `SupplierLedgerTest::test_the_ageing_screen_buckets_every_open_bill_by_its_own_due_date`, `PurchaseBillTest::…payables…` | DONE |
| 06-10 | Suppliers › Supplier Payments | `GET /app/purchase/payments?supplier=` and `create?bill=` | `purchase.payments.view` / `create` | recording a payment against a posted bill, with the bill's balance and ageing beside it (03-47 / 03-52 own the mechanics) | `payments`, `payment_allocations` | ACCT + AUD | `SupplierPaymentTest` (7 cases) | PARTIAL — real payments, a filtered history and the account they land in exist; paying *ahead* of a bill and advance adjustment do not |
| 06-11 | Suppliers › Supplier Contracts | — | — | — | — | — | — | NOT STARTED |
| 06-12 | Suppliers › Supplier Performance / Quality Score | (spend panel on the profile) | `suppliers.view` | `PurchaseQuery::supplierSpend` | `goods_receipts` | — | — | PARTIAL — spend only; no score, formula or rating |
| 06-13 | Suppliers › Supplier Documents | — | — | — | — | — | — | NOT STARTED |
| 06-14 | Suppliers › Supplier Statements | `GET /app/suppliers/{supplier}/statement` (`?format=csv`) | `suppliers.view` | printable document (its own print frame, toolbar hidden when printing) with opening/closing balance, every document in the period and the ageing of what is still outstanding; CSV streams the same rows | `purchase_bills`, `payments`, `purchase_returns` | — | `SupplierLedgerTest::test_the_statement_is_printable_and_exportable` | DONE |
| 06-15 | Suppliers › Supplier Reports | (profile spend + payables) | `suppliers.view` | spend by month and open payables on the profile | `goods_receipts`, `purchase_bills` | — | — | PARTIAL — no report routes |

## Slice scorecard

| | Count |
|---|---|
| DONE | 6 (06-01, 06-02, 06-05, 06-08, 06-09, 06-14) |
| PARTIAL | 6 (06-04, 06-06, 06-07, 06-10, 06-12, 06-15) |
| NOT STARTED | 3 (06-03, 06-11, 06-13) |
