# 03. PURCHASE — Traceability

Baseline (`BL`) applies. Shared services: `Purchase\Actions\{CreatePurchaseRequest, ConvertPrToPo, CreatePurchaseOrder, ApprovePoPath... (via Workflow), SendPoToSupplier, AmendPurchaseOrder, DuplicatePurchaseOrder, CreateGrn, RecordGrnLineCondition, ConvertGrnToBill, MatchThreeWay, CreateSupplierReturn, RecordSupplierPayment, AdjustAdvance, CreateLcRecord, ComputeLandingCost, BulkGenerateBeftn}` · `Purchase\Services\{PurchaseStateMachine, ThreeWayMatcher, SupplierPerformanceCalculator, LandingCostService}` · `Purchase\Queries\{PurchaseOrderQuery, GrnQuery, BillQuery, SupplierPaymentQuery}`.

Core rule: draft PR/PO creates **no stock and no liability**. Stock posts at GRN receipt stage; liability at bill posting (Spec §13).

## 3.1 Suppliers (Purchase menu branch)

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-01 | Purchase › Suppliers › All Suppliers | `GET /purchase/suppliers` | `purchase.suppliers.view` | `SupplierController@index`, `SupplierQuery` | `suppliers`, `supplier_categories` | AUD | `SupplierListTest`, `SupplierScopeTest` | PLANNED |
| 03-02 | Purchase › Suppliers › Add Supplier | `GET|POST /purchase/suppliers/create` | `purchase.suppliers.create` | `CreateSupplier` (FormRequest: TIN/BIN/trade license validation) | `suppliers`, `supplier_contacts` | AUD | `CreateSupplierTest` | PLANNED |
| 03-03 | Purchase › Suppliers › Import Suppliers / Export Suppliers | `POST /purchase/suppliers/import`, `GET .../export` | `purchase.suppliers.import/export` | `SupplierImporter` (queued, validated, per-row errors), `SupplierExporter` | `import_batches`, `suppliers` | AUD: import/export rows | `SupplierImportTest` (bad-row rejection), `SupplierExportScopeTest` | PLANNED |
| 03-04 | Purchase › Suppliers › Supplier Profile | `GET /purchase/suppliers/{id}` | `purchase.suppliers.view` | `SupplierController@show` (contacts, bank, ledger summary, performance) | `suppliers` + related | AUD sensitive view | `SupplierProfileTest`, `SupplierIdorTest` | PLANNED |
| 03-05 | Purchase › Suppliers › Supplier Contacts / Bank Details / TIN-BIN-Trade License | `GET|PUT /purchase/suppliers/{id}/{contacts,bank,legal}` | `purchase.suppliers.edit` | `UpdateSupplier` sections; bank details encrypted cast | `supplier_contacts`, `supplier_bank_details`, `suppliers` | AUD diff; secrets never in logs | `SupplierContactTest`, `SupplierBankEncryptedTest` | PLANNED |
| 03-06 | Purchase › Suppliers › Supplier Categories | `GET|POST /purchase/supplier-categories` | `purchase.suppliers.categories` | `SupplierCategoryController` | `supplier_categories` | AUD | `SupplierCategoryTest` | PLANNED |
| 03-07 | Purchase › Suppliers › Supplier Ledger | `GET /purchase/suppliers/{id}/ledger` | `accounting.ledger.view` | `LedgerService@supplierLedger` (journal-derived + running balance + rebuild) | `journal_lines`, `payment_allocations`, `running_balances` | ACCT-derived; DOC printable statement; AUD | `SupplierLedgerTest` (balance=GL), `SupplierLedgerRebuildAfterBackdatedTest` | PLANNED |
| 03-08 | Purchase › Suppliers › Supplier Payments / Record Supplier Payment / Payment History | `GET|POST /purchase/suppliers/{id}/payments` | `purchase.payments.create/view` | `RecordSupplierPayment` (idempotency, allocation to bills/advances) | `payments`, `payment_allocations`, `journal_entries` | WF if threshold; ACCT: Dr AP, Cr Cash/Bank; AUD; NOT receipt | `RecordSupplierPaymentTest`, `SupplierPaymentIdempotencyTest` | PLANNED |
| 03-09 | Purchase › Suppliers › Advance Payments | `GET|POST /purchase/advances` | `purchase.payments.advance` | advance posted to supplier advance account, adjusted later | `payments`, `journal_entries`, `payment_allocations` | ACCT; WF if configured; AUD | `SupplierAdvanceTest`, `AdvanceAdjustmentTest` | PLANNED |
| 03-10 | Purchase › Suppliers › BEFTN Payments | `GET|POST /purchase/beftn` | `purchase.payments.beftn` | `BulkGenerateBeftn` (bank-format file builder, download gated) | `beftn_batches`, `beftn_lines`, `payments` | DOC file; WF approval before generation; AUD download | `BeftnFileGenerationTest`, `BeftnApprovalGateTest` | PLANNED |
| 03-11 | Purchase › Suppliers › Payment Schedule | `GET /purchase/payment-schedule` | `purchase.payments.view` | schedule CRUD + due queries | `supplier_payment_schedules` | NOT due reminders via rules | `SupplierPaymentScheduleTest` | PLANNED |
| 03-12 | Purchase › Suppliers › Supplier Performance / Supplier Scoring | `GET /purchase/suppliers/performance` | `purchase.suppliers.performance` | `SupplierPerformanceCalculator` (on-time, quality, price variance — formulas exposed) | `supplier_performance_scores`, `grns`, `purchase_bills` | BI explainability fields | `SupplierScoringTest` (formula+sample exposed) | PLANNED |
| 03-13 | Purchase › Suppliers › Supplier Contracts / Supplier Documents | `GET|POST /purchase/suppliers/{id}/contracts` | `purchase.suppliers.contracts` | contract/document CRUD via secure upload pipeline | `supplier_contracts`, `supplier_documents`, `documents` | AUD; DOC links | `SupplierContractTest`, `SupplierDocumentUploadSecurityTest` | PLANNED |
| 03-14 | Purchase › Suppliers › Supplier Portal | `GET /supplier-portal/*` (isolated, config-gated) | `purchase.suppliers.portal` (+ portal credentials, isolated data views) | `SupplierPortal\PortalController` (only own POs/bills/payments; permission/configuration driven, isolated from core ERP — Spec §48-E) | `suppliers`, read-models, portal sessions | AUD portal login/access; no cross-supplier IDOR | `SupplierPortalIsolationTest`, `SupplierPortalDisabledByDefaultTest` | PLANNED |
| 03-15 | Purchase › Suppliers › Supplier Settings | `GET|PUT /settings/suppliers` | `purchase.suppliers.configure` | settings (default terms, tolerances, portal toggle) | `settings` | AUD config | `SupplierSettingsTest` | PLANNED |

