<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Queries\PurchaseQuery;
use App\Domain\Purchase\Services\PurchaseOrderService;
use App\Http\Requests\StorePurchaseOrderRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Purchase orders (§03). Creation, approval and cancellation are separate
 * routes gated by separate permissions — the service refuses self-approval and
 * unexplained cancellation, and the controller never computes money.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        protected PurchaseOrderService $orders,
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

        return view('purchase.orders.index', [
            'orders' => $this->query->orders($filters, $this->branchIds($request)),
            'filters' => $filters,
            'statuses' => PurchaseOrder::STATUSES,
            'suppliers' => Supplier::query()->with('district:id,name')->orderBy('name')->get(['id', 'name', 'code', 'district_id']),
            'summary' => $this->query->summary($this->branchIds($request)),
            'sparkline' => $this->query->receiptsByDay(14, $this->branchIds($request)),
        ]);
    }

    public function create(Request $request): View
    {
        return view('purchase.orders.form', [
            'order' => new PurchaseOrder([
                'order_date' => now()->toDateString(),
                'status' => 'draft',
            ]),
            'suppliers' => Supplier::query()->orderable()->orderBy('name')->get(['id', 'name', 'code', 'payment_terms_days']),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'branch_id']),
            'products' => Product::query()->active()->orderBy('name')->limit(500)->get(['id', 'sku', 'name', 'standard_cost']),
            'mode' => 'create',
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): RedirectResponse
    {
        try {
            $order = $this->orders->create($request->payload(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()->route('purchase.orders.show', $order)
            ->with('status', "Purchase order {$order->code} saved as draft.");
    }

    public function show(PurchaseOrder $order): View
    {
        return view('purchase.orders.show', [
            'order' => $order->load(['lines.product:id,sku,name', 'supplier:id,name,code,payment_terms_days', 'warehouse:id,name', 'branch:id,name', 'creator:id,name', 'approver:id,name', 'receipts:id,code,purchase_order_id,received_date,status,total']),
        ]);
    }

    public function submit(Request $request, PurchaseOrder $order): RedirectResponse
    {
        try {
            $this->orders->submit($order, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('status', "{$order->code} submitted for approval.");
    }

    public function approve(Request $request, PurchaseOrder $order): RedirectResponse
    {
        try {
            $this->orders->approve($order, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('status', "{$order->code} approved — goods can now be received against it.");
    }

    public function cancel(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        try {
            $this->orders->cancel($order, $data['cancel_reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['cancel_reason' => $e->getMessage()]);
        }

        return back()->with('status', "{$order->code} cancelled.");
    }

    /** @return array<int, int> */
    protected function branchIds(Request $request): array
    {
        $ids = $request->user()?->accessibleBranchIds();

        return $ids === null ? [] : array_map('intval', $ids);
    }
}
