<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Queries\PurchaseQuery;
use App\Domain\Purchase\Services\GoodsReceiptService;
use App\Http\Requests\StoreGoodsReceiptRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Goods received notes (§03). Drafting, posting (which is what moves stock) and
 * cancellation while still draft. Posting permission is separate from drafting
 * because posting is the moment inventory and the ledger become facts.
 */
class GoodsReceiptController extends Controller
{
    public function __construct(
        protected GoodsReceiptService $receipts,
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
        ];

        return view('purchase.receipts.index', [
            'receipts' => $this->query->receipts($filters, $this->branchIds($request)),
            'filters' => $filters,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'code']),
            'summary' => $this->query->summary($this->branchIds($request)),
        ]);
    }

    public function create(Request $request): View
    {
        $order = null;

        if ($request->filled('order')) {
            $order = PurchaseOrder::query()
                ->with(['lines.product:id,sku,name', 'lines' => fn ($q) => $q->orderBy('sort_order')])
                ->open()
                ->findOrFail((int) $request->query('order'));
        }

        return view('purchase.receipts.form', [
            'order' => $order,
            'openOrders' => PurchaseOrder::query()->open()->with('supplier:id,name')->orderByDesc('order_date')->limit(100)->get(['id', 'code', 'supplier_id', 'warehouse_id', 'order_date']),
            'suppliers' => Supplier::query()->orderable()->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'branch_id']),
            'products' => Product::query()->active()->orderBy('name')->limit(500)->get(['id', 'sku', 'name', 'standard_cost']),
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request): RedirectResponse
    {
        try {
            $receipt = $this->receipts->create($request->payload(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['receipt' => $e->getMessage()]);
        }

        return redirect()->route('purchase.receipts.show', $receipt)
            ->with('status', "Goods receipt {$receipt->code} saved as draft — post it to move stock.");
    }

    public function show(GoodsReceipt $receipt): View
    {
        return view('purchase.receipts.show', [
            'receipt' => $receipt->load(['lines.product:id,sku,name', 'supplier:id,name,code', 'warehouse:id,name', 'branch:id,name', 'order:id,code,status', 'receiver:id,name', 'poster:id,name']),
        ]);
    }

    public function post(Request $request, GoodsReceipt $receipt): RedirectResponse
    {
        try {
            $result = $this->receipts->post($receipt, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['receipt' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s posted — %d stock movement(s), ৳ %s of stock received.',
            $receipt->code,
            $result['movements'],
            number_format($result['value'], 2),
        ));
    }

    public function cancel(Request $request, GoodsReceipt $receipt): RedirectResponse
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        try {
            $this->receipts->cancel($receipt, $data['cancel_reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['cancel_reason' => $e->getMessage()]);
        }

        return back()->with('status', "{$receipt->code} cancelled.");
    }

    /** @return array<int, int> */
    protected function branchIds(Request $request): array
    {
        $ids = $request->user()?->accessibleBranchIds();

        return $ids === null ? [] : array_map('intval', $ids);
    }
}