## 3.2 Purchase Requests

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-16 | Purchase › Purchase Requests › All Requests | `GET /purchase/requests` | `purchase.requests.view` | `PurchaseRequestController@index` | `purchase_requests`, `_lines` | AUD | `PurchaseRequestListTest` | PLANNED |
| 03-17 | Purchase › Purchase Requests › Create Request | `GET|POST /purchase/requests/create` | `purchase.requests.create` | `CreatePurchaseRequest` → `ApprovalEngine->submit` | `purchase_requests`, `_lines`, `approval_requests` | WF always available (threshold rules); AUD | `CreatePurchaseRequestTest`, `PrApprovalRoutingTest` | PLANNED |
| 03-18 | Purchase › Purchase Requests › Pending / Approved / Rejected Requests | `?status={pending,approved,rejected}` | `purchase.requests.view` | status facets via `TransitionService` + status registry | `purchase_requests`, `approval_requests` | WF states | `PrStatusFacetsTest` (all three) | PLANNED |
| 03-19 | Purchase › Purchase Requests › PR to PO Conversion | `POST /purchase/requests/{id}/convert` | `purchase.orders.create` | `ConvertPrToPo` (line mapping, remaining-qty guard, provenance) | `purchase_orders.source_request_id`, lines | no stock/liability yet; AUD | `PrToPoConversionTest` (no double conversion) | PLANNED |
| 03-20 | Purchase › Purchase Requests › PR History | `GET /purchase/requests/{id}/history` | `purchase.requests.view` | approval + transition history view | `approval_history`, `audit_events` | AUD read | `PrHistoryTest` | PLANNED |
| 03-21 | Purchase › Purchase Requests › PR Analytics | `GET /reports/purchase/pr-analytics` | `purchase.reports.view` | `Reporting\PrAnalytics` (approval time, conversion rate, spend by requester) | `purchase_requests` | BI explainable | `PrAnalyticsTest` | PLANNED |

