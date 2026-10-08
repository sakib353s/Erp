<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Company;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Sales\Actions\ClosePosSession;
use App\Domain\Sales\Actions\CommitPosSale;
use App\Domain\Sales\Actions\CreateLayaway;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\Actions\HoldPosOrder;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\Actions\PosCashInOut;
use App\Domain\Sales\Actions\PosExchangeAction;
use App\Domain\Sales\Actions\PosOfflineSync;
use App\Domain\Sales\Actions\PosReturnAction;
use App\Domain\Sales\Actions\ResumePosOrder;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\PosHold;
use App\Domain\Sales\PosSession;
use App\Domain\Sales\PosTransaction;
use App\Domain\Sales\Services\PosReportService;
use App\Domain\Sales\Services\PricingService;
use App\Domain\Settings\Services\LocalizationService;
use App\Domain\Settings\Services\SettingService;
use App\Domain\Tax\Services\TaxPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * POS terminal + session screens (02-30…02-37).
 * Sale commit is CommitPosSale; X/Z via PosReportService.
 */
class PosController extends Controller
{
    public function __construct(
        protected OpenPosSession $openSession,
        protected ClosePosSession $closeSession,
        protected CommitPosSale $commitPosSale,
        protected PricingService $pricing,
        protected PosReportService $reports,
        protected HoldPosOrder $holdPosOrder,
        protected ResumePosOrder $resumePosOrder,
        protected PosOfflineSync $offlineSync,
        protected PosReturnAction $posReturnAction,
        protected PosExchangeAction $posExchangeAction,
        protected PosCashInOut $posCashInOut,
        protected CreateQuotation $createQuotation,
        protected CreateLayaway $createLayaway,
        protected SettingService $settings,
        protected LocalizationService $localization,
        protected TaxPolicy $taxPolicy,
    ) {}

