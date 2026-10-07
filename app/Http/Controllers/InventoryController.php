<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\PostStockAdjustment;
use App\Domain\Inventory\Actions\StockTransferService;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\Services\ReorderService;
use App\Domain\Inventory\Services\StockQuery;
use App\Domain\Inventory\StockAdjustment;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\StockTransfer;
use App\Http\Requests\ReceiveStockTransferRequest;
use App\Http\Requests\StoreOpeningStockRequest;
use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Http\Requests\StoreStockTransferRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inventory stock screens (04-21…04-33). All mutations flow through
 * StockLedgerService — controllers never write stock_movements directly.
 */
class InventoryController extends Controller
{
    public function __construct(
        protected StockQuery $stockQuery,
        protected CreateOpeningStock $openingStock,
        protected PostStockAdjustment $postAdjustment,
        protected StockTransferService $transfers,
        protected ReorderService $reorder,
    ) {}

    public function overview(Request $request): View
    {
        $branchId = $request->query('branch')
            ? (int) $request->query('branch')
            : $request->user()->default_branch_id;

        $warehouseId = $request->query('warehouse')
            ? (int) $request->query('warehouse')
            : null;

        $result = $this->stockQuery->overview(
            (int) $request->user()->company_id,
            $request->query('all_branches') ? null : $branchId,
            $warehouseId,
            $request->query('q'),
        );

        return view('inventory.stock.overview', [
            'rows' => $result['rows'],
            'totals' => $result['totals'],
            'q' => $request->query('q'),
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
        ]);
    }