## 3.3 Purchase Orders

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-22 | Purchase › Purchase Orders › All Purchase Orders | `GET /purchase/orders` | `purchase.orders.view` | `PurchaseOrderController@index`, `PurchaseOrderQuery` | `purchase_orders`, `_lines` | AUD | `PoListTest`, `PoScopeTest` | PLANNED |
| 03-23 | Purchase › Purchase Orders › Create PO | `GET|POST /purchase/orders/create` | `purchase.orders.create` | `CreatePurchaseOrder` (Pricing from supplier terms, totals server-side, numbering) | `purchase_orders`, `_lines`, `numbering_sequences`, `approval_requests` | WF: submit; **no stock, no GL**; NOT supplier PO message (pending wording per §11); DOC PO PDF; AUD | `CreatePoTest`, `DraftPoNoStockNoGlTest`, `PoCreatedNotificationPendingWordingTest` | PLANNED |
| 03-24 | Purchase › Purchase Orders › Pending / Approved / Sent to Supplier / Cancelled POs | `?status={pending,approved,sent,cancelled}` | `purchase.orders.view` | facets; `SendPoToSupplier` (outbox + PDF, truthful state) | `purchase_orders`, `outbox_messages` | WF: approved → confirmation notification (2nd, configurable); AUD | `PoStatusFacetsTest`, `PoApprovedConfirmationNotificationTest`, `PoSentTruthfulTest` | PLANNED |
| 03-25 | Purchase › Purchase Orders › Partially Received / Fully Received / Overdue Delivery | `?status={partially_received,fully_received,overdue}` | `purchase.orders.view` | receipt state from GRN aggregation; overdue by scheduler | `purchase_orders`, `grns` | NOT overdue reminder rule | `PoReceiptStatesTest`, `PoOverdueSchedulerTest` | PLANNED |
| 03-26 | Purchase › Purchase Orders › PO Amendment | `POST /purchase/orders/{id}/amend` | `purchase.orders.amend` | `AmendPurchaseOrder` (revision chain, original immutable, approval restart on material change) | `purchase_orders` (`amendment_of`) | WF snapshot invalidation; AUD | `PoAmendmentTest`, `PoAmendmentRestartsApprovalTest` | PLANNED |
| 03-27 | Purchase › Purchase Orders › PO Duplication | `POST /purchase/orders/{id}/duplicate` | `purchase.orders.create` | `DuplicatePurchaseOrder` (new number, provenance) | `purchase_orders` | AUD | `PoDuplicateTest` | PLANNED |
| 03-28 | Purchase › Purchase Orders › PO Print / Email / WhatsApp | `GET|POST /purchase/orders/{id}/{print,email,whatsapp}` | `purchase.orders.print/notify` | `DocumentRenderer@purchase_order`; outbox | `documents`, `print_history`, `outbox_messages` | DOC; NOT truthful; AUD | `PoPrintTest` (title Purchase Order), `PoEmailOutboxTest`, `PoWhatsAppOutboxTest` | PLANNED |
| 03-29 | Purchase › Purchase Orders › PO to GRN Conversion | `POST /purchase/orders/{id}/receive` | `purchase.grn.create` | `CreateGrn` from PO (over-receipt tolerance from settings) | `grns`, `_lines` | STK at GRN post; AUD | `PoToGrnTest`, `OverReceiptBlockedWithoutPermissionTest` | PLANNED |
| 03-30 | Purchase › Purchase Orders › PO to Bill Conversion | `POST /purchase/grns/{id}/bill` (from PO view) | `purchase.bills.create` | `ConvertGrnToBill` | `purchase_bills`, `_lines` | ACCT at bill posting; WF if configured; AUD | `PoToBillTest` | PLANNED |
| 03-31 | Purchase › Purchase Orders › PO Analytics | `GET /reports/purchase/po-analytics` | `purchase.reports.view` | `Reporting\PoAnalytics` | `purchase_orders`, `grns` | BI explainable | `PoAnalyticsTest` | PLANNED |