    public function terminal(Request $request): View
    {
        $session = PosSession::query()
            ->where('company_id', $request->user()->company_id)
            ->where('branch_id', $request->user()->default_branch_id)
            ->where('status', 'open')
            ->first();

        return view('pos.terminal', [
            'session' => $session,
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) $request->query('q'));
        $query = Product::query()
            ->where('company_id', $request->user()->company_id)
            ->where('is_active', true)
            ->orderBy('name');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            });
        }

        $products = $query->limit(20)->get()->map(function (Product $product) {
            return [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'is_stocked' => (bool) $product->is_stocked,
                'unit_price' => $this->pricing->resolveUnitPrice($product, null, null, now()->toDateString()),
            ];
        });

        return response()->json(['data' => $products]);
    }

    /**
     * 02-42 Price check mode: the server's quoted unit price, read-only.
     * Never writes, never touches the cart, never exposes another
     * company's product (scoped findOrFail / validation errors).
     */
    public function priceCheck(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'required_without:q'],
            'q' => ['nullable', 'string', 'max:100', 'required_without:product_id'],
            'customer_id' => ['nullable', 'integer'],
        ]);

        $companyId = $request->user()->company_id;
        $product = $this->resolvePriceCheckProduct($companyId, $data);

        $customer = ! empty($data['customer_id'])
            ? Customer::query()->where('company_id', $companyId)->findOrFail($data['customer_id'])
            : null;

        $at = now()->toDateString();
        $priceList = $this->pricing->priceListFor($customer, $at);

        return response()->json([
            'data' => [
                'product_id' => $product->id,
                'code' => $product->code,
                'sku' => $product->sku,
                'name' => $product->name,
                'unit_price' => $this->pricing->resolveUnitPrice($product, $customer, null, $at),
                'currency' => Company::current()?->currency ?? 'BDT',
                'price_list' => $priceList?->name,
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /** Exact SKU/code/barcode first, then a single unambiguous name match. */
    protected function resolvePriceCheckProduct(int $companyId, array $data): Product
    {
        $base = Product::query()
            ->where('company_id', $companyId)
            ->where('is_active', true);

        if (! empty($data['product_id'])) {
            return (clone $base)->findOrFail($data['product_id']);
        }

        $term = trim((string) $data['q']);

        $exact = (clone $base)
            ->where(fn ($q) => $q->where('code', $term)->orWhere('sku', $term)->orWhere('barcode', $term))
            ->get();

        if ($exact->count() === 1) {
            return $exact->first();
        }

        if ($exact->count() > 1) {
            throw ValidationException::withMessages(['q' => 'More than one product matches that code.']);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $matches = (clone $base)->where('name', 'like', $like)->limit(2)->get();

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages(['q' => 'No active product matches that search.']);
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['q' => 'Several products match; search by SKU or code.']);
        }

        return $matches->first();
    }

    public function sessions(Request $request): View
    {
        $sessions = PosSession::query()
            ->where('company_id', $request->user()->company_id)
            ->orderByDesc('opened_at')
            ->paginate(15)
            ->withQueryString();

        return view('pos.sessions', [
            'sessions' => $sessions,
        ]);
    }

    public function openSession(Request $request): RedirectResponse
    {
        $request->validate([
            'opening_float' => ['nullable', 'numeric', 'min:0'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        try {
            $session = $this->openSession->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['session' => $e->getMessage()]);
        }

        return redirect()
            ->route('pos.terminal')
            ->with('status', "POS session {$session->session_no} opened.");
    }

    public function closeSession(Request $request, PosSession $session): RedirectResponse
    {
        $request->validate([
            'closing_counted' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $this->closeSession->handle($session, (float) $request->input('closing_counted'), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['session' => $e->getMessage()]);
        }

        return redirect()
            ->route('pos.sessions.index')
            ->with('status', "POS session {$session->session_no} closed.");
    }

    public function commitSale(Request $request): RedirectResponse
    {
        $request->validate([
            'pos_session_id' => ['nullable', 'integer', 'exists:pos_sessions,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'payment_method' => ['nullable', 'string', 'in:cash,bank,cheque,mobile'],
            'tendered' => ['nullable', 'numeric', 'min:0'],
            'client_uuid' => ['nullable', 'string', 'max:64'],
            'tax_applicable' => ['nullable', 'boolean'],
            'tax_code' => ['nullable', 'string', 'max:32'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:48'],
        ]);

        try {
            $transaction = $this->commitPosSale->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['sale' => $e->getMessage()]);
        }

        $invoiceNo = $transaction->invoice?->invoice_no ?? 'sale';

        return redirect()
            ->route('pos.terminal')
            ->with('status', sprintf(
                'Sale committed: %s · total %s · change %s.',
                $invoiceNo,
                number_format((float) $transaction->total, 2),
                number_format((float) $transaction->change_due, 2),
            ))
            ->with('last_receipt', $transaction->id);
    }

    /**
     * 02-47 printable POS receipt — paper width and footer come from the
     * POS settings group (company-scoped, honest defaults when unset).
     */
    public function receipt(Request $request, PosTransaction $posTransaction): View
    {
        abort_unless($posTransaction->company_id === $request->user()->company_id, 404);

        $invoice = $posTransaction->invoice;

        // The till belongs to a branch, and a branch may print its own receipt:
        // both values are read branch-first (§15-03), so an outlet that uses 58mm
        // paper does not have to be the company default.
        $paper = (string) $this->settings->effective('pos', 'paper_width');

        return view('pos.receipt', [
            'txn' => $posTransaction,
            'invoice' => $invoice,
            'lines' => $invoice?->lines ?? collect(),
            'paperWidth' => in_array($paper, ['58', '80'], true) ? $paper : '80',
            'footer' => trim((string) $this->settings->effective('pos', 'receipt_footer')),
            // §15-07: the slip is a document, so it obeys the same switches the
            // invoices do — grouping, numerals and amount in words.
            'localization' => $this->localization,
            'taxInclusive' => $this->taxPolicy->pricesIncludeTax(),
        ]);
    }

    public function xReport(PosSession $session): View
    {
        return view('pos.report', [
            'session' => $session,
            'report' => $this->reports->x($session),
            'kind' => 'X',
        ]);
    }

    public function zReport(PosSession $session): View
    {
        return view('pos.report', [
            'session' => $session,
            'report' => $this->reports->z($session),
            'kind' => 'Z',
        ]);
    }

    public function holds(Request $request): View
    {
        $query = PosHold::query()->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('pos.holds', [
            'holds' => $query->paginate(15)->withQueryString(),
            'status' => $request->query('status'),
        ]);
    }

    public function storeHold(Request $request): RedirectResponse
    {
        $request->validate([
            'pos_session_id' => ['nullable', 'integer', 'exists:pos_sessions,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $hold = $this->holdPosOrder->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['hold' => $e->getMessage()]);
        }

        return redirect()
            ->route('pos.holds.index')
            ->with('status', "Cart held as {$hold->hold_no}.");
    }

    public function resumeHold(Request $request, PosHold $hold): RedirectResponse
    {
        try {
            $fresh = $this->resumePosOrder->handle($hold, $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['hold' => $e->getMessage()]);
        }

        return back()->with('status', "Hold {$fresh->hold_no} resumed (stock re-reserved).");
    }

    public function offlineSync(Request $request): JsonResponse
    {
        // 02-47: an honest config gate — disabled means no batch commits.
        if (! $this->settings->getBool('pos', 'offline_enabled', true)) {
            return response()->json([
                'message' => 'Offline POS sync is disabled in POS settings.',
            ], 403);
        }

        $request->validate([
            'sales' => ['required', 'array', 'min:1'],
            'sales.*.client_uuid' => ['required', 'string', 'max:64'],
            'sales.*.lines' => ['required', 'array', 'min:1'],
            'sales.*.lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'sales.*.lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'sales.*.payment_method' => ['nullable', 'string', 'in:cash,bank,cheque,mobile'],
            'sales.*.client_total' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $result = $this->offlineSync->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, $result['failed'] > 0 && $result['committed'] === 0 ? 422 : 200);
    }

    /**
     * 02-38 POS Return screen: look a POS invoice up by number, hand the
     * lines back for return quantities. Honest states — nothing found
     * says so; only invoice_type=pos is searchable.
     */
    public function returns(Request $request): View
    {
        $search = trim((string) $request->query('invoice'));
        $invoice = null;

        if ($search !== '') {
            $invoice = Invoice::query()
                ->with('lines.product')
                ->where('company_id', $request->user()->company_id)
                ->where('invoice_type', 'pos')
                ->where('invoice_no', $search)
                ->latest('id')
                ->first();
        }

        return view('pos.returns', [
            'invoice' => $invoice,
            'search' => $search,
        ]);
    }

    /** 02-38 Counter return: full returns pipeline against a POS invoice. */
    public function returnSale(Request $request): RedirectResponse
    {
        $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'pos_session_id' => ['nullable', 'integer', 'exists:pos_sessions,id'],
            'payment_method' => ['nullable', 'string', 'in:cash,bank,mobile'],
            'return_reason_id' => ['nullable', 'integer', 'exists:return_reasons,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_line_id' => ['required', 'integer', 'exists:invoice_lines,id'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $result = $this->posReturnAction->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['return' => $e->getMessage()]);
        }

        return redirect()
            ->route('pos.returns.index')
            ->with('status', sprintf(
                'Return %s refunded: %s (%s).',
                $result['sales_return']->return_no,
                number_format((float) $result['refund']->amount, 2),
                $result['refund']->method,
            ));
    }

    /**
     * 02-39 POS Exchange screen: look a POS invoice up by number for the
     * return leg, and search products server-side (no extra permission —
     * the search rides this screen's own key) for the items taken in
     * exchange. Honest states — nothing found says so; only
     * invoice_type=pos is searchable.
     */
    public function exchange(Request $request): View
    {
        $search = trim((string) $request->query('invoice'));
        $invoice = null;

        if ($search !== '') {
            $invoice = Invoice::query()
                ->with('lines.product')
                ->where('company_id', $request->user()->company_id)
                ->where('invoice_type', 'pos')
                ->where('invoice_no', $search)
                ->latest('id')
                ->first();
        }

        $q = trim((string) $request->query('q'));
        $products = collect();

        if ($q !== '') {
            $like = '%'.$q.'%';
            $products = Product::query()
                ->where('company_id', $request->user()->company_id)
                ->where('is_active', true)
                ->where(function ($query) use ($like) {
                    $query->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('code', 'like', $like)
                        ->orWhere('barcode', 'like', $like);
                })
                ->orderBy('name')
                ->limit(20)
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'unit_price' => $this->pricing->resolveUnitPrice($product, null, null, now()->toDateString()),
                ]);
        }

        return view('pos.exchange', [
            'invoice' => $invoice,
            'search' => $search,
            'q' => $q,
            'products' => $products,
        ]);
    }

    /** 02-39 Counter exchange: return leg + new-sale leg, difference settles. */
    public function exchangeSale(Request $request): RedirectResponse
    {
        $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'pos_session_id' => ['nullable', 'integer', 'exists:pos_sessions,id'],
            'payment_method' => ['nullable', 'string', 'in:cash,bank,mobile'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_line_id' => ['required', 'integer', 'exists:invoice_lines,id'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'exchange_lines' => ['required', 'array', 'min:1'],
            'exchange_lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'exchange_lines.*.qty' => ['nullable', 'numeric', 'min:0'],
            'exchange_lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $result = $this->posExchangeAction->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['exchange' => $e->getMessage()]);
        }

        return redirect()
            ->route('pos.exchange.index')
            ->with('status', sprintf(
                'Exchange %s completed: invoice %s, difference %s via %s.',
                $result['exchange']->exchange_no,
                $result['invoice']->invoice_no,
                number_format((float) $result['exchange']->price_differential, 2),
                $result['exchange']->payment_method,
            ));
    }

    /**
     * 02-44 Cash Drawer: live state from the open session (counters +
     * expected cash using the close formula) with the cash events —
     * the balanced journal entries each movement posted — and the
     * session's audit trail below it. Honest state when nothing is open.
     */
    public function drawer(Request $request): View
    {
        return view('pos.drawer', $this->drawerData($request));
    }

    /**
     * 02-45 Cash In / Cash Out: record a drawer movement against the open
     * session; the session's cash events are listed below the form.
     */
    public function cashInOut(Request $request): View
    {
        return view('pos.cash-in-out', $this->drawerData($request));
    }

    /** 02-45 POST: one balanced journal + session counters + audited reason. */
    public function storeCashInOut(Request $request): RedirectResponse
    {
        $request->validate([
            'pos_session_id' => ['nullable', 'integer', 'exists:pos_sessions,id'],
            'direction' => ['required', 'string', 'in:in,out'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'reason' => ['required', 'string', 'max:140'],
        ]);

        try {
            $this->posCashInOut->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['cash_io' => $e->getMessage()]);
        }

        return redirect()
            ->route('pos.cash-io.index')
            ->with('status', sprintf(
                'Cash %s of %s recorded: %s.',
                $request->input('direction'),
                number_format((float) $request->input('amount'), 2),
                $request->input('reason'),
            ));
    }

    /**
     * 02-40 POS Quotation: the terminal cart becomes a draft quotation
     * through CreateQuotation — DOC only (no stock, no GL), totals and
     * pricing recomputed server-side. Rides sales.quotations.create, not
     * pos.sell — the endpoint works for any holder of that key.
     */
    public function quotationSale(Request $request): RedirectResponse
    {
        $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $quote = $this->createQuotation->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['quotation' => $e->getMessage()]);
        }

        return redirect()
            ->route('pos.terminal')
            ->with('status', sprintf(
                'Quotation %s saved as draft — %d line(s), total %s.',
                $quote->quote_no,
                $quote->lines->count(),
                number_format((float) $quote->grand_total, 2),
            ));
    }

    /**
     * 02-41 POS Layaway / Advance Deposit: the cart becomes a pending
     * sales order with stock reserved, a paid deposit invoice posting
     * Dr cash/bank / Cr customer advances, and a dated installment
     * schedule for the balance. Requires an open drawer; the deposit
     * lands on it so X report and close math agree. When a
     * sales_order/layaway workflow definition resolves, the order is
     * held for approval instead (no stock, no money).
     */
    public function layawaySale(Request $request): RedirectResponse
    {
        $request->validate([
            'sales_order_id' => ['nullable', 'integer', 'exists:sales_orders,id'],
            'pos_session_id' => ['nullable', 'integer', 'exists:pos_sessions,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'deposit_amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'installment_count' => ['required', 'integer', 'min:1', 'max:60'],
            'interval_days' => ['required', 'integer', 'min:1', 'max:365'],
            'payment_method' => ['required', 'string', 'in:cash,bank,cheque,mobile'],
        ]);

        try {
            $result = $this->createLayaway->handle($request->all(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['layaway' => $e->getMessage()]);
        }

        if ($result['result'] === 'pending_approval') {
            return redirect()
                ->route('pos.terminal')
                ->with('status', sprintf(
                    'Layaway %s submitted for approval — no deposit taken yet.',
                    $result['order']->order_no,
                ));
        }

        $schedule = $result['schedule'];

        return redirect()
            ->route('pos.terminal')
            ->with('status', sprintf(
                'Layaway %s: deposit %s received, balance %s in %d installment(s) of ~%s every %d day(s).',
                $result['order']->order_no,
                number_format((float) $schedule->deposit_amount, 2),
                number_format((float) $schedule->balance_amount, 2),
                (int) $schedule->installment_count,
                number_format((float) $schedule->installments()->min('amount'), 2),
                (int) $schedule->interval_days,
            ));
    }

    /**
     * Shared by the drawer and cash in/out screens: this branch's open
     * session, its expected cash (the close formula), the session's cash
     * events (journal entries) and its audit trail.
     *
     * @return array{session: ?PosSession, expected: ?float, events: Collection<int, JournalEntry>, trail: Collection<int, AuditEvent>}
     */
    protected function drawerData(Request $request): array
    {
        $companyId = $request->user()->company_id;

        $session = PosSession::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $request->user()->default_branch_id)
            ->where('status', 'open')
            ->first();

        if ($session === null) {
            return [
                'session' => null,
                'expected' => null,
                'events' => collect(),
                'trail' => collect(),
            ];
        }

        $events = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('source_type', 'pos_session')
            ->where('source_id', $session->id)
            ->whereIn('source_event', ['pos_cash_in', 'pos_cash_out', 'layaway_deposit'])
            ->latest('id')
            ->limit(25)
            ->get();

        $trail = AuditEvent::query()
            ->where('company_id', $companyId)
            ->where('entity_type', 'pos_session')
            ->where('entity_id', $session->id)
            ->latest('seq')
            ->limit(15)
            ->get();

        return [
            'session' => $session,
            'expected' => round(
                (float) $session->opening_float
                + (float) $session->cash_sales
                + (float) $session->cash_in
                - (float) $session->cash_out,
                4,
            ),
            'events' => $events,
            'trail' => $trail,
        ];
    }
}
