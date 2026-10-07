# 03. PURCHASE — Traceability

**Implemented slices (2026-10-08):** the operating core of purchasing — supplier master deepening (03-01, 03-02,
03-04), purchase orders with server-derived totals and a two-permission approval gate (03-22, 03-23, 03-24 partial,
03-25 partial), goods receipt as the only stock-entry path (03-32, 03-33, 03-36), PO → GRN conversion with a real
over-receipt guard (03-29), batch capture on receipt lines (03-34 partial) — followed in the same day by **purchase
bills (03-44, 03-45, 03-46, 03-50)**: the payable is now real, posted to the ledger through `posting_rules` on
approval, with the three-way match (order ↔ receipt ↔ bill) computed, stored on the bill and shown beside it, plus
supplier payables with ageing (03-48 partial).

**Deliberately out of these slices** (planned, not stubbed — no screen claims a capability it does not have):
purchase requests (03-16…03-21), PO amendment / duplication / print / outbox (03-26…03-28), GRN amendment / print
(03-35, 03-38), RFQ & comparison (03-39…03-43), bill payment recording / print / reports (03-47, 03-49, 03-51),
**supplier payments, advances, BEFTN, schedules (03-52…03-58)**, **purchase returns and debit notes
(03-59…03-64)**, import purchase / LC / landing cost (03-65…03-67) and the report family (03-68…03-73).
Supplier payments are the immediate next change: a bill now carries a real balance (`paid_amount` / `due_amount`)
that nothing can settle yet, and purchase returns are what make a posted receipt or bill correctable.

**Known deviations in the implemented slice (honest, not silent):**
- Approval runs on the direct permission `purchase.orders.approve` (creator ≠ approver enforced in the service),
  **not** yet through the shared Workflow engine (`approval_requests`) — consistent with the HRM slice, and the
  seam is a single call in `PurchaseOrderService::approve()`.
- Over-receipt is a **hard refusal** with the numbers in the message. The spec's configurable tolerance
  (03-29) is not implemented: `qty_received` may never exceed `qty_ordered` on a PO line.
- "Sent to supplier" (03-24) does not exist: there is no outbox, so the order status vocabulary stops at
  `approved` and no notification is claimed. Same reason print/email/WhatsApp (03-28) are absent.
- Receipt lines carry `batch_no` only; condition (good/damaged/short), expiry and bin allocation (03-34) are not
  captured, so FEFO dimensions and the quality-reject report cannot be produced yet.
- Money is `decimal(18,4)` per line and re-derived on every save; a posted receipt is immutable and there is no
  reverse path until purchase returns (03-59) exist — the cancel guard says exactly that.
- Supplier "performance" on the profile is spend history from real documents, not a score: no formula, no score
  field, nothing presented as a rating (03-12).
- **Bill approval is the posting moment and it uses `purchase.bills.approve`** rather than a separate
  `purchase.bills.match` / Workflow step (03-50). The three-way match is computed on approval, stored on the bill
  and displayed — but with no configurable tolerance yet it *records* a mismatch instead of blocking, because
  refusing to book a liability the company really owes would push the truth off the system. The mismatch stays on
  the record and is shown in full on the bill.
- Input VAT is posted as a **debit to Tax Payable (2120)** — net-VAT treatment, so input and output VAT net on one
  account instead of a separate recoverable-VAT asset. A zero tax line is dropped rather than posted as 0.00.
- Goods-backed bills capitalise **Inventory (1140)**; direct/service bills land on **Purchases & Services (5225)**.
  Both routes are `posting_rules` rows (`purchase_bill_posted` / `purchase_bill_expense_posted`) seeded by
  `PurchaseCoreSeeder` — no account id appears in the service.
- Bill numbering is `BILL-00001` per company from the service (like `PO-` and `GRN-`), not NumberingService:
  purchase documents have no `document_types` row yet, so nothing claims a Mushak-style document type.