## 3.4 Goods Receipt (GRN)

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-32 | Purchase › Goods Receipt › All GRNs / GRN History | `GET /purchase/grns` | `purchase.grn.view` | `GrnController@index` + history | `grns`, `_lines` | AUD | `GrnListTest` | PLANNED |
| 03-33 | Purchase › Goods Receipt › Create GRN / Per-Product Receiving | `GET|POST /purchase/grns/create` | `purchase.grn.create` | `CreateGrn` (per-line received qty vs ordered, shortage/excess flags) | `grns`, `_lines`, `purchase_orders` | STK on post: PURCHASE_RECEIPT movement; AUD | `CreateGrnTest`, `GrnShortageDetectionTest`, `GrnExcessDetectionTest` | PLANNED |
| 03-34 | Purchase › Goods Receipt › Condition Recording / Batch Number Entry / Expiry Date Entry / Bin Location Assignment | (GRN line facets) `POST /purchase/grns/{id}/lines` | `purchase.grn.create` | GRN line capture → `batches`, `serials`, `bins` linkage in `StockLedgerService` | `grns`, `_lines`, `batches`, `bins` | STK with batch/expiry/bin dimensions; FEFO ordering | `GrnConditionCaptureTest`, `GrnBatchExpiryBinTest` | PLANNED |
| 03-35 | Purchase › Goods Receipt › GRN Amendment | `POST /purchase/grns/{id}/amend` | `purchase.grn.amend` | `AmendGrn` (compensating movement if posted — never rewrite ledger) | `grns`, `stock_movements` | STK compensating only; WF if configured; AUD | `GrnAmendmentReversalTest` (ledger append-only) | PLANNED |
| 03-36 | Purchase › Goods Receipt › GRN to Stock Update | `POST /purchase/grns/{id}/post` | `purchase.grn.post` | `PostGrn` idempotent | `grns`, `stock_movements`, `stock_balances`, `stock_layers` | STK receipt + valuation layers; ACCT if perpetual (Dr Inventory, Cr GRN-IR); idempotent | `GrnPostIdempotencyTest`, `GrnValuationLayerTest` | PLANNED |
| 03-37 | Purchase › Goods Receipt › GRN to Bill Conversion | (see 03-30) | `purchase.bills.create` | `ConvertGrnToBill` | `purchase_bills` | ACCT at bill post | `GrnToBillTest` | PLANNED |
| 03-38 | Purchase › Goods Receipt › GRN Print | `GET /purchase/grns/{id}/print` | `purchase.grn.print` | `DocumentRenderer@grn` | `documents`, `print_history` | DOC; AUD | `GrnPrintTest` | PLANNED |

