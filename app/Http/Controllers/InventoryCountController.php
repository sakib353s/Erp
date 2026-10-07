<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Inventory\StockCount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Stock count / cycle count (§04-31).
 *
 * The controller shapes the counting ritual: open a sheet, write down what is
 * on the shelf, then let a manager post the difference. It never decides a
 * quantity and never writes stock — that is StockCountService's job.
 */
class InventoryCountController extends Controller
{
    public function __construct(protected StockCountService $counts) {}

    public function index(Request $request): View
    {
        $status = array_key_exists((string) $request->query('status'), StockCount::STATUSES)
            ? (string) $request->query('status')
            : null;

        $scope = array_key_exists((string) $request->query('scope'), StockCount::SCOPES)
            ? (string) $request->query('scope')
            : null;

        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;

        return view('inventory.counts.index', [
            'counts' => $this->counts->sessions([
                'status' => $status,
                'scope' => $scope,
                'warehouse' => $warehouseId,
                'from' => $request->filled('from') ? (string) $request->query('from') : null,
                'to' => $request->filled('to') ? (string) $request->query('to') : null,
            ]),
            'filters' => [
                'status' => $status,
                'scope' => $scope,
                'warehouse' => $warehouseId,
                'from' => $request->filled('from') ? (string) $request->query('from') : null,
                'to' => $request->filled('to') ? (string) $request->query('to') : null,
            ],
            'statuses' => StockCount::STATUSES,
            'scopes' => StockCount::SCOPES,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']),
            'analytics' => $this->counts->analytics(
                $request->filled('from') ? (string) $request->query('from') : null,
                $request->filled('to') ? (string) $request->query('to') : null,
                $warehouseId,
            ),
        ]);
    }

    public function create(Request $request): View
    {
        return view('inventory.counts.create', [
            'scope' => $request->query('scope') === StockCount::SCOPE_CYCLE
                ? StockCount::SCOPE_CYCLE
                : StockCount::SCOPE_FULL,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'products' => Product::query()->active()->stocked()->orderBy('sku')->get(['id', 'sku', 'name']),
            'scopes' => StockCount::SCOPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'count_date' => ['required', 'date'],
            'scope' => ['required', 'in:full,cycle'],
            'notes' => ['nullable', 'string', 'max:500'],
            'products' => ['required_if:scope,cycle', 'array'],
            'products.*' => ['integer', 'exists:products,id'],
        ], [], [
            'warehouse_id' => 'warehouse',
            'count_date' => 'count date',
            'products' => 'products to count',
        ]);

        try {
            $count = $this->counts->open($data, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['scope' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.counts.show', $count)
            ->with('status', "Count sheet {$count->code} opened with {$count->line_count} line(s) — write down what is on the shelf.");
    }

    public function show(Request $request, StockCount $count): View
    {
        $count = $this->counts->sheet($count);

        return view('inventory.counts.show', [
            'count' => $count,
            // A counter who cannot post a variance does not see the expected
            // number: a blind count is the only one that finds anything.
            'blind' => ! app(PermissionCatalog::class)->allows($request->user(), 'inventory.counts.post'),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function saveCounts(Request $request, StockCount $count): RedirectResponse
    {
        $data = $request->validate([
            'counted' => ['required', 'array'],
            'counted.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['counted' => 'counted quantities']);

        try {
            $count = $this->counts->recordCounts($count, $data['counted'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['counted' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s line(s) counted on %s — %s with a difference.',
            $count->counted_lines,
            $count->code,
            $count->variance_lines,
        ));
    }

    public function postCount(Request $request, StockCount $count): RedirectResponse
    {
        try {
            $count = $this->counts->post($count, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['counted' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s posted — %s line(s) with a variance, %s applied to stock%s.',
            $count->code,
            $count->variance_lines,
            number_format((float) $count->variance_value, 2),
            $count->adjustment ? ' as adjustment '.$count->adjustment->adjustment_no : '',
        ));
    }

    public function cancel(Request $request, StockCount $count): RedirectResponse
    {
        $data = $request->validate([
            'cancel_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->counts->cancel($count, $data['cancel_note'] ?? null, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['cancel_note' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.counts.index')
            ->with('status', "Count sheet {$count->code} cancelled — nothing was corrected.");
    }
}
