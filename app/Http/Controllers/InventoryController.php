<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\PostStockAdjustment;
use App\Domain\Inventory\Actions\StockTransferService;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
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
        $query = StockAdjustment::query()
            ->with(['warehouse', 'lines.product'])
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id');

        return view('inventory.adjustments.index', [
            'adjustments' => $query->paginate(15)->withQueryString(),
        ]);
    }

    public function createAdjustment(): View
    {
        return view('inventory.adjustments.form', [
            'products' => Product::query()->active()->stocked()->orderBy('sku')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeAdjustment(StoreStockAdjustmentRequest $request): RedirectResponse
    {
        try {
            $adjustment = $this->postAdjustment->handle($request->validated(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.adjustments.index')
            ->with('status', "Adjustment {$adjustment->adjustment_no} posted.");
    }

    public function transfers(Request $request): View
    {
        $query = StockTransfer::query()
            ->with(['fromWarehouse', 'toWarehouse', 'lines.product'])
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return view('inventory.transfers.index', [
            'transfers' => $query->paginate(15)->withQueryString(),
            'status' => $status ?? '',
        ]);
    }

    public function createTransfer(): View
    {
        return view('inventory.transfers.form', [
            'products' => Product::query()->active()->stocked()->orderBy('sku')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeTransfer(StoreStockTransferRequest $request): RedirectResponse
    {
        try {
            $transfer = $this->transfers->create($request->validated(), $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.transfers.index')
            ->with('status', "Transfer {$transfer->transfer_no} created.");
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
}