Baseline (`BL`) applies. Core rule honoured literally: draft PO creates **no stock and no liability**; stock posts
at GRN posting stage; liability will post at bill posting (Spec §13).

Shared services: this slice is `Purchase\Services\{PurchaseOrderService, GoodsReceiptService, SupplierService}` ·
`Purchase\Queries\PurchaseQuery` · stock truth via `Inventory\Services\StockLedgerService` + `ValuationService`.

## 3.1 Suppliers (Supplier records live in the masters surface, §06)

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-01 | Purchase › Suppliers › All Suppliers | `GET /app/suppliers` | `suppliers.view` | `SupplierController@index`, `PurchaseQuery::suppliers` (q/category/status/sort, paginated) | `suppliers` | AUD | `PurchaseFlowTest` (screen render + gate) | DONE |
| 03-02 | Purchase › Suppliers › Add Supplier | `GET|POST /app/suppliers/create` | `suppliers.create` | `StoreSupplierRequest` (code normalised upper-case, per-company unique ignoring self), `SupplierService@create` — duplicate name/BIN/phone refused | `suppliers` | AUD; code `SUP-00001` from `NumberingService` | `PurchaseFlowTest` (duplicate + blacklist gate) | DONE |
| 03-03 | Purchase › Suppliers › Import Suppliers / Export Suppliers | — | — | not built | — | — | — | NOT STARTED |
| 03-04 | Purchase › Suppliers › Supplier Profile | `GET /app/suppliers/{supplier}` | `suppliers.view` | `SupplierController@show` — open PO lines outstanding, recent orders, recent receipts, spend by month, blacklist banner with reason | `suppliers`, `purchase_orders`, `goods_receipts` | AUD | `PurchaseFlowTest` | DONE |
| 03-05 | Purchase › Suppliers › Contacts / Bank Details / TIN-BIN-Trade Licence | (sections of the supplier form) | `suppliers.edit` | `contact_person`/`phone`/`phone_alt`/`email`, `tin`/`bin`, `bank_name`/`bank_account_no`/`mobile_wallet` on the party record | `suppliers` | AUD diff | — | PARTIAL — single contact and destination only; no contacts table, no encrypted bank sub-record, no licence expiry tracking |
| 03-06 | Purchase › Suppliers › Supplier Categories | (filter + picker) | `suppliers.view` | `Supplier::CATEGORIES` (`goods, service, transport, utility, other`) enforced by `StoreSupplierRequest` | `suppliers.category` | — | — | PARTIAL — fixed vocabulary, no category CRUD screen |
| 03-07 | Purchase › Suppliers › Supplier Ledger | — | — | needs bills / AP journal (03-44) | — | — | — | NOT STARTED — the profile's "outstanding" table is a delivery view (open PO lines), not a GL ledger, and is labelled as such |
| 03-08 | Purchase › Suppliers › Payments / Record Payment / Payment History | — | — | — | — | — | — | NOT STARTED |
| 03-09 | Purchase › Suppliers › Advance Payments | — | — | — | — | — | — | NOT STARTED |
| 03-10 | Purchase › Suppliers › BEFTN Payments | — | — | — | — | — | — | NOT STARTED |
| 03-11 | Purchase › Suppliers › Payment Schedule | (terms on the party) | `suppliers.edit` | `payment_terms_days` + `credit_limit` captured and shown on the PO/receipt documents | `suppliers` | — | — | PARTIAL — terms exist, no schedule screen or due query |
| 03-12 | Purchase › Suppliers › Supplier Performance / Scoring | (spend panel on the profile) | `suppliers.view` | `PurchaseQuery::supplierSpend` (PHP month bucketing, real receipts only) | `goods_receipts` | — | — | PARTIAL — spend history only; on-time/quality/price-variance scoring not computed and not displayed |
| 03-13 | Purchase › Suppliers › Contracts / Documents | — | — | — | — | — | — | NOT STARTED |
| 03-14 | Purchase › Suppliers › Supplier Portal | — | — | — | — | — | — | NOT STARTED |
| 03-15 | Purchase › Suppliers › Supplier Settings | — | — | terms/dedupe rules live in code, not settings | — | — | — | NOT STARTED |

