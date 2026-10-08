<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Courier;
use App\Domain\Masters\Customer;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\Services\TaxService;
use App\Domain\Notification\MessageTemplate;
use App\Domain\People\Employee;
use App\Domain\Reporting\AgingReportService;
use App\Domain\Reporting\OrderExport;
use App\Domain\Reporting\OrderExporter;
use App\Domain\Reporting\PeakHoursQuery;
use App\Domain\Reporting\PromotionReport;
use App\Domain\Reporting\SalesBreakdownReport;
use App\Domain\Reporting\SalesSummaryReport;
use App\Domain\Reporting\TrendQuery;
use App\Domain\Returns\Actions\CreateReturnRequest;
use App\Domain\Returns\Actions\IssueCreditNote;
use App\Domain\Returns\Actions\ProcessRefund;
use App\Domain\Returns\Actions\ReceiveReturnedGoods;
use App\Domain\Returns\SalesReturn;
use App\Domain\Sales\Actions\AcceptQuotation;
use App\Domain\Sales\Actions\BulkGenerateCoupons;
use App\Domain\Sales\Actions\BulkNotifyAction;
use App\Domain\Sales\Actions\BulkOrderAction;
use App\Domain\Sales\Actions\BulkPrintAction;
use App\Domain\Sales\Actions\CancelOrder;
use App\Domain\Sales\Actions\CommissionCalculator;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\ConvertQuotationToOrder;
use App\Domain\Sales\Actions\CreateBeatPlan;
use App\Domain\Sales\Actions\CreateCoupon;
use App\Domain\Sales\Actions\CreateDeliveryChallan;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreatePromotion;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\CreateTerritory;
use App\Domain\Sales\Actions\DeclineQuotation;
use App\Domain\Sales\Actions\DispatchDeliveryChallan;
use App\Domain\Sales\Actions\FlagEmployeeAsSalesPerson;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Actions\LogSalesCall;
use App\Domain\Sales\Actions\MarkDeliveryChallanDelivered;
use App\Domain\Sales\Actions\PayCommission;
use App\Domain\Sales\Actions\RecordFieldVisit;
use App\Domain\Sales\Actions\RecordInvoicePayment;
use App\Domain\Sales\Actions\ReviseQuotation;
use App\Domain\Sales\Actions\SendQuotation;
use App\Domain\Sales\Actions\SetSalesTarget;
use App\Domain\Sales\Actions\UpdateSalesOrder;
use App\Domain\Sales\BeatPlan;
use App\Domain\Sales\CommissionCalculation;
use App\Domain\Sales\CommissionRule;
use App\Domain\Sales\Coupon;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\FieldVisit;
use App\Domain\Sales\GpsPoint;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Promotion;
use App\Domain\Sales\PromotionUsage;
use App\Domain\Sales\Queries\CouponUsageQuery;
use App\Domain\Sales\Queries\FieldSalesQuery;
use App\Domain\Sales\Queries\FlashSaleStatus;
use App\Domain\Sales\Queries\InvoiceQuery;
use App\Domain\Sales\Queries\LeaderboardQuery;
use App\Domain\Sales\Queries\OrderQuery;
use App\Domain\Sales\Queries\SalesPerformanceQuery;
use App\Domain\Sales\Quotation;
use App\Domain\Sales\SalesCallLog;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\SalesTarget;
use App\Domain\Sales\Services\SuspiciousOrderFlagger;
use App\Domain\Sales\SuspiciousOrderFlag;
use App\Domain\Sales\Territory;
use App\Domain\Settings\Services\LocalizationService;
use App\Domain\Tax\Services\TaxPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Sales cycle screens (02-01…02-70 + delivery/returns slices).
 * Domain rules live in Sales\Actions / Returns\Actions.
 */
class SalesController extends Controller
{
    public function __construct(
        protected CreateQuotation $createQuotation,
        protected CreateSalesOrder $createSalesOrder,
        protected ConfirmOrder $confirmOrder,
        protected CancelOrder $cancelOrder,
        protected BulkOrderAction $bulkOrderAction,
        protected BulkPrintAction $bulkPrintAction,
        protected BulkNotifyAction $bulkNotifyAction,
        protected OrderExporter $orderExporter,
        protected UpdateSalesOrder $updateSalesOrder,
        protected CreateInvoiceFromOrder $createInvoice,
        protected IssueInvoice $issueInvoice,
        protected RecordInvoicePayment $recordPayment,
        protected ReviseQuotation $reviseQuotation,
        protected ConvertQuotationToOrder $convertQuotation,
        protected CreateDeliveryChallan $createChallan,
        protected DispatchDeliveryChallan $dispatchChallan,
        protected MarkDeliveryChallanDelivered $deliverChallan,
        protected AcceptQuotation $acceptQuotation,
        protected DeclineQuotation $declineQuotation,
        protected SendQuotation $sendQuotation,
        protected CreateCoupon $createCoupon,
        protected BulkGenerateCoupons $bulkGenerateCoupons,
        protected CouponUsageQuery $couponUsageQuery,
        protected CreatePromotion $createPromotion,
        protected FlashSaleStatus $flashSaleStatus,
        protected PromotionReport $promotionReport,
        protected AgingReportService $agingReport,
        protected SalesSummaryReport $salesSummaryReport,
        protected SalesBreakdownReport $breakdown,
        protected PeakHoursQuery $peakHours,
        protected TrendQuery $trendQuery,
        protected FlagEmployeeAsSalesPerson $flagSalesPerson,
        protected SetSalesTarget $setSalesTarget,
        protected CommissionCalculator $commissionCalculator,
        protected PayCommission $payCommission,
        protected LogSalesCall $logSalesCall,
        protected RecordFieldVisit $recordFieldVisit,
        protected CreateBeatPlan $createBeatPlan,
        protected CreateTerritory $createTerritory,
        protected FieldSalesQuery $fieldSalesQuery,
        protected LeaderboardQuery $leaderboardQuery,
        protected SalesPerformanceQuery $performanceQuery,
        protected InvoiceQuery $invoiceQuery,
        protected CreateReturnRequest $createReturn,
        protected ReceiveReturnedGoods $receiveReturn,
        protected IssueCreditNote $issueCreditNote,
        protected ProcessRefund $processRefund,
        protected PermissionCatalog $catalog,
        protected AuditRecorder $audit,
        protected OrderQuery $orderQuery,
        protected SuspiciousOrderFlagger $flagger,
        protected DocumentRenderer $renderer,
        protected TaxService $taxService,
        protected LocalizationService $localization,
        protected TaxPolicy $taxPolicy,
    ) {}

