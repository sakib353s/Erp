# 06. SUPPLIERS — Traceability

**Implemented slice (2026-10-08):** the supplier master as a real party record — list (06-01), create/update with
duplicate refusal (06-02), and a profile that shows the truth about the relationship: outstanding deliveries from
open purchase orders, recent orders, recent receipts and spend by month (06-05), plus transactional controls that
the earlier party table did not have (blacklist with a mandatory reason, reinstate on the same screen, and a
`scopeOrderable` guard that stops a barred supplier being put on a new PO).

**Since the bill slice (03-44…03-50) the money half of the profile is real:** the supplier screen now shows what is
owed (`PurchaseQuery::supplierPayables`), the ageing buckets those bills fall into, and the list of open bills — all
derived from posted `purchase_bills` rows, never from a cached balance.

**Deliberately out of these slices** (no screens claim otherwise): CSV/queued import & export (06-03), a GL-derived
supplier ledger and printable statement (06-08, 06-14 — the profile shows documents, not journal lines), payments
and advance adjustment (06-10), contracts (06-11), performance scoring (06-12), documents (06-13) and the report
family (06-15).

**Known deviations in the implemented slice (honest, not silent):**
- Contacts and bank details live **on the supplier record** (`contact_person`, `phone`, `phone_alt`, `email`;
  `bank_name`, `bank_account_no`, `mobile_wallet`). There is no `supplier_contacts` / encrypted bank sub-table, and
  nothing is described as verified.
- Categories are a fixed vocabulary (`Supplier::CATEGORIES`) enforced on write and used as a filter — there is no
  category CRUD screen. Adding a category is a code change, and the UI says so rather than offering a dead button.
- Blacklisting is a permission of its own (`suppliers.blacklist`) distinct from edit; the reason, actor and time are
  recorded and displayed on the profile. Reinstating is audited on the same trail.
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
| 06-08 | Suppliers › Supplier Ledger | — | — | a GL-side ledger (`LedgerService` by the AP control account) is still pending; the profile lists the documents that make up the balance instead of pretending to be a journal | — | — | — | NOT STARTED |
| 06-09 | Suppliers › Supplier Due › All / 0-30 / 31-60 / 60+ | ageing shown on `GET /app/suppliers/{supplier}` | `suppliers.view` | `PurchaseQuery::supplierPayables` — due / overdue plus current, 1–30, 31–60, 61–90 and 90+ buckets from each bill's due date | `purchase_bills` | — | `PurchaseBillTest::test_payables_reach_the_supplier_profile_and_the_bill_summary` | PARTIAL — buckets + open-bill table on the profile; no company-wide due screen |
| 06-10 | Suppliers › Supplier Payments | — | — | — | — | — | — | NOT STARTED |
| 06-11 | Suppliers › Supplier Contracts | — | — | — | — | — | — | NOT STARTED |
| 06-12 | Suppliers › Supplier Performance / Quality Score | (spend panel on the profile) | `suppliers.view` | `PurchaseQuery::supplierSpend` | `goods_receipts` | — | — | PARTIAL — spend only; no score, formula or rating |
| 06-13 | Suppliers › Supplier Documents | — | — | — | — | — | — | NOT STARTED |
| 06-14 | Suppliers › Supplier Statements | — | — | needs bills/ledger | — | — | — | NOT STARTED |
| 06-15 | Suppliers › Supplier Reports | (profile spend + payables) | `suppliers.view` | spend by month and open payables on the profile | `goods_receipts`, `purchase_bills` | — | — | PARTIAL — no report routes |

## Slice scorecard

| | Count |
|---|---|
| DONE | 3 (06-01, 06-02, 06-05) |
| PARTIAL | 5 (06-04, 06-06, 06-07, 06-09, 06-15) |
| NOT STARTED | 7 |