## 3.2 Purchase Requests

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-16 | Purchase › Purchase Requests › All Requests | — | — | next slice | — | — | — | NOT STARTED |
| 03-17 | Purchase › Purchase Requests › Create Request | — | — | next slice | — | — | — | NOT STARTED |
| 03-18 | Purchase › Purchase Requests › Pending / Approved / Rejected | — | — | next slice | — | — | — | NOT STARTED |
| 03-19 | Purchase › Purchase Requests › PR to PO Conversion | — | — | next slice | — | — | — | NOT STARTED |
| 03-20 | Purchase › Purchase Requests › PR History | — | — | next slice | — | — | — | NOT STARTED |
| 03-21 | Purchase › Purchase Requests › PR Analytics | — | — | next slice | — | — | — | NOT STARTED |

## 3.3 Purchase Orders

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-22 | Purchase › Purchase Orders › All Purchase Orders | `GET /app/purchase/orders` | `purchase.orders.view` | `PurchaseOrderController@index`, `PurchaseQuery::orders` — q (code/reference/supplier), `status=open|draft|pending_approval|approved|partially_received|received|cancelled`, supplier, date range, sort by value/oldest; branch-scoped | `purchase_orders`, `_lines` | AUD; KPI grid + 14-day receipt sparkline from real rows | `PurchaseFlowTest` (render + gate) | DONE |
| 03-23 | Purchase › Purchase Orders › Create PO | `GET|POST /app/purchase/orders/create` | `purchase.orders.create` | `StorePurchaseOrderRequest::payload()` drops qty ≤ 0 lines and rejects money fields on the header; `PurchaseOrderService@create` re-derives subtotal/discount/tax/total from lines and assigns `PO-00001` | `purchase_orders`, `purchase_order_lines`, `numbering_sequences` | no stock, no GL; AUD | `PurchaseFlowTest::test_order_totals_are_recomputed_from_the_lines` | DONE — no PDF/outbox (see 03-28) |
| 03-24 | Purchase › Purchase Orders › Pending / Approved / Cancelled | `?status=` facets on 03-22 | `purchase.orders.view` | status facets incl. `draft`, `pending_approval`, `approved`, `cancelled`; `submit` (create permission) and `approve` (`purchase.orders.approve`, creator refused in the service); `cancel` needs a reason | `purchase_orders` | direct permission, not WF; AUD | `PurchaseFlowTest::test_self_approval_is_refused…`, `…test_approval_route_is_gated_on_the_approve_permission` | PARTIAL — "sent to supplier" absent because no outbox exists |
| 03-25 | Purchase › Purchase Orders › Partially / Fully Received, Overdue | `?status=open` + receipt state | `purchase.orders.view` | `qty_received` per line drives `partially_received` / `received` via `GoodsReceiptService::syncOrderStatus`; `outstandingQty()` on order and line | `purchase_orders`, `_lines`, `goods_receipts` | stock state derived from posted receipts only | `PurchaseFlowTest::test_posting_a_receipt_moves_stock_and_advances_the_order`, `::test_receiving_everything_closes_the_order` | PARTIAL — no overdue scheduler/rule; expected date is shown and filterable by date range |
| 03-26 | Purchase › Purchase Orders › PO Amendment | — | — | — | — | — | — | NOT STARTED |
| 03-27 | Purchase › Purchase Orders › PO Duplication | — | — | — | — | — | — | NOT STARTED |
| 03-28 | Purchase › Purchase Orders › PO Print / Email / WhatsApp | — | — | no document renderer for PO in this slice | — | — | — | NOT STARTED |
| 03-29 | Purchase › Purchase Orders › PO to GRN Conversion | `GET /app/purchase/receipts/create?order={id}` | `purchase.receipts.create` | `GoodsReceiptController@create` prefills from an open PO; `GoodsReceiptService::create` refuses orders that are not `approved`/`partially_received` and refuses over-receipt per line; direct receipts (no PO) supported | `goods_receipts`, `_lines` | stock only at post; AUD | `PurchaseFlowTest::test_a_receipt_cannot_be_raised_against_an_unapproved_order`, `::test_over_receipt_is_refused_with_numbers` | DONE — tolerance setting intentionally not implemented |
| 03-30 | Purchase › Purchase Orders › PO to Bill Conversion | — | — | next change (bills) | — | — | — | NOT STARTED |
| 03-31 | Purchase › Purchase Orders › PO Analytics | (KPIs + sparkline on 03-22) | `purchase.orders.view` | `PurchaseQuery::summary` (open orders/value, awaiting approval, receipts this month), `receiptsByDay` | `purchase_orders`, `goods_receipts` | — | — | PARTIAL — on-screen indicators only; report routes (03-68…) not built |