    /**
     * Return-status views (02-24…02-27) are readable with sales.orders.view
     * OR the returns-team key for that status. The plain list stays behind
     * sales.orders.view alone.
     */
    private const RETURN_STATUS_PERMISSIONS = [
        'return_requested' => 'returns.view',
        'return_approved' => 'returns.view',
        'returned' => 'returns.view',
        'refunded' => 'returns.refunds.view',
    ];

    /* ---- Quotations ---- */

    public function quotations(Request $request): View
    {
        $query = Quotation::query()->with('customer')->orderByDesc('id');

        if ($search = trim((string) $request->query('q'))) {
            $query->where('quote_no', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('sales.quotations.index', [
            'quotations' => $query->paginate(15)->withQueryString(),
            'q' => $search,
            'status' => $request->query('status'),
        ]);
    }

    public function storeQuotation(Request $request): RedirectResponse
    {
        $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:48'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'shipping_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $quote = $this->createQuotation->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.quotations.index')
            ->with('status', "Quotation {$quote->quote_no} created.");
    }

    public function reviseQuotation(Request $request, Quotation $quotation): RedirectResponse
    {
        try {
            $revision = $this->reviseQuotation->handle($quotation, $request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.quotations.index')
            ->with('status', "Revised as {$revision->quote_no} (rev {$revision->revision}).");
    }

    public function convertQuotation(Request $request, Quotation $quotation): RedirectResponse
    {
        try {
            $order = $this->convertQuotation->handle($quotation, $request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.orders.show', $order)
            ->with('status', "Quotation converted to order {$order->order_no} (pending).");
    }

    public function acceptQuotation(Request $request, Quotation $quotation): RedirectResponse
    {
        try {
            $fresh = $this->acceptQuotation->handle($quotation, $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return back()->with('status', "Quotation {$fresh->quote_no} accepted.");
    }

    public function declineQuotation(Request $request, Quotation $quotation): RedirectResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $fresh = $this->declineQuotation->handle($quotation, (string) $request->input('reason'), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['quotation' => $e->getMessage()]);
        }

        return back()->with('status', "Quotation {$fresh->quote_no} declined.");
    }

    public function sendQuotation(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['nullable', 'string', 'in:email,sms,whatsapp,link'],
            'to' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $fresh = $this->sendQuotation->handle($quotation, $data, $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            "Quotation {$fresh->quote_no} sent via {$fresh->sent_channel} to {$fresh->sent_to}."
        );
    }

    /* ---- Orders ---- */

    public function orders(Request $request): View
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        // Status-aware gate: return-status views double as the returns
        // team's window into sales orders (02-24…02-27); everything else
        // needs sales.orders.view. Mirrors CheckPermission (AND/OR-free
        // single key) including the permission.denied audit.
        $status = (string) $request->query('status');
        if (! $this->catalog->allows($user, 'sales.orders.view')) {
            $required = self::RETURN_STATUS_PERMISSIONS[$status] ?? 'sales.orders.view';
            if (! $this->catalog->allows($user, $required)) {
                $this->audit->record([
                    'action' => 'permission.denied',
                    'entity_type' => 'permission',
                    'entity_id' => null,
                    'actor_id' => $user->id,
                    'result' => 'denied',
                    'reason' => "missing permission: {$required}",
                    'after' => [
                        'path' => $request->path(),
                        'method' => $request->method(),
                        'permission' => $required,
                    ],
                ]);

                abort(403, 'You do not have permission to perform this action.');
            }
        }

        // Fake/suspicious view (02-28): needs sales.orders.view (above)
        // AND sales.orders.review — denial audits the missing key.
        $suspicious = $request->query('flag') === 'suspicious';
        if ($suspicious && ! $this->catalog->allows($user, 'sales.orders.review')) {
            $this->audit->record([
                'action' => 'permission.denied',
                'entity_type' => 'permission',
                'entity_id' => null,
                'actor_id' => $user->id,
                'result' => 'denied',
                'reason' => 'missing permission: sales.orders.review',
                'after' => [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'permission' => 'sales.orders.review',
                ],
            ]);

            abort(403, 'You do not have permission to perform this action.');
        }

        $companyId = $this->contextCompanyId($request);

        if ($suspicious) {
            // Refresh-on-read: score orders nothing has flagged yet.
            $this->flagger->backfill($companyId);

            $query = $this->orderQuery->suspicious(SalesOrder::query(), $companyId);
        } else {
            $query = SalesOrder::query()->with('customer')->orderByDesc('id');
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where('sales_orders.order_no', 'like', "%{$search}%");
        }

        if ($status !== '') {
            $query->where('sales_orders.status', $status);
        }

        $statusOptions = $this->catalog->allows($user, 'sales.orders.view')
            ? SalesOrder::STATUSES
            : array_values(array_filter(
                array_keys(self::RETURN_STATUS_PERMISSIONS),
                fn (string $s) => $this->catalog->allows($user, self::RETURN_STATUS_PERMISSIONS[$s]),
            ));

        return view('sales.orders.index', [
            'orders' => $query->paginate(15)->withQueryString(),
            'q' => $search,
            'status' => $request->query('status'),
            'flag' => $suspicious ? 'suspicious' : null,
            'statusOptions' => $statusOptions,
            'couriers' => Courier::query()
                ->where('company_id', $this->contextCompanyId($request))
                ->active()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'configuration_status']),
            'notifyTemplates' => MessageTemplate::query()
                ->whereIn('channel', ['sms', 'whatsapp', 'email'])
                ->where('is_active', true)
                ->where(function ($q) {
                    $companyId = auth()->user()?->company_id;
                    $q->whereNull('company_id')->orWhere('company_id', $companyId);
                })
                ->orderBy('channel')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'channel']),
        ]);
    }

    public function exportOrders(Request $request)
    {
        $export = $this->orderExporter->handle($request);

        if ($export->status === OrderExport::STATUS_COMPLETED && $export->document !== null) {
            $path = Storage::disk('local')->path($export->document->path);

            return response()->download($path, $export->document->original_name, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        if ($export->status === OrderExport::STATUS_FAILED) {
            return back()->withErrors(['order' => 'Export failed: '.($export->error ?? 'unknown error.')]);
        }

        return back()->with('status', 'Export queued — the CSV will be ready when the worker finishes it.');
    }

    public function showOrder(SalesOrder $order): View
    {
        return view('sales.orders.show', [
            'order' => $order->load(['lines.product', 'customer', 'invoices']),
        ]);
    }

    public function storeOrder(Request $request): RedirectResponse
    {
        $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:48'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'shipping_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $order = $this->createSalesOrder->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.orders.show', $order)
            ->with('status', "Order {$order->order_no} created.");
    }

    public function createChallan(Request $request, SalesOrder $order): RedirectResponse
    {
        $request->validate([
            'courier_name' => ['nullable', 'string', 'max:100'],
            'tracking_no' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $challan = $this->createChallan->handle($order, $request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.delivery-challans.show', $challan)
            ->with('status', "Delivery challan {$challan->challan_no} created.");
    }

    public function confirmOrder(Request $request, SalesOrder $order): RedirectResponse
    {
        try {
            $fresh = $this->confirmOrder->handle($order, $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return $fresh->status === 'confirmed'
            ? back()->with('status', "Order {$order->order_no} confirmed; stock reserved.")
            : back()->with('status', "Order {$order->order_no} submitted for approval.");
    }

    public function cancelOrder(Request $request, SalesOrder $order): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $fresh = $this->cancelOrder->handle($order, $request->input('reason'), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return $fresh->status === 'cancelled'
            ? back()->with('status', "Order {$order->order_no} cancelled.")
            : back()->with('status', "Cancellation of {$order->order_no} submitted for approval.");
    }

    /**
     * 02-28 Review decision on a suspicion flag. Records the human
     * decision on the flag row and audits it — the order itself is
     * never mutated or deleted (never auto-delete).
     */
    public function reviewSuspiciousOrder(Request $request, SalesOrder $order): RedirectResponse
    {
        abort_unless($order->company_id === $this->contextCompanyId($request), 404);

        $flag = SuspiciousOrderFlag::query()
            ->where('sales_order_id', $order->id)
            ->where('company_id', $order->company_id)
            ->first();
        abort_unless($flag !== null, 404, 'This order has no suspicion flag.');

        $validated = $request->validate([
            'decision' => ['required', Rule::in([
                SuspiciousOrderFlag::DECISION_LEGITIMATE,
                SuspiciousOrderFlag::DECISION_SUSPICIOUS,
            ])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $before = [
            'status' => $flag->status,
            'decision' => $flag->decision,
            'score' => (int) $flag->score,
        ];

        $flag->update([
            'status' => SuspiciousOrderFlag::STATUS_REVIEWED,
            'decision' => $validated['decision'],
            'review_notes' => $validated['notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->audit->record([
            'action' => 'sales.order_review_decision',
            'entity_type' => 'sales_order',
            'entity_id' => $order->id,
            'actor_id' => $request->user()->id,
            'before' => $before,
            'after' => [
                'status' => SuspiciousOrderFlag::STATUS_REVIEWED,
                'decision' => $flag->decision,
                'review_notes' => $flag->review_notes,
                'score' => (int) $flag->score,
                'level' => $flag->level,
            ],
        ]);

        $label = $flag->decision === SuspiciousOrderFlag::DECISION_LEGITIMATE
            ? 'legitimate'
            : 'confirmed suspicious';

        return back()->with(
            'status',
            "Order {$order->order_no} marked {$label} — the order itself was not changed.",
        );
    }

    public function bulkConfirm(Request $request): RedirectResponse
    {
        return $this->runBulkOrderAction('confirm', $request);
    }

    public function bulkCancel(Request $request): RedirectResponse
    {
        return $this->runBulkOrderAction('cancel', $request);
    }

    public function bulkPrintInvoice(Request $request): RedirectResponse
    {
        return $this->handleBulkPrint($request, 'invoice', 'invoice');
    }

    public function bulkPrintPackingSlip(Request $request): RedirectResponse
    {
        return $this->handleBulkPrint($request, 'packing_slip', 'packing slip');
    }

    public function bulkPrintShippingLabel(Request $request): RedirectResponse
    {
        return $this->handleBulkPrint($request, 'shipping_label', 'shipping label');
    }

    public function bulkSms(Request $request): RedirectResponse
    {
        return $this->handleBulkNotify($request, 'sms', 'SMS');
    }

    public function bulkWhatsapp(Request $request): RedirectResponse
    {
        return $this->handleBulkNotify($request, 'whatsapp', 'WhatsApp');
    }

    public function bulkEmail(Request $request): RedirectResponse
    {
        return $this->handleBulkNotify($request, 'email', 'email');
    }

    protected function handleBulkNotify(Request $request, string $channel, string $noun): RedirectResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.BulkNotifyAction::MAX_PER_RUN],
            'order_ids.*' => ['integer'],
            'template_id' => ['required', 'integer'],
        ]);

        try {
            $report = $this->bulkNotifyAction->handle($channel, $data['order_ids'], $data, $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        $summary = sprintf(
            'Bulk %s: %d requested, %d queued, %d held, %d failed.',
            $noun,
            $report['requested'],
            $report['counts']['queued'],
            $report['counts']['not_configured'],
            $report['counts']['failed'],
        );

        $messages = collect($report['results'])
            ->filter(fn (array $row) => $row['outcome'] !== 'queued')
            ->map(fn (array $row) => ($row['order_no'] ?? '#'.$row['order_id']).': '.$row['message'])
            ->all();

        if ($report['counts']['queued'] === 0 && $report['counts']['not_configured'] === 0) {
            return back()->withErrors(['order' => $summary.' '.implode(' ', $messages)]);
        }

        return back()
            ->with('status', $summary)
            ->with('bulk_messages', $messages);
    }

    protected function handleBulkPrint(Request $request, string $type, string $noun): RedirectResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.BulkPrintAction::MAX_PER_RUN],
            'order_ids.*' => ['integer'],
        ]);

        try {
            $report = $this->bulkPrintAction->handle($type, $data['order_ids'], [], $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        $summary = sprintf(
            'Bulk print %s: %d requested, %d printed, %d failed.',
            $noun,
            $report['requested'],
            $report['counts']['printed'],
            $report['counts']['failed'],
        );

        $messages = collect($report['results'])
            ->filter(fn (array $row) => $row['outcome'] === 'failed')
            ->map(fn (array $row) => ($row['order_no'] ?? '#'.$row['order_id']).': '.$row['message'])
            ->all();

        if ($report['counts']['failed'] === $report['requested']) {
            return back()->withErrors(['order' => $summary.' '.implode(' ', $messages)]);
        }

        return back()
            ->with('status', $summary)
            ->with('bulk_messages', $messages);
    }

    public function bulkAssignCourier(Request $request): RedirectResponse
    {
        $companyId = $this->contextCompanyId($request);

        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.BulkOrderAction::MAX_PER_RUN],
            'order_ids.*' => ['integer'],
            'courier_id' => [
                'required', 'integer',
                Rule::exists('couriers', 'id')->where('company_id', $companyId),
            ],
            'rider_name' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $report = $this->bulkOrderAction->handle(
                'assign_courier',
                $data['order_ids'],
                [
                    'courier_id' => (int) $data['courier_id'],
                    'rider_name' => $data['rider_name'] ?? null,
                ],
                $request,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        $summary = sprintf(
            'Bulk assign courier: %d requested, %d assigned, %d failed.',
            $report['requested'],
            $report['counts']['assigned'],
            $report['counts']['failed'],
        );

        $messages = collect($report['results'])
            ->filter(fn (array $row) => $row['outcome'] === 'failed')
            ->map(fn (array $row) => ($row['order_no'] ?? '#'.$row['order_id']).': '.$row['message'])
            ->all();

        if ($report['counts']['failed'] === $report['requested']) {
            return back()->withErrors(['order' => $summary.' '.implode(' ', $messages)]);
        }

        return back()
            ->with('status', $summary)
            ->with('bulk_messages', $messages);
    }

    protected function runBulkOrderAction(string $action, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:'.BulkOrderAction::MAX_PER_RUN],
            'order_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $report = $this->bulkOrderAction->handle(
                $action,
                $data['order_ids'],
                ['reason' => $data['reason'] ?? null],
                $request,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        $summary = sprintf(
            'Bulk %s: %d requested, %d confirmed, %d cancelled, %d awaiting approval, %d failed.',
            $action,
            $report['requested'],
            $report['counts']['confirmed'],
            $report['counts']['cancelled'],
            $report['counts']['pending_approval'],
            $report['counts']['failed'],
        );

        $messages = collect($report['results'])
            ->filter(fn (array $row) => $row['outcome'] === 'failed')
            ->map(fn (array $row) => ($row['order_no'] ?? '#'.$row['order_id']).': '.$row['message'])
            ->all();

        if ($report['counts']['failed'] === $report['requested']) {
            return back()->withErrors(['order' => $summary.' '.implode(' ', $messages)]);
        }

        return back()
            ->with('status', $summary)
            ->with('bulk_messages', $messages);
    }

    public function editOrder(Request $request, SalesOrder $order): View
    {
        $companyId = $this->contextCompanyId($request);
        $order->load('lines');

        // Base document discount only: line discounts, coupon and promotion
        // amounts are re-derived server-side on save, so they must not be
        // pre-filled into doc_discount or the discount would double-count.
        $lineDiscounts = (float) $order->lines->sum(fn ($line) => (float) $line->discount);
        $promotionDiscount = (float) PromotionUsage::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->sum('discount_amount');

        $docDiscount = max(
            0.0,
            (float) $order->discount - $lineDiscounts - (float) $order->coupon_discount - $promotionDiscount,
        );

        return view('sales.orders.edit', [
            'order' => $order->load('lines.product'),
            'docDiscount' => $docDiscount,
            'customers' => Customer::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(),
            'warehouses' => Warehouse::query()
                ->where('company_id', $companyId)
                ->orderBy('name')
                ->get(),
            'products' => Product::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function updateOrder(Request $request, SalesOrder $order): RedirectResponse
    {
        $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'order_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'doc_discount' => ['nullable', 'numeric', 'min:0'],
            'shipping' => ['nullable', 'numeric', 'min:0'],
            'shipping_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:48'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $this->updateSalesOrder->handle($order, $request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.orders.show', $order)
            ->with('status', "Order {$order->order_no} updated.");
    }

    public function invoiceOrder(Request $request, SalesOrder $order): RedirectResponse
    {
        try {
            $invoice = $this->createInvoice->handle($order, $request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.invoices.show', $invoice)
            ->with('status', "Draft invoice {$invoice->invoice_no} created.");
    }

    /* ---- Invoices ---- */

    public function invoices(Request $request): View
    {
        $query = Invoice::query()->with('customer')->orderByDesc('id');

        if ($search = trim((string) $request->query('q'))) {
            $query->where('invoice_no', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $overdue = $request->query('overdue') === '1';
        if ($overdue) {
            $this->invoiceQuery->overdue($query);
        }

        return view('sales.invoices.index', [
            'invoices' => $query->paginate(15)->withQueryString(),
            'q' => $search,
            'status' => $request->query('status'),
            'overdue' => $overdue,
            'invoiceQuery' => $this->invoiceQuery,
        ]);
    }

    public function showInvoice(Invoice $invoice): View
    {
        return view('sales.invoices.show', [
            'invoice' => $invoice->load(['lines.product', 'customer', 'salesOrder']),
            'taxInclusive' => $this->taxPolicy->pricesIncludeTax(),
        ]);
    }

    /**
     * 02-50 Mushak 9.1 statutory tax invoice. The template is a
     * SEPARATE statutory document (document_types.code=mushak_9_1,
     * printed title MUSHAK 9.1) — the commercial invoice print never
     * changes. Rate resolution is effective-dated through TaxService,
     * the document is filed (versioned row in documents) and every
     * print is logged + audited. A non-tax-applicable invoice is
     * refused with an honest reason instead of rendering a hollow form.
     */
    public function mushak91(Request $request, Invoice $invoice): Response
    {
        abort_unless($invoice->company_id === $this->contextCompanyId($request), 404);

        abort_unless(
            (bool) $invoice->tax_applicable,
            422,
            'Mushak 9.1 applies only to tax-applicable invoices — this invoice has no tax configuration.',
        );

        $invoice->loadMissing(['lines.product', 'customer', 'company']);

        $type = DocumentType::query()->where('code', 'mushak_9_1')->firstOrFail();
        $rate = $invoice->tax_code !== null && $invoice->tax_code !== ''
            ? $this->taxService->rateFor($invoice->tax_code, $invoice->invoice_date?->toDateString())
            : null;

        $html = view('sales.invoices.mushak91', [
            'invoice' => $invoice,
            'type' => $type,
            'rate' => $rate,
            'localization' => $this->localization,
            'formRevision' => $this->taxPolicy->mushakFormRevision(),
        ])->render();

        $document = $this->renderer->storeGenerated(
            $html,
            companyId: (int) $invoice->company_id,
            directory: 'invoices',
            basename: str_replace(['/', '\\'], '-', (string) $invoice->invoice_no).'-mushak-9.1',
            owner: $invoice,
            typeCode: 'mushak_9_1',
            user: $request->user(),
        );
        $document->refresh();
        $this->renderer->recordPrint($invoice, 'mushak_9_1', $request);

        $this->audit->record([
            'action' => 'sales.invoice_mushak_printed',
            'entity_type' => 'invoice',
            'entity_id' => $invoice->id,
            'actor_id' => $request->user()->id,
            'after' => [
                'invoice_no' => $invoice->invoice_no,
                'document_type' => 'mushak_9_1',
                'document_version' => $document->version,
                'tax_code' => $invoice->tax_code,
                'tax' => (float) $invoice->tax,
            ],
        ]);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function issueInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        try {
            $this->issueInvoice->handle($invoice, $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return back()->with('status', "Invoice {$invoice->invoice_no} issued.");
    }

    public function storePayment(Request $request): RedirectResponse
    {
        $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'in:cash,bank,cheque,mobile'],
            'reference' => ['nullable', 'string', 'max:64'],
            'narration' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $payment = $this->recordPayment->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.invoices.show', $request->input('invoice_id'))
            ->with('status', "Payment {$payment->receipt_no} recorded.");
    }

    /* ---- Coupons (02-101…) ---- */

    public function coupons(Request $request): View
    {
        $query = Coupon::query()->orderByDesc('id');

        if ($search = trim((string) $request->query('q'))) {
            $query->where('code', 'like', "%{$search}%");
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return view('sales.coupons.index', [
            'coupons' => $query->paginate(15)->withQueryString(),
            'q' => $search,
            'type' => $request->query('type'),
        ]);
    }

    public function storeCoupon(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:48'],
            'type' => ['required', 'string', 'in:percent_off,fixed_off,free_shipping,buy_x_get_y'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'buy_qty' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'get_qty' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $coupon = $this->createCoupon->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.coupons.index')
            ->with('status', "Coupon {$coupon->code} created.");
    }

    public function couponUsage(Request $request): View
    {
        $periodType = (string) $request->query('period_type', 'monthly');
        $at = $request->query('at');
        $view = (string) $request->query('view', 'usage');

        if ($view === 'analytics') {
            $analytics = $this->couponUsageQuery->analytics(
                (int) $request->user()->company_id,
                $periodType,
                $at,
            );

            return view('sales.coupons.analytics', [
                'analytics' => $analytics,
                'report' => $analytics['report'],
                'periodType' => $periodType,
                'at' => $at,
                'view' => $view,
            ]);
        }

        $report = $this->couponUsageQuery->forPeriod(
            (int) $request->user()->company_id,
            $periodType,
            $at,
        );

        return view('sales.coupons.usage', [
            'report' => $report,
            'periodType' => $periodType,
            'at' => $at,
            'view' => $view,
        ]);
    }

    public function bulkGenerateCoupons(Request $request): RedirectResponse
    {
        $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:500'],
            'prefix' => ['nullable', 'string', 'max:24'],
            'type' => ['required', 'string', 'in:percent_off,fixed_off,free_shipping,buy_x_get_y'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'buy_qty' => ['nullable', 'integer', 'min:1'],
            'get_qty' => ['nullable', 'integer', 'min:1'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->bulkGenerateCoupons->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.coupons.index')
            ->with('status', "Generated {$result['created']} coupon(s): ".implode(', ', array_slice($result['codes'], 0, 5)).(count($result['codes']) > 5 ? '…' : ''));
    }

    /* ---- Promotions (02-105…02-107) ---- */

    public function promotions(Request $request): View
    {
        $query = Promotion::query()->orderByDesc('priority')->orderByDesc('id');

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }

        if ($request->boolean('active_now')) {
            $query->activeAt(now()->toDateTimeString());
        }

        return view('sales.promotions.index', [
            'promotions' => $query->paginate(15)->withQueryString(),
            'q' => $request->query('q'),
            'kind' => $request->query('kind'),
            'activeNow' => $request->boolean('active_now'),
            'kinds' => Promotion::KINDS,
            'types' => Promotion::TYPES,
        ]);
    }

    public function storePromotion(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:48'],
            'type' => ['required', 'string', 'in:percent_off,fixed_off'],
            'kind' => ['nullable', 'string', 'in:standard,seasonal,flash'],
            'value' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        try {
            $promotion = $this->createPromotion->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.promotions.index')
            ->with('status', "Promotion {$promotion->name} created.");
    }

    public function flashSales(Request $request): View
    {
        $status = $this->flashSaleStatus->forCompany((int) $request->user()->company_id);

        return view('sales.promotions.flash', [
            'status' => $status,
            'flashPromotions' => $status['rows'],
            'kinds' => Promotion::KINDS,
            'types' => Promotion::TYPES,
        ]);
    }

    public function promotionReport(Request $request): View
    {
        $periodType = (string) $request->query('period_type', 'monthly');
        $at = $request->query('at');

        $report = $this->promotionReport->forPeriod(
            (int) $request->user()->company_id,
            $periodType,
            $at,
        );

        return view('sales.promotions.report', [
            'report' => $report,
            'periodType' => $periodType,
            'at' => $at,
        ]);
    }

    public function invoiceAgingReport(Request $request): View
    {
        $data = $request->validate([
            'as_of' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $report = $this->agingReport->forCompany(
            (int) $request->user()->company_id,
            $data['as_of'] ?? null,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
        );

        return view('sales.reports.invoice-aging', ['report' => $report]);
    }

    public function salesSummaryReport(Request $request): View
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:'.implode(',', Invoice::STATUSES)],
            'method' => ['nullable', 'string', 'in:cash,bank,cheque,mobile'],
        ]);

        $report = $this->salesSummaryReport->forCompany((int) $request->user()->company_id, $data);

        return view('sales.reports.summary', ['report' => $report]);
    }

    public function peakHours(Request $request): View
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $report = $this->peakHours->forCompany((int) $request->user()->company_id, $data);

        return view('sales.reports.peak-hours', ['report' => $report]);
    }

    public function salesTrend(Request $request): View
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $report = $this->trendQuery->forCompany((int) $request->user()->company_id, $data);

        return view('sales.reports.trend', ['report' => $report]);
    }

    public function salesBreakdown(Request $request, string $dim): View
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        if ($dim === 'branch') {
            // Rule 5 / D6: a scoped user only ever compares their own branches.
            $data['branch_ids'] = $request->user()->accessibleBranchIds();
        }

        $report = $this->breakdown->forCompany((int) $request->user()->company_id, $dim, $data);

        return view('sales.reports.breakdown', [
            'report' => $report,
            'can_compare_branch' => app(PermissionCatalog::class)
                ->allows($request->user(), 'branches.compare'),
        ]);
    }

    /* ---- Sales team (02-78…02-80) ---- */

    public function team(Request $request): View
    {
        $query = Employee::query()->orderBy('full_name');

        if ($search = trim((string) $request->query('q'))) {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('full_name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        if ($request->query('salespersons') === '1') {
            $query->salespersons();
        }

        return view('sales.team.index', [
            'employees' => $query->paginate(15)->withQueryString(),
            'q' => $request->query('q'),
            'salespersonsOnly' => $request->query('salespersons') === '1',
        ]);
    }

    public function flagSalesPerson(Request $request): RedirectResponse
    {
        $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'is_salesperson' => ['required', 'boolean'],
        ]);

        try {
            $employee = Employee::query()->findOrFail($request->input('employee_id'));
            $fresh = $this->flagSalesPerson->handle(
                $employee,
                (bool) $request->boolean('is_salesperson'),
                $request,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['employee' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s %s sales-person roster.',
            $fresh->full_name,
            $fresh->is_salesperson ? 'added to' : 'removed from',
        ));
    }

    public function teamTargets(Request $request): View
    {
        $query = SalesTarget::query()->with('employee')->orderByDesc('period_start');

        if ($type = $request->query('period_type')) {
            $query->where('period_type', $type);
        }

        if ($employeeId = (int) $request->query('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        $employees = Employee::query()->orderBy('full_name')->get();

        return view('sales.team.targets', [
            'targets' => $query->paginate(15)->withQueryString(),
            'employees' => $employees,
            'periodType' => $request->query('period_type'),
            'employeeFilter' => $employeeId ?: null,
        ]);
    }

    public function storeTarget(Request $request): RedirectResponse
    {
        $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'period_type' => ['required', 'string', 'in:daily,monthly,yearly'],
            'target_amount' => ['required', 'numeric', 'min:0'],
            'at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $target = $this->setSalesTarget->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['target' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.team.targets.index')
            ->with('status', sprintf(
                'Target %.2f saved for %s (%s).',
                (float) $target->target_amount,
                $target->employee?->full_name ?? 'employee',
                $target->period_type,
            ));
    }

    public function teamAchievement(Request $request): View
    {
        $at = $request->query('at') ?: now()->toDateString();
        $periodType = $request->query('period_type', 'monthly');

        if (! in_array($periodType, SalesTarget::PERIOD_TYPES, true)) {
            $periodType = 'monthly';
        }

        $bounds = SalesTarget::boundsFor($periodType, $at);

        $targets = SalesTarget::query()
            ->with('employee')
            ->where('period_type', $periodType)
            ->whereDate('period_start', $bounds['period_start'])
            ->orderBy('target_amount', 'desc')
            ->get();

        // Actuals: issued/paid invoices attributed to sales_person in window
        $rows = $targets->map(function (SalesTarget $target) use ($bounds) {
            $actual = Invoice::query()
                ->where('sales_person_id', $target->employee_id)
                ->whereIn('status', ['issued', 'partial', 'paid'])
                ->whereDate('invoice_date', '>=', $bounds['period_start'])
                ->whereDate('invoice_date', '<=', $bounds['period_end'])
                ->sum('grand_total');

            $targetAmount = (float) $target->target_amount;
            $actualAmount = (float) $actual;

            return [
                'target' => $target,
                'employee' => $target->employee,
                'target_amount' => $targetAmount,
                'actual_amount' => $actualAmount,
                'variance' => round($actualAmount - $targetAmount, 4),
                'pct' => $targetAmount > 0 ? round(($actualAmount / $targetAmount) * 100, 2) : null,
            ];
        });

        return view('sales.team.achievement', [
            'rows' => $rows,
            'bounds' => $bounds,
            'periodType' => $periodType,
            'at' => $at,
            'sampleSize' => $rows->count(),
        ]);
    }

    public function teamLeaderboard(Request $request): View
    {
        $at = $request->query('at') ?: now()->toDateString();
        $periodType = $request->query('period_type', 'monthly');

        $report = $this->leaderboardQuery->forPeriod(
            (int) $this->contextCompanyId($request),
            $periodType,
            $at,
        );

        return view('sales.team.leaderboard', [
            'report' => $report,
            'at' => $at,
        ]);
    }

    public function teamPerformance(Request $request): View
    {
        $at = $request->query('at') ?: now()->toDateString();
        $periodType = $request->query('period_type', 'monthly');

        $report = $this->performanceQuery->forPeriod(
            (int) $this->contextCompanyId($request),
            $periodType,
            $at,
        );

        return view('sales.team.performance', [
            'report' => $report,
            'at' => $at,
        ]);
    }

    public function teamCommissions(Request $request): View
    {
        $query = CommissionCalculation::query()
            ->with(['employee', 'rule'])
            ->orderByDesc('period_start')
            ->orderByDesc('id');

        if ($employeeId = (int) $request->query('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('sales.team.commissions', [
            'calculations' => $query->paginate(15)->withQueryString(),
            'employees' => Employee::query()->salespersons()->orderBy('full_name')->get(),
            'employeeFilter' => $employeeId ?: null,
            'statusFilter' => $request->query('status'),
        ]);
    }

    public function calculateCommission(Request $request): RedirectResponse
    {
        $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'commission_rule_id' => ['nullable', 'integer', 'exists:commission_rules,id'],
            'period_type' => ['required', 'string', 'in:daily,monthly,yearly'],
            'at' => ['nullable', 'date'],
        ]);

        try {
            $calc = $this->commissionCalculator->calculate($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['commission' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.team.commissions.index')
            ->with('status', sprintf(
                'Commission %.2f calculated for %s (%s).',
                (float) $calc->commission_amount,
                $calc->employee?->full_name ?? 'employee',
                $calc->period_type,
            ));
    }

    public function payCommission(Request $request, CommissionCalculation $calculation): RedirectResponse
    {
        $request->validate([
            'method' => ['nullable', 'string', 'in:cash,bank'],
            'payment_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'narration' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $payment = $this->payCommission->handle($calculation, $request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        if ($payment->status === 'paid') {
            return redirect()
                ->route('sales.team.commissions.index')
                ->with('status', "Commission payment {$payment->payment_no} posted.");
        }

        return redirect()
            ->route('sales.team.commissions.index')
            ->with('status', "Commission payment {$payment->payment_no} submitted for approval.");
    }

    public function commissionRules(Request $request): View
    {
        $query = CommissionRule::query()->with('employee')->orderByDesc('id');

        if ($employeeId = (int) $request->query('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        return view('sales.team.commission-rules', [
            'rules' => $query->paginate(15)->withQueryString(),
            'employees' => Employee::query()->orderBy('full_name')->get(),
            'employeeFilter' => $employeeId ?: null,
        ]);
    }

    public function storeCommissionRule(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'rule_type' => ['required', 'string', 'in:percent_of_revenue,fixed_per_period'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'period_type' => ['required', 'string', 'in:daily,monthly,yearly'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $rule = $this->commissionCalculator->createRule($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['rule' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.team.commission-rules.index')
            ->with('status', "Commission rule {$rule->name} saved.");
    }

    /* ---- Sales field slice (02-84…02-87) ---- */

    public function teamCalls(Request $request): View
    {
        $query = SalesCallLog::query()->with(['employee', 'customer'])->orderByDesc('call_date')->orderByDesc('id');

        if ($employeeId = (int) $request->query('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        if ($direction = $request->query('direction')) {
            $query->where('direction', $direction);
        }

        return view('sales.team.calls', [
            'calls' => $query->paginate(15)->withQueryString(),
            'employees' => Employee::query()->salespersons()->orderBy('full_name')->get(),
            'employeeFilter' => $employeeId ?: null,
            'directionFilter' => $request->query('direction'),
        ]);
    }

    public function storeCall(Request $request): RedirectResponse
    {
        $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'call_date' => ['nullable', 'date'],
            'direction' => ['required', 'string', 'in:inbound,outbound'],
            'outcome' => ['required', 'string', 'in:'.implode(',', SalesCallLog::OUTCOMES)],
            'subject' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ]);

        try {
            $call = $this->logSalesCall->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['call' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.team.calls.index')
            ->with('status', "Call logged for {$call->employee?->full_name} ({$call->outcome}).");
    }

    public function fieldSales(Request $request): View
    {
        $at = $request->query('at') ?: now()->toDateString();
        $periodType = $request->query('period_type', 'monthly');

        $report = $this->fieldSalesQuery->forPeriod(
            (int) $this->contextCompanyId($request),
            $periodType,
            $at,
        );

        return view('sales.team.field-sales', [
            'report' => $report,
            'at' => $at,
        ]);
    }

    public function fieldVisits(Request $request): View
    {
        $query = FieldVisit::query()->with(['employee', 'customer'])->orderByDesc('visit_date')->orderByDesc('id');

        if ($employeeId = (int) $request->query('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // GPS replay: only points for visits that recorded consent
        $gpsQuery = GpsPoint::query()
            ->whereHas('fieldVisit', fn ($q) => $q->where('gps_consent', true))
            ->orderBy('captured_at');
        if ($employeeId) {
            $gpsQuery->where('employee_id', $employeeId);
        }

        return view('sales.team.field-visits', [
            'visits' => $query->paginate(15)->withQueryString(),
            'employees' => Employee::query()->salespersons()->orderBy('full_name')->get(),
            'employeeFilter' => $employeeId ?: null,
            'statusFilter' => $request->query('status'),
            'gpsPoints' => $gpsQuery->limit(200)->get(),
        ]);
    }

    public function storeFieldVisit(Request $request): RedirectResponse
    {
        $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'visit_date' => ['nullable', 'date'],
            'purpose' => ['nullable', 'string', 'max:64'],
            'location_note' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'gps_consent' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        try {
            $visit = $this->recordFieldVisit->handle(
                array_merge($request->all(), ['action' => 'create']),
                $request,
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['visit' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.team.field-visits.index')
            ->with('status', "Field visit planned for {$visit->employee?->full_name}.");
    }

    public function transitionFieldVisit(Request $request, FieldVisit $visit): RedirectResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:start,complete,cancel'],
            'gps_consent' => ['nullable', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        try {
            $fresh = $this->recordFieldVisit->handle(
                array_merge($request->all(), ['field_visit_id' => $visit->id]),
                $request,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['visit' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.team.field-visits.index')
            ->with('status', "Visit {$fresh->id} is now {$fresh->status}.");
    }

    public function beatPlans(Request $request): View
    {
        $query = BeatPlan::query()->with(['employee', 'stops.customer'])->orderByDesc('plan_date')->orderByDesc('id');

        if ($employeeId = (int) $request->query('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        return view('sales.team.beat-plans', [
            'plans' => $query->paginate(15)->withQueryString(),
            'employees' => Employee::query()->salespersons()->orderBy('full_name')->get(),
            'employeeFilter' => $employeeId ?: null,
        ]);
    }

    public function storeBeatPlan(Request $request): RedirectResponse
    {
        $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'name' => ['required', 'string', 'max:160'],
            'plan_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'stops' => ['required', 'array', 'min:1'],
            'stops.*.customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'stops.*.label' => ['nullable', 'string', 'max:200'],
            'stops.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $plan = $this->createBeatPlan->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['beat' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.team.beat-plans.index')
            ->with('status', "Beat plan \"{$plan->name}\" created with {$plan->stops->count()} stops.");
    }

    public function territories(Request $request): View
    {
        $query = Territory::query()->with(['employees', 'deliveryZone'])->orderBy('code');

        if ($search = trim((string) $request->query('q'))) {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('code', 'like', $like)->orWhere('name', 'like', $like);
            });
        }

        return view('sales.team.territories', [
            'territories' => $query->paginate(15)->withQueryString(),
            'q' => $request->query('q'),
            'employees' => Employee::query()->orderBy('full_name')->get(),
            'deliveryZones' => DeliveryZone::query()
                ->where('company_id', $this->contextCompanyId($request))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeTerritory(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'delivery_zone_id' => ['nullable', 'integer', 'exists:delivery_zones,id'],
            'is_active' => ['nullable', 'boolean'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        try {
            $territory = $this->createTerritory->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['territory' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.team.territories.index')
            ->with('status', "Territory {$territory->code} created.");
    }

    protected function contextCompanyId(Request $request): int
    {
        return (int) ($request->user()?->company_id ?? app(TenantContext::class)->companyId() ?? abort(500, 'No company context.'));
    }

    /* ---- Delivery challans ---- */

    public function deliveryChallans(Request $request): View
    {
        $query = DeliveryChallan::query()->with('order')->orderByDesc('id');

        if ($search = trim((string) $request->query('q'))) {
            $query->where('challan_no', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('sales.delivery-challans.index', [
            'challans' => $query->paginate(15)->withQueryString(),
            'q' => $search,
            'status' => $request->query('status'),
        ]);
    }

    public function showChallan(DeliveryChallan $challan): View
    {
        return view('sales.delivery-challans.show', [
            'challan' => $challan->load(['lines.product', 'order']),
        ]);
    }

    public function dispatchChallan(Request $request, DeliveryChallan $challan): RedirectResponse
    {
        $request->validate([
            'courier_name' => ['nullable', 'string', 'max:100'],
            'tracking_no' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $fresh = $this->dispatchChallan->handle($challan, $request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['challan' => $e->getMessage()]);
        }

        return back()->with('status', "Challan {$fresh->challan_no} dispatched.");
    }

    public function deliverChallan(Request $request, DeliveryChallan $challan): RedirectResponse
    {
        try {
            $fresh = $this->deliverChallan->handle($challan, $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['challan' => $e->getMessage()]);
        }

        return back()->with('status', "Challan {$fresh->challan_no} marked delivered.");
    }

    /* ---- Returns / credit notes / refunds ---- */

    public function returns(Request $request): View
    {
        $query = SalesReturn::query()->with(['invoice', 'customer'])->orderByDesc('id');

        if ($search = trim((string) $request->query('q'))) {
            $query->where('return_no', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('sales.returns.index', [
            'returns' => $query->paginate(15)->withQueryString(),
            'q' => $search,
            'status' => $request->query('status'),
        ]);
    }

    public function storeReturn(Request $request): RedirectResponse
    {
        $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'return_reason_id' => ['nullable', 'integer', 'exists:return_reasons,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_line_id' => ['required', 'integer', 'exists:invoice_lines,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
        ]);

        try {
            $salesReturn = $this->createReturn->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.returns.index')
            ->with('status', "Return {$salesReturn->return_no} requested.");
    }

    public function receiveReturn(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        try {
            $fresh = $this->receiveReturn->handle($salesReturn, $request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        return back()->with('status', "Return {$fresh->return_no} received.");
    }

    public function creditReturn(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        try {
            $creditNote = $this->issueCreditNote->handle($salesReturn, $request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        return back()->with('status', "Credit note {$creditNote->credit_note_no} issued.");
    }

    public function refundReturn(Request $request, SalesReturn $salesReturn): RedirectResponse
    {
        $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'in:cash,bank,mobile,adjustment'],
            'reference' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $refund = $this->processRefund->handle($salesReturn, $request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['return' => $e->getMessage()]);
        }

        return back()->with('status', "Refund {$refund->refund_no} posted.");
    }
}