    public function movements(Request $request): View
    {
        $query = StockMovement::query()
            ->with(['product', 'warehouse', 'actor'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($type = $request->query('type')) {
            $query->where('movement_type', $type);
        }

        if ($productId = $request->query('product_id')) {
            $query->where('product_id', (int) $productId);
        }

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', (int) $warehouseId);
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return view('inventory.stock.movements', [
            'movements' => $query->paginate(20)->withQueryString(),
            'type' => $type ?? '',
            'q' => $request->query('q'),
            'types' => [
                StockMovement::TYPE_OPENING,
                StockMovement::TYPE_ADJUST_IN,
                StockMovement::TYPE_ADJUST_OUT,
                StockMovement::TYPE_TRANSIT_OUT,
                StockMovement::TYPE_TRANSIT_IN,
                StockMovement::TYPE_DAMAGE_OUT,
                StockMovement::TYPE_WRITE_OFF,
            ],
        ]);
    }

    public function productLedger(Product $product): View
    {
        return view('inventory.stock.product-ledger', [
            'ledger' => $this->stockQuery->productLedger($product),
            'product' => $product,
        ]);
    }

    public function createOpening(Request $request): View
    {
        return view('inventory.stock.opening-form', [
            'products' => Product::query()->active()->stocked()->orderBy('sku')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeOpening(StoreOpeningStockRequest $request): RedirectResponse
    {
        try {
            $movements = $this->openingStock->handle($request->validated(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.stock')
            ->with('status', count($movements).' opening line(s) posted.');
    }

    public function adjustments(Request $request): View
    {
        $status = (string) $request->query('status');

        $query = StockAdjustment::query()
            ->with(['warehouse', 'creator', 'approver', 'lines.product'])
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q->where('adjustment_no', 'like', "%{$search}%")
                ->orWhere('reason', 'like', "%{$search}%"));
        }

        $counts = [
            'all' => StockAdjustment::query()->count(),
            'pending' => StockAdjustment::query()->where('status', StockAdjustment::STATUS_PENDING)->count(),
            'posted' => StockAdjustment::query()->where('status', StockAdjustment::STATUS_POSTED)->count(),
            'rejected' => StockAdjustment::query()->where('status', StockAdjustment::STATUS_REJECTED)->count(),
        ];

        $pendingValue = (float) StockAdjustment::query()
            ->where('status', StockAdjustment::STATUS_PENDING)
            ->sum('total_value');

        return view('inventory.adjustments.index', [
            'adjustments' => $query->paginate(15)->withQueryString(),
            'counts' => $counts,
            'status' => $status,
            'q' => $search,
            'pendingValue' => $pendingValue,
            'threshold' => $this->postAdjustment->approvalThreshold(),
        ]);
    }

    /**
     * §04-26 — the history of the document, not just its current state: who
     * raised it, who decided it and what the ledger was actually asked to do.
     */
    public function adjustmentHistory(Request $request): View
    {
        $status = (string) $request->query('status');

        $query = StockAdjustment::query()
            ->with(['warehouse', 'creator', 'approver'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        return view('inventory.adjustments.history', [
            'adjustments' => $query->paginate(25)->withQueryString(),
            'status' => $status,
            'threshold' => $this->postAdjustment->approvalThreshold(),
        ]);
    }

    public function createAdjustment(): View
    {
        return view('inventory.adjustments.form', [
            'products' => Product::query()->active()->stocked()->orderBy('sku')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'threshold' => $this->postAdjustment->approvalThreshold(),
        ]);
    }

    public function storeAdjustment(StoreStockAdjustmentRequest $request): RedirectResponse
    {
        try {
            $adjustment = $this->postAdjustment->submit($request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        if ($adjustment->isPending()) {
            return redirect()
                ->route('inventory.adjustments.index', ['status' => StockAdjustment::STATUS_PENDING])
                ->with('status', sprintf(
                    'Adjustment %s is worth %s and is waiting for a second pair of eyes — no stock has moved yet.',
                    $adjustment->adjustment_no,
                    number_format((float) $adjustment->total_value, 2),
                ));
        }

        return redirect()
            ->route('inventory.adjustments.index')
            ->with('status', "Adjustment {$adjustment->adjustment_no} posted.");
    }

    /** Approve a held adjustment: the stock moves now, once. */
    public function approveAdjustment(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        $note = trim((string) $request->input('note'));

        try {
            $this->postAdjustment->approve($adjustment, $request->user(), $note !== '' ? $note : null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['adjustment' => $e->getMessage()]);
        }

        return back()->with('status', "Adjustment {$adjustment->adjustment_no} approved and posted.");
    }

    /** Refuse a held adjustment — which moves nothing, by definition. */
    public function rejectAdjustment(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        try {
            $this->postAdjustment->reject($adjustment, $request->user(), (string) $request->input('note'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['adjustment' => $e->getMessage()]);
        }

        return back()->with('status', "Adjustment {$adjustment->adjustment_no} rejected — the stock was left alone.");
    }

    public function transfers(Request $request): View
    {
        $status = (string) $request->query('status');

        $query = StockTransfer::query()
            ->with(['fromWarehouse', 'toWarehouse', 'creator', 'approver', 'lines.product'])
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q->where('transfer_no', 'like', "%{$search}%")
                ->orWhere('narration', 'like', "%{$search}%"));
        }

        $counts = [
            'pending_approval' => StockTransfer::query()->where('status', StockTransfer::STATUS_PENDING)->count(),
            'draft' => StockTransfer::query()->where('status', StockTransfer::STATUS_DRAFT)->count(),
            'dispatched' => StockTransfer::query()->where('status', StockTransfer::STATUS_DISPATCHED)->count(),
            'received' => StockTransfer::query()->whereIn('status', [StockTransfer::STATUS_RECEIVED, StockTransfer::STATUS_DISCREPANCY])->count(),
        ];

        return view('inventory.transfers.index', [
            'transfers' => $query->paginate(15)->withQueryString(),
            'status' => $status,
            'q' => $search,
            'counts' => $counts,
            'pendingValue' => (float) StockTransfer::query()
                ->where('status', StockTransfer::STATUS_PENDING)
                ->sum('total_value'),
            'threshold' => $this->transfers->approvalThreshold(),
        ]);
    }

    public function createTransfer(): View
    {
        return view('inventory.transfers.form', [
            'products' => Product::query()->active()->stocked()->orderBy('sku')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'threshold' => $this->transfers->approvalThreshold(),
        ]);
    }

    public function storeTransfer(StoreStockTransferRequest $request): RedirectResponse
    {
        try {
            $transfer = $this->transfers->create($request->validated(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        if ($transfer->isPending()) {
            return redirect()
                ->route('inventory.transfers.index', ['status' => StockTransfer::STATUS_PENDING])
                ->with('status', sprintf(
                    'Transfer %s is worth %s and is waiting for approval — nothing has left the warehouse.',
                    $transfer->transfer_no,
                    number_format((float) $transfer->total_value, 2),
                ));
        }

        return redirect()
            ->route('inventory.transfers.index')
            ->with('status', "Transfer {$transfer->transfer_no} created.");
    }

    /** Approve a held transfer: it becomes dispatchable, and nothing has moved yet. */
    public function approveTransfer(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $note = trim((string) $request->input('note'));

        try {
            $this->transfers->approve($transfer, $request->user(), $note !== '' ? $note : null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return back()->with('status', "Transfer {$transfer->transfer_no} approved — it can be dispatched now.");
    }

    /** Refuse a held transfer: it can never be dispatched afterwards. */
    public function rejectTransfer(Request $request, StockTransfer $transfer): RedirectResponse
    {
        try {
            $this->transfers->reject($transfer, $request->user(), (string) $request->input('note'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return back()->with('status', "Transfer {$transfer->transfer_no} rejected — the stock stayed where it is.");
    }

    public function dispatchTransfer(StockTransfer $transfer, Request $request): RedirectResponse
    {
        try {
            $this->transfers->dispatch($transfer, $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.transfers.index')
            ->with('status', "Transfer {$transfer->transfer_no} dispatched.");
    }

    public function receiveTransfer(ReceiveStockTransferRequest $request, StockTransfer $transfer): RedirectResponse
    {
        try {
            $this->transfers->receive(
                $transfer,
                $request->validated('lines') ?? [],
                $request,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.transfers.index')
            ->with('status', "Transfer {$transfer->transfer_no} received.");
    }

    public function rebuildBalances(Request $request): RedirectResponse
    {
        $count = app(StockLedgerService::class)
            ->rebuildBalances((int) $request->user()->company_id);

        return back()->with('status', "Stock balances rebuilt from ledger for {$count} row(s).");
    }

    /* ---------------------- stock alerts & reorder levels (04-23, 04-24) ---- */

    public function alerts(Request $request): View
    {
        $type = in_array($request->query('type'), ['low', 'out', 'over'], true)
            ? (string) $request->query('type')
            : 'low';

        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;
        $search = trim((string) $request->query('q'));

        $result = $this->reorder->alertRows($type, $warehouseId, $search === '' ? null : $search);

        return view('inventory.stock.alerts', [
            'type' => $type,
            'filters' => ['warehouse' => $warehouseId, 'q' => $search],
            'rows' => $result['rows'],
            'counts' => $result['counts'],
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']),
            // §04-55: "is this urgent?" needs demand as well as a level — the
            // window is named on screen so the average day can be argued with.
            'demandDays' => $this->reorder->demandWindowDays(),
        ]);
    }

    public function reorderLevels(Request $request): View
    {
        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;
        $search = trim((string) $request->query('q'));

        return view('inventory.reorder-levels', [
            'policies' => $this->reorder->policies($warehouseId, $search === '' ? null : $search),
            'filters' => ['warehouse' => $warehouseId, 'q' => $search],
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']),
            'products' => Product::query()->active()->orderBy('name')->get(['id', 'sku', 'name']),
        ]);
    }

    public function storeReorderLevel(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'min_level' => ['required', 'numeric', 'gte:0'],
            'max_level' => ['required', 'numeric', 'gte:0'],
            'reorder_point' => ['required', 'numeric', 'gte:0'],
            'safety_stock' => ['required', 'numeric', 'gte:0'],
            'reorder_qty' => ['required', 'numeric', 'gte:0'],
            'lead_time_days' => ['required', 'integer', 'between:0,365'],
        ]);

        $product = Product::query()->findOrFail($data['product_id']);

        try {
            $policy = $this->reorder->savePolicy(
                $product,
                $data,
                $data['warehouse_id'] ?? null,
                $request->user()?->id,
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['reorder' => $e->getMessage()]);
        }

        return redirect()->route('inventory.reorder.index')
            ->with('status', sprintf(
                'Reorder policy saved for %s %s.',
                $product->sku,
                $policy->warehouse_id === null ? 'across every warehouse' : 'in the chosen warehouse',
            ));
    }

    public function destroyReorderLevel(Request $request, int $policy): RedirectResponse
    {
        $record = \App\Domain\Inventory\ReorderPolicy::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($policy);

        $this->reorder->deletePolicy($record, $request->user()?->id);

        return back()->with('status', 'The policy was removed — this product is no longer watched in that warehouse.');
    }
}