## 3.5 Supplier Quotations (RFQ)

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-39 | Purchase › Supplier Quotations › All RFQs / Create RFQ | `GET|POST /purchase/rfq` | `purchase.rfq.view/create` | `CreateRfq`, `SendRfqToSuppliers` (outbox per supplier) | `rfqs`, `_lines`, `outbox_messages` | NOT truthful; AUD | `CreateRfqTest`, `RfqBroadcastTest` | PLANNED |
| 03-40 | Purchase › Supplier Quotations › Send to Multiple Suppliers | `POST /purchase/rfq/{id}/send` | `purchase.rfq.send` | `SendRfqToSuppliers` (dedupe, opt-out respected) | `rfqs`, `supplier_quotations` invitations | NOT | `RfqSendMultipleTest` | PLANNED |
| 03-41 | Purchase › Supplier Quotations › Quotation Comparison | `GET /purchase/rfq/{id}/compare` | `purchase.rfq.view` | `SaveRfqComparison` (snapshot matrix: price, lead time, terms) | `rfq_comparisons`, `supplier_quotations`, `_lines` | AUD snapshot | `RfqComparisonTest` (snapshot persists) | PLANNED |
| 03-42 | Purchase › Supplier Quotations › Select Winner / Auto-Create PO | `POST /purchase/rfq/{id}/select-winner` | `purchase.orders.create` | `SelectRfqWinner` → `ConvertRfqToPo` (provenance) | `supplier_quotations`, `purchase_orders` | WF if threshold; AUD winner decision | `RfqWinnerTest`, `RfqAutoPoTest` | PLANNED |
| 03-43 | Purchase › Supplier Quotations › RFQ History | `GET /purchase/rfq/{id}/history` | `purchase.rfq.view` | history from approval+audit trail | `audit_events`, `rfqs` | AUD read | `RfqHistoryTest` | PLANNED |

## 3.6 Purchase Bills

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-44 | Purchase › Purchase Bills › All Bills | `GET /purchase/bills` | `purchase.bills.view` | `BillController@index` | `purchase_bills`, `_lines` | AUD | `BillListTest` | PLANNED |
| 03-45 | Purchase › Purchase Bills › Create Bill / Bill from GRN | `GET|POST /purchase/bills/create` | `purchase.bills.create` | `CreatePurchaseBill` (from GRN or standalone w/ justification), totals server-side | `purchase_bills`, `_lines`, `approval_requests` | WF if configured; ACCT at post: Dr Inventory/Expense/Asset, Cr AP; AUD | `CreateBillTest`, `BillPostingBalancedTest`, `StandaloneBillRequiresJustificationTest` | PLANNED |
| 03-46 | Purchase › Purchase Bills › Pending / Paid / Overdue Bills | `?status={pending,paid,overdue}` | `purchase.bills.view` | facets + scheduler | `purchase_bills` | NOT payment due reminders | `BillStatusFacetsTest`, `BillOverdueTest` | PLANNED |
| 03-47 | Purchase › Purchase Bills › Bill Payment Recording | `POST /purchase/bills/{id}/payments` | `purchase.payments.create` | `ApplyPaymentAllocation` (bill side) | `payments`, `payment_allocations` | ACCT; idempotent; AUD | `BillPaymentAllocationTest` | PLANNED |
| 03-48 | Purchase › Purchase Bills › Bill Aging | `GET /reports/purchase/bill-aging` | `purchase.reports.view` | `Reporting\AgingQuery` (AP side) | `purchase_bills`, `journal_lines` | DOC export | `BillAgingTest` | PLANNED |
| 03-49 | Purchase › Purchase Bills › Bill Print | `GET /purchase/bills/{id}/print` | `purchase.bills.print` | `DocumentRenderer@purchase_bill` | `documents`, `print_history` | DOC; AUD | `BillPrintTest` | PLANNED |
| 03-50 | Purchase › Purchase Bills › 3-Way Match | `GET /purchase/bills/{id}/match` | `purchase.bills.match` | `ThreeWayMatcher` (PO↔GRN↔Bill qty/price tolerances from settings; match state persisted) | `purchase_bills` (`match_state`), `grns`, `purchase_orders` | block/auto-flag beyond tolerance; WF override; AUD | `ThreeWayMatchTest` (within/beyond tolerance), `ThreeWayMatchOverrideApprovalTest` | PLANNED |
| 03-51 | Purchase › Purchase Bills › Bill Reports | `GET /reports/purchase/bills` | `purchase.reports.view` | `Reporting\BillReport` | `purchase_bills` | DOC export | `BillReportTest` | PLANNED |