## 3.4 Goods Receipt (GRN)

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-32 | Purchase › Goods Receipt › All GRNs / GRN History | `GET /app/purchase/receipts` | `purchase.receipts.view` | `GoodsReceiptController@index`, `PurchaseQuery::receipts` — q (code/challan/supplier), status, supplier, date range | `goods_receipts`, `_lines` | AUD; draft-vs-posted banner explains that stock is untouched | `PurchaseFlowTest` | DONE |
| 03-33 | Purchase › Goods Receipt › Create GRN / Per-Product Receiving | `GET|POST /app/purchase/receipts/create` | `purchase.receipts.create` | `StoreGoodsReceiptRequest` (`received_date` ≤ today, lines required), `GoodsReceiptService@create` validates every line against the PO's outstanding quantity and computes line totals | `goods_receipts`, `_lines`, `purchase_order_lines` | AUD | `PurchaseFlowTest::test_posting_a_receipt_moves_stock_and_advances_the_order` | DONE |
| 03-34 | Purchase › Goods Receipt › Condition / Batch / Expiry / Bin | `batch_no` on the receipt line | `purchase.receipts.create` | `batch_no` captured and displayed | `goods_receipt_lines.batch_no` | — | — | PARTIAL — no condition, expiry or bin dimension, so no FEFO or reject-rate input |
| 03-35 | Purchase › Goods Receipt › GRN Amendment | — | — | posted receipts are immutable by design; no compensating path yet | — | — | — | NOT STARTED — depends on purchase returns (03-59) |
| 03-36 | Purchase › Goods Receipt › GRN to Stock Update | `POST /app/purchase/receipts/{receipt}/post` | `purchase.receipts.post` | `GoodsReceiptService@post` — one `StockLedgerService::post()` per line (`TYPE_PURCHASE_RECEIPT`, `source_type=goods_receipt`, idempotency key `grn:{id}:line:{lineId}`), valuation layer at the cost actually paid, then `syncOrderStatus` | `stock_movements`, `stock_balances`, valuation layers, `goods_receipts` | STK; idempotent — a retry reuses the same key; AUD | `PurchaseFlowTest::test_posting_a_receipt_moves_stock…`, `::test_stock_ledger_rebuild_agrees_with_the_live_balance`, `::test_receipt_codes_are_unique_per_company` | DONE |
| 03-37 | Purchase › Goods Receipt › GRN to Bill Conversion | — | — | next change (bills) | — | — | — | NOT STARTED |
| 03-38 | Purchase › Goods Receipt › GRN Print | — | — | no document renderer in this slice | — | — | — | NOT STARTED |

## 3.5 Supplier Quotations (RFQ)

| Ref | Menu path | Route | Permission | Status |
|---|---|---|---|---|
| 03-39 | Purchase › Supplier Quotations › All RFQs / Create RFQ | — | — | NOT STARTED |
| 03-40 | Purchase › Supplier Quotations › Send to Multiple Suppliers | — | — | NOT STARTED |
| 03-41 | Purchase › Supplier Quotations › Quotation Comparison | — | — | NOT STARTED |
| 03-42 | Purchase › Supplier Quotations › Select Winner / Auto-Create PO | — | — | NOT STARTED |
| 03-43 | Purchase › Supplier Quotations › RFQ History | — | — | NOT STARTED |

