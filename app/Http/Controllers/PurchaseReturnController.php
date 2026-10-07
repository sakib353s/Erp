<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Warehouse;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Models\PurchaseReturn;
use App\Domain\Purchase\Queries\PurchaseQuery;
use App\Domain\Purchase\Services\PurchaseReturnService;
use App\Http\Requests\StorePurchaseReturnRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Purchase returns (§03.9). Every write goes through PurchaseReturnService:
 * quantities are capped against what was actually received, stock leaves only
 * for stock-managed lines, and approval posts the debit note to the ledger and
 * against the bill it corrects.
 */
class PurchaseReturnController extends Controller
{
    public function __construct(
        protected PurchaseReturnService $returns,
        protected PurchaseQuery $query,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q')),
            'status' => (string) $request->query('status'),
            'supplier' => $request->filled('supplier') ? (int) $request->query('supplier') : null,
            'from' => (string) $request->query('from'),
            'to' => (string) $request->query('to'),
        ];

        return view('purchase.returns.index', [
            'returns' => $this->query->returns($filters, $this->branchIds($request)),
            'filters' => $filters,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code']),
            'summary' => $this->query->returnSummary($this->branchIds($request)),
        ]);
    }

    public function create(Request $request): View
    {
        $receipt = $request->filled('receipt')
            ? GoodsReceipt::query()->with('supplier:id,name,code')->findOrFail((int) $request->query('receipt'))
            : null;

        $bill = $request->filled('bill')
            ? PurchaseBill::query()->with('supplier:id,name,code')->findOrFail((int) $request->query('bill'))
            : null;

        return view('purchase.returns.form', [
            'receipt' => $receipt,
            'bill' => $bill,
            'receiptLines' => $receipt !== null ? $this->returns->linesFor($receipt) : [],
            'returnableReceipts' => $this->returns->returnableReceipts(),
            'creditableBills' => $this->returns->creditableBills(),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(StorePurchaseReturnRequest $request): RedirectResponse
    {
        try {
            $return = $this->returns->create($request->payload(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['return' => $e->getMessage()]);
        }

        return redirect()->route('purchase.returns.show', $return)
            ->with('status', "Return {$return->code} is saved as a draft. Submit it for approval when the goods are ready to go back.");
    }

    public function show(Request $request, PurchaseReturn $return): View
    {
        $return->load(['lines.product:id,name,sku', 'supplier:id,name,code,phone', 'receipt:id,code', 'bill:id,code', 'order:id,code', 'warehouse:id,name']);

        return view('purchase.returns.show', [
            'return' => $return,
            'credit' => $return->bill_id !== null ? (float) $return->bill?->due_amount : null,
        ]);
    }

    public function submit(Request $request, PurchaseReturn $return): RedirectResponse
    {
        try {
            $this->returns->submit($return, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        return back()->with('status', "{$return->code} is waiting for approval.");
    }

    public function approve(Request $request, PurchaseReturn $return): RedirectResponse
    {
        try {
            $this->returns->approve($return, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        return back()->with('status', "{$return->code} is approved — the goods are out of stock and the debit note is posted.");
    }

    public function cancel(Request $request, PurchaseReturn $return): RedirectResponse
    {
        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        try {
            $this->returns->cancel($return, $data['cancel_reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['return' => $e->getMessage()]);
        }

        return back()->with('status', "{$return->code} is cancelled.");
    }

    /** @return array<int, int> */
    protected function branchIds(Request $request): array
    {
        $ids = $request->user()?->accessibleBranchIds();

        return $ids === null ? [] : array_map('intval', $ids);
    }
}
