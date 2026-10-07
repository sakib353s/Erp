<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Branch;
use App\Domain\Masters\District;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Queries\PurchaseQuery;
use App\Domain\Purchase\Services\SupplierService;
use App\Http\Requests\StoreSupplierRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Suppliers (§06). List, profile, create/edit and the blacklist — every write
 * goes through SupplierService so duplicate detection and the reason-required
 * blacklist cannot be bypassed by a form.
 */
class SupplierController extends Controller
{
    public function __construct(
        protected SupplierService $suppliers,
        protected PurchaseQuery $query,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q')),
            'status' => (string) $request->query('status'),
            'category' => (string) $request->query('category'),
            'sort' => (string) $request->query('sort'),
        ];

        return view('purchase.suppliers.index', [
            'suppliers' => $this->query->suppliers($filters),
            'filters' => $filters,
            'categories' => Supplier::CATEGORIES,
            'summary' => $this->query->summary($this->branchIds($request)),
        ]);
    }

    public function create(): View
    {
        return view('purchase.suppliers.form', [
            'supplier' => new Supplier(['is_active' => true, 'payment_terms_days' => 0]),
            'districts' => District::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Supplier::CATEGORIES,
            'mode' => 'create',
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        try {
            $supplier = $this->suppliers->create($request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['supplier' => $e->getMessage()]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('status', "Supplier {$supplier->name} created.");
    }

    public function show(Request $request, Supplier $supplier): View
    {
        return view('purchase.suppliers.show', [
            'supplier' => $supplier->loadCount(['orders', 'receipts'])->load(['district:id,name', 'blacklistedBy:id,name']),
            'openLines' => $this->query->openLines($supplier->id),
            'spend' => $this->query->supplierSpend($supplier->id),
            'recentOrders' => $supplier->orders()->with('warehouse:id,name')->orderByDesc('order_date')->limit(10)->get(),
            'recentReceipts' => $supplier->receipts()->with('order:id,code')->orderByDesc('received_date')->limit(10)->get(),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        return view('purchase.suppliers.form', [
            'supplier' => $supplier,
            'districts' => District::query()->orderBy('name')->get(['id', 'name']),
            'categories' => Supplier::CATEGORIES,
            'mode' => 'edit',
        ]);
    }

    public function update(StoreSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        try {
            $this->suppliers->update($supplier, $request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['supplier' => $e->getMessage()]);
        }

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Supplier updated.');
    }

    public function blacklist(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->suppliers->blacklist($supplier, $data['reason'], $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', "{$supplier->name} is blacklisted — no new documents can be raised.");
    }

    public function unblacklist(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->suppliers->unblacklist($supplier, $request->user()?->id);

        return back()->with('status', "{$supplier->name} reinstated.");
    }

    /** @return array<int, int> */
    protected function branchIds(Request $request): array
    {
        $ids = $request->user()?->accessibleBranchIds();

        return $ids === null ? [] : array_map('intval', $ids);
    }
}