## 3.6 Purchase Bills

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-44 | Purchase › Purchase Bills › All Bills | `GET /app/purchase/bills` | `purchase.bills.view` | `PurchaseBillController@index`, `PurchaseQuery::bills` + `billSummary` — search (code / supplier bill no / supplier), status (`open`, `overdue`, `draft`, `pending_approval`, `approved`, `paid`, `cancelled`), supplier, bill-date range, sort by due date or value | `purchase_bills`, `_lines` | AUD; KPI grid (payable, overdue, due within 7 days, awaiting approval) from posted bills | `PurchaseBillTest::test_payables_reach_the_supplier_profile_and_the_bill_summary` | DONE |
| 03-45 | Purchase › Purchase Bills › Create Bill / Bill from GRN | `GET|POST /app/purchase/bills/create` | `purchase.bills.create` | `StorePurchaseBillRequest::payload()` (no header money at all), `PurchaseBillService@create` / `@createFromReceipt` — only a **posted** receipt can be billed, and only once (the picker lists posted receipts that have no bill yet); due date from supplier terms | `purchase_bills`, `purchase_bill_lines`, `purchase_orders` | AUD; `BILL-00001` per company | `PurchaseBillTest::test_bill_totals_come_from_the_lines_and_the_due_date_from_terms`, `::test_billing_a_receipt_copies_its_lines_and_links_the_document`, `::test_only_a_posted_receipt_can_be_billed` | DONE |
| 03-46 | Purchase › Purchase Bills › Pending / Paid / Overdue | `?status=` facets on 03-44 | `purchase.bills.view` | status facets including `overdue` (open + due date in the past) and `pending_approval`; `submit`, `approve` (`purchase.bills.approve`, maker never approves own bill), `cancel` (unposted only, reason required) | `purchase_bills` | ACCT on approval; AUD | `PurchaseBillTest::test_the_maker_cannot_approve_their_own_bill`, `::test_cancelling_an_unposted_bill_needs_a_reason_and_posted_bills_are_immutable` | DONE |
| 03-47 | Purchase › Purchase Bills › Bill Payment Recording | — | — | needs the supplier-payment slice (03-52) — `due_amount` is ready for allocation but nothing settles it yet | — | — | — | NOT STARTED |
| 03-48 | Purchase › Purchase Bills › Bill Aging | (ageing on the supplier profile + `?sort=due`) | `suppliers.view` / `purchase.bills.view` | `PurchaseQuery::supplierPayables` — current / 1–30 / 31–60 / 61–90 / 90+ buckets derived from each bill's own due date; a bill with no due date is never counted late | `purchase_bills` | — | `PurchaseBillTest::test_payables_reach_the_supplier_profile_and_the_bill_summary` | PARTIAL — buckets and the open-bill table exist; no dedicated report route (03-51) |
| 03-49 | Purchase › Purchase Bills › Bill Print | — | — | no document renderer in this slice | — | — | — | NOT STARTED |
| 03-50 | Purchase › Purchase Bills › 3-Way Match | shown on `GET /app/purchase/bills/{bill}` | `purchase.bills.view` (match runs under `purchase.bills.approve`) | `PurchaseBillService::runThreeWayMatch` → `match_state` + human `match_summary` stored on the bill; `PurchaseQuery::matchRows` renders ordered / received / billed quantities and prices per line | `purchase_bills`, `purchase_bill_lines`, `purchase_order_lines`, `goods_receipt_lines` | ACCT only if approved; AUD | `PurchaseBillTest::test_approval_posts_a_balanced_payable_journal_entry`, `::test_billing_more_than_was_received_is_recorded_as_a_mismatch_not_swallowed` | DONE — permission is `purchase.bills.approve`, tolerances not configurable (records, does not block) |
| 03-51 | Purchase › Purchase Bills › Bill Reports | — | — | — | — | — | — | NOT STARTED |