## 3.7 Supplier Payments (module menu)

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-52 | Purchase › Supplier Payments › Record Payment / Payment History | (routes of 03-08) | `purchase.payments.create/view` | `RecordSupplierPayment` | `payments`, `journal_entries` | ACCT; AUD | `SupplierPaymentMenuTest` (same service, no duplicate logic) | PLANNED |
| 03-53 | Purchase › Supplier Payments › Advance Payments / Advance Adjustment | (routes of 03-09) | `purchase.payments.advance` | `AdjustAdvance` (apply advance to bills) | `payments`, `payment_allocations` | ACCT; AUD | `AdvanceAdjustmentTest` | PLANNED |
| 03-54 | Purchase › Supplier Payments › BEFTN Payments | (route of 03-10) | `purchase.payments.beftn` | `BulkGenerateBeftn` | `beftn_batches` | DOC; WF gate | `BeftnMenuTest` | PLANNED |
| 03-55 | Purchase › Supplier Payments › Payment Schedule | (route of 03-11) | `purchase.payments.view` | schedule reader | `supplier_payment_schedules` | NOT reminders | `PaymentScheduleMenuTest` | PLANNED |
| 03-56 | Purchase › Supplier Payments › Payment Approval | `GET /purchase/payments/approvals` | `purchase.payments.approve` | ApprovalEngine inbox for supplier payments | `approval_requests`, `approval_steps` | WF: maker-checker, SoD; AUD | `SupplierPaymentApprovalTest`, `SupplierPaymentSelfApprovalBlockedTest` | PLANNED |
| 03-57 | Purchase › Supplier Payments › Supplier Payment Reminder | `POST /purchase/payments/remind` | `purchase.payments.remind` | outbox reminder job from due rules | `outbox_messages`, `notification_rules` | NOT truthful | `PaymentReminderTest` | PLANNED |
| 03-58 | Purchase › Supplier Payments › Payment Reports | `GET /reports/purchase/payments` | `purchase.reports.view` | `Reporting\SupplierPaymentReport` | `payments` | DOC export | `SupplierPaymentReportTest` | PLANNED |

## 3.8 Supplier Returns

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-59 | Purchase › Supplier Returns › All Returns / Create Return | `GET|POST /purchase/returns` | `purchase.returns.view/create` | `CreateSupplierReturn` (links GRN/batch/serial) | `purchase_returns`, `_lines` | WF: return authorization; STK at dispatch: PURCHASE_RETURN_OUT; AUD | `CreateSupplierReturnTest`, `ReturnAuthorizationApprovalTest` | PLANNED |
| 03-60 | Purchase › Supplier Returns › Return Authorization | (workflow facet of 03-59) | `purchase.returns.authorize` | ApprovalEngine step | `approval_requests` | WF | `ReturnAuthorizationTest` | PLANNED |
| 03-61 | Purchase › Supplier Returns › Auto Debit Note | `POST /purchase/returns/{id}/debit-note` | `sales.debit_notes.create` | `CreateDebitNote` auto-linked to return | `debit_notes`, `purchase_returns`, `journal_entries` | ACCT: reduce AP; AUD | `SupplierReturnDebitNoteTest` | PLANNED |
| 03-62 | Purchase › Supplier Returns › Return Shipment Tracking | `GET /purchase/returns/{id}/tracking` | `purchase.returns.view` | shipment/tracking link | `purchase_returns`, `tracking_events` | NOT status | `SupplierReturnTrackingTest` | PLANNED |
| 03-63 | Purchase › Supplier Returns › Supplier Ledger Credit | (effect of 03-61 posting) | `accounting.ledger.view` | `LedgerService` reflects credit | `journal_lines`, `payment_allocations` | ACCT credit visible in ledger | `SupplierLedgerCreditTest` | PLANNED |
| 03-64 | Purchase › Supplier Returns › Return History / Return Reports | `GET /purchase/returns/history`, `/reports/purchase/returns` | `purchase.returns.view` / `purchase.reports.view` | history + `Reporting\PurchaseReturnReport` | `purchase_returns` | AUD read; DOC export | `SupplierReturnHistoryTest`, `SupplierReturnReportTest` | PLANNED |

