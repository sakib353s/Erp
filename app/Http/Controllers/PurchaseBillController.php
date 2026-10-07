<?php

namespace App\Http\Controllers;

use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Queries\PurchaseQuery;
use App\Domain\Purchase\Services\PurchaseBillService;
use App\Http\Requests\StorePurchaseBillRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Purchase bills (§03.6). Drafting, submitting, approval (which posts the
 * payable to the ledger) and cancelling an unposted bill. Nothing here computes
 * money — PurchaseBillService owns every figure.
 */
class PurchaseBillController extends Controller
{
    public function __construct(
        protected PurchaseBillService $bills,
        protected PurchaseQuery $query,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q')),
            'status' => (string) $request->query('status'),
            'supplier' => $request->query('supplier') !== null && $request->query('supplier') !== '' ? (int) $request->query('supplier') : null,
            'from' => (string) $request->query('from'),
            'to' => (string) $request->query('to'),
            'sort' => (string) $request->query('sort'),
        ];

        return view('purchase.bills.index', [
            'bills' => $this->query->bills($filters, $this->branchIds($request)),
            'filters' => $filters,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code']),
            'summary' => $this->query->billSummary($this->branchIds($request)),
        ]);
    }

    public function create(Request $request): View
    {
        $receipt = $request->filled('receipt')
            ? GoodsReceipt::query()->with(['lines.product:id,sku,name', 'supplier:id,name'])->findOrFail((int) $request->query('receipt'))
            : null;

        if ($receipt !== null && ! $receipt->isPosted()) {
            $receipt = null;
        }

        return view('purchase.bills.form', [
            'receipt' => $receipt,
            'openOrders' => $this->bills->openOrders(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code', 'payment_terms_days']),
            'billableReceipts' => $this->bills->billableReceipts(),
            'lines' => $this->bills->linesFor($receipt),
        ]);
    }

    public function store(StorePurchaseBillRequest $request): RedirectResponse
    {
        try {
            $bill = $this->bills->create($request->payload(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['bill' => $e->getMessage()]);
        }

        return redirect()->route('purchase.bills.show', $bill)
            ->with('status', "{$bill->code} saved as draft — approving it is what creates the payable.");
    }

    public function show(PurchaseBill $bill): View
    {
        $bill->load([
            'lines.product:id,sku,name', 'lines.receiptLine:id,goods_receipt_id,qty_received,unit_cost',
            'lines.orderLine:id,unit_price,qty_ordered', 'supplier:id,name,code,payment_terms_days',
            'branch:id,name', 'order:id,code,status', 'receipt:id,code,received_date,status',
            'creator:id,name', 'approver:id,name',
        ]);

        return view('purchase.bills.show', [
            'bill' => $bill,
            'match' => $this->query->matchRows($bill),
        ]);
    }

    public function submit(Request $request, PurchaseBill $bill): RedirectResponse
    {
        try {
            $this->bills->submit($bill, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['bill' => $e->getMessage()]);
        }

        return back()->with('status', "{$bill->code} submitted — it now waits for approval.");
    }

    public function approve(Request $request, PurchaseBill $bill): RedirectResponse
    {
        try {
            $this->bills->approve($bill, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['bill' => $e->getMessage()]);
        }

        return back()->with('status', "{$bill->code} approved — the payable is posted to the ledger.");
    }

    public function cancel(Request $request, PurchaseBill $bill): RedirectResponse
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        try {
            $this->bills->cancel($bill, $data['cancel_reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['cancel_reason' => $e->getMessage()]);
        }

        return back()->with('status', "{$bill->code} cancelled.");
    }

    /** @return array<int, int> */
    protected function branchIds(Request $request): array
    {
        $ids = $request->user()?->accessibleBranchIds();

        return $ids === null ? [] : array_map('intval', $ids);
    }

    /**
     * Bill ageing (03-48) — every open bill folded into the bucket its own due
     * date puts it in, grouped by supplier. The company-wide answer to "who do
     * we owe, and how late are we".
     */
    public function payables(Request $request): View
    {
        $bucket = (string) $request->query('bucket', 'all');
        $ageing = $this->query->payablesAgeing($this->branchIds($request));
        $labels = [
            'current' => 'Not yet due',
            'd1_30' => '1–30 days late',
            'd31_60' => '31–60 days late',
            'd61_90' => '61–90 days late',
            'd90_plus' => 'More than 90 days late',
        ];

        return view('purchase.payables', [
            'ageing' => $ageing,
            'bucket' => $bucket,
            'labels' => $labels,
            'focus' => array_key_exists($bucket, $labels) ? $labels[$bucket] : null,
        ]);
    }
}