## 3.7 Supplier Payments

| Ref | Menu path | Route | Permission | Status |
|---|---|---|---|---|
| 03-52 | Purchase › Supplier Payments › Record / History | — | — | NOT STARTED |
| 03-53 | Purchase › Supplier Payments › Advance Payments / Adjustment | — | — | NOT STARTED |
| 03-54 | Purchase › Supplier Payments › BEFTN | — | — | NOT STARTED |
| 03-55 | Purchase › Supplier Payments › Payment Schedule | — | — | NOT STARTED |
| 03-56 | Purchase › Supplier Payments › Payment Approval | — | — | NOT STARTED |
| 03-57 | Purchase › Supplier Payments › Payment Reminder | — | — | NOT STARTED |
| 03-58 | Purchase › Supplier Payments › Payment Reports | — | — | NOT STARTED |

## 3.8 Supplier Returns

| Ref | Menu path | Route | Permission | Status |
|---|---|---|---|---|
| 03-59 | Purchase › Supplier Returns › All / Create Return | — | — | NOT STARTED |
| 03-60 | Purchase › Supplier Returns › Return Authorization | — | — | NOT STARTED |
| 03-61 | Purchase › Supplier Returns › Auto Debit Note | — | — | NOT STARTED |
| 03-62 | Purchase › Supplier Returns › Shipment Tracking | — | — | NOT STARTED |
| 03-63 | Purchase › Supplier Returns › Supplier Ledger Credit | — | — | NOT STARTED |
| 03-64 | Purchase › Supplier Returns › History / Reports | — | — | NOT STARTED |

## 3.9 Import Purchase & Reports

| Ref | Menu path | Route | Permission | Status |
|---|---|---|---|---|
| 03-65 | Purchase › Import Purchase › LC Records / Create LC | — | — | NOT STARTED |
| 03-66 | Purchase › Import Purchase › HS Code / Duty / Clearing Agent | — | — | NOT STARTED |
| 03-67 | Purchase › Import Purchase › Landing Cost | — | — | NOT STARTED |
| 03-68 | Purchase › Reports › Purchase Summary | — | — | NOT STARTED |
| 03-69 | Purchase › Reports › Purchase by Supplier / Product | — | — | NOT STARTED |
| 03-70 | Purchase › Reports › Price Variance | — | — | NOT STARTED |
| 03-71 | Purchase › Reports › On-Time Delivery Rate | — | — | NOT STARTED |
| 03-72 | Purchase › Reports › Quality Reject Rate | — | — | NOT STARTED (needs 03-34) |
| 03-73 | Purchase › Reports › Purchase Trend | — | — | NOT STARTED |

## Slice scorecard

| | Count |
|---|---|
| DONE | 13 (03-01, 03-02, 03-04, 03-22, 03-23, 03-29, 03-32, 03-33, 03-36, 03-44, 03-45, 03-46, 03-50) |
| PARTIAL | 9 (03-05, 03-06, 03-11, 03-12, 03-24, 03-25, 03-31, 03-34, 03-48) |
| NOT STARTED | 51 |

Stock consequence of the slice: **purchase is now the only inbound stock path with documents behind it** — a receipt
posts an immutable `purchase_receipt` movement and a valuation layer at the cost actually paid, and the cached
balance provably replays from the ledger (`rebuildBalances` test). Nothing else in purchasing moves stock.

Money consequence of the bill slice: **a delivery now becomes a liability through one auditable path** — approve a
bill and a balanced journal entry exists (Dr inventory or purchases, Dr input tax, Cr accounts payable) with the
entry number stamped on the bill, the supplier's balance ageing from the bill's own due date, and the three-way
match result stored beside it. Nothing else in purchasing touches the ledger.