## 3.9 Import Purchase (LC)

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-65 | Purchase › Import Purchase › All LC Records / Create LC | `GET|POST /purchase/lc` | `purchase.lc.view/create` | `CreateLcRecord` (LC number/date, supplier, currency) | `lc_records`, `currencies`, `exchange_rates` | WF if amount threshold; ACCT FX via posting rules; AUD | `CreateLcTest` | PLANNED |
| 03-66 | Purchase › Import Purchase › HS Code / Customs Duty / Clearing Agent | `GET|PUT /purchase/lc/{id}` | `purchase.lc.edit` | `UpdateLcRecord` + duty capture | `lc_records`, `landing_cost_items` | ACCT: duty → landed cost | `LcDutyCaptureTest` | PLANNED |
| 03-67 | Purchase › Import Purchase › Landing Cost Calculation | `POST /purchase/lc/{id}/landing-cost` | `purchase.lc.landing_cost` | `LandingCostService` (duty+freight+clearing+insurance → per-unit cost, formula shown) | `landing_cost_items`, `stock_layers` (cost update path) | STK valuation impact; ACCT capitalization; BI explainability of buildup | `LandingCostCalculationTest`, `LandingCostAllocatesToUnitsTest` | PLANNED |

## 3.10 Purchase Reports

| Ref | Menu path | Route | Permission | Backend | DB entities | WF / Effects | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| 03-68 | Purchase › Purchase Reports › Purchase Summary | `GET /reports/purchase/summary` | `purchase.reports.view` | `Reporting\PurchaseSummaryReport` | `purchase_bills`, `purchase_orders` | DOC export/print/PDF; AUD export | `PurchaseSummaryTest` (totals=source) | PLANNED |
| 03-69 | Purchase › Reports › Purchase by Supplier / by Product | `GET /reports/purchase/by-{supplier,product}` | `purchase.reports.view` | `Reporting\PurchaseBreakdownReport` | `purchase_bills`, `_lines`, `suppliers`, `products` | drill | `PurchaseBySupplierTest`, `PurchaseByProductTest` | PLANNED |
| 03-70 | Purchase › Reports › Price Variance | `GET /reports/purchase/price-variance` | `purchase.reports.view` | variance PO price vs bill price vs standard | `purchase_orders`, `purchase_bills`, `products` | BI anomaly factor (IQR flagging when history suffices) | `PriceVarianceReportTest` | PLANNED |
| 03-71 | Purchase › Reports › On-Time Delivery Rate | `GET /reports/purchase/on-time` | `purchase.reports.view` | promised vs received dates | `purchase_orders`, `grns`, `supplier_performance_scores` | BI with sample size | `OnTimeDeliveryReportTest` | PLANNED |
| 03-72 | Purchase › Reports › Quality Reject Rate | `GET /reports/purchase/quality-reject` | `purchase.reports.view` | condition captures → reject ratio | `grns`, `_lines`, `purchase_returns` | BI | `QualityRejectReportTest` | PLANNED |
| 03-73 | Purchase › Reports › Purchase Trend | `GET /reports/purchase/trend` | `purchase.reports.view` | `Reporting\TrendQuery` (min-sample gate) | `bi_metrics_daily` | BI method/sample exposed | `PurchaseTrendTest` | PLANNED |
