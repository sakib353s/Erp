<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\PutawayList;
use App\Domain\Inventory\Services\PickingService;
use App\Domain\Inventory\WarehouseBin;
use App\Http\Requests\RecordPlacementRequest;
use App\Http\Requests\StorePutawayListRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Putaway lists (§04-44).
 *
 * Goods arrive on a dock and have to end up somewhere. The controller shapes
 * that decision: raise the list from a posted receipt (or by hand), hand it out,
 * write down which bin each line went into, finish when the dock is empty. The
 * service writes the bin map; the ledger is not touched at all.
 */
class PutawayListController extends Controller
{
    public function __construct(protected PickingService $picking) {}

    public function index(Request $request): View
    {
        $status = array_key_exists((string) $request->query('status'), PutawayList::STATUSES)
            ? (string) $request->query('status')
            : null;

        $mine = $request->query('mine') === '1';

        $filters = [
            'q' => $request->filled('q') ? trim((string) $request->query('q')) : null,
            'status' => $status,
            'warehouse_id' => $request->filled('warehouse') ? (int) $request->query('warehouse') : null,
            'assigned_to' => $mine ? (int) auth()->id() : null,
        ];

        return view('inventory.putaway.index', [
            'lists' => $this->picking->putawayLists($filters)->paginate(20)->withQueryString(),
            'filters' => $filters,
            'mine' => $mine,
            'statuses' => PutawayList::STATUSES,
            'warehouses' => $this->warehouses(),
            'stats' => $this->picking->putawayStats(),
        ]);
    }

    public function create(Request $request): View
    {
        $receipts = $this->picking->receiptsToPutAway();

        $selected = $request->filled('receipt')
            ? $receipts->firstWhere('id', (int) $request->query('receipt'))
            : null;

        return view('inventory.putaway.create', [
            'warehouses' => $this->warehouses(),
            'receipts' => $receipts,
            'selectedReceipt' => $selected,
            'products' => Product::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(400)
                ->get(['id', 'sku', 'name']),
            'blankRows' => 5,
        ]);
    }

    public function store(StorePutawayListRequest $request): RedirectResponse
    {
        try {
            $list = $this->picking->createPutawayList($request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['putaway_list' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.putaway-lists.show', $list)
            ->with('status', "Putaway list {$list->code} is ready — {$list->lines->count()} line(s) to place.");
    }

    public function show(PutawayList $putawayList): View
    {
        $this->assertSameCompany($putawayList);

        $putawayList->load([
            'lines.product:id,sku,name',
            'lines.bin:id,code,name,warehouse_zone_id',
            'lines.placedBin:id,code,name',
            'lines.batch:id,batch_no,expires_on',
            'warehouse:id,name,code',
            'receipt:id,code,challan_no,supplier_id,received_date',
            'receipt.supplier:id,name',
            'assignee:id,name',
            'creator:id,name',
        ]);

        return view('inventory.putaway.show', [
            'list' => $putawayList,
            'users' => $this->assignees(),
            'bins' => $this->bins($putawayList->warehouse_id),
        ]);
    }

    public function assign(Request $request, PutawayList $putawayList): RedirectResponse
    {
        $this->assertSameCompany($putawayList);

        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignee = filled($data['assigned_to'] ?? null)
            ? User::query()->where('company_id', $putawayList->company_id)->find((int) $data['assigned_to'])
            : null;

        try {
            $this->picking->assignPutaway($putawayList, $assignee, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['assign' => $e->getMessage()]);
        }

        return back()->with('status', $assignee === null
            ? "{$putawayList->code} is back to unassigned."
            : "{$putawayList->code} handed to {$assignee->name}.");
    }

    public function recordPlacement(RecordPlacementRequest $request, PutawayList $putawayList): RedirectResponse
    {
        $this->assertSameCompany($putawayList);

        $rows = [];

        foreach ((array) $request->input('placements', []) as $lineId => $row) {
            $rows[(int) $lineId] = [
                'bin_id' => $row['bin_id'] ?? null,
                'qty' => $row['qty'] ?? null,
                'note' => $row['note'] ?? null,
            ];
        }

        try {
            $list = $this->picking->recordPlacement($putawayList, $rows, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['placements' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s is %s: %s of %s placed, %d line(s) still on the dock.',
            $list->code,
            $list->stateLabel(),
            number_format($list->placedQty(), 4),
            number_format($list->requiredQty(), 4),
            $list->pendingLines(),
        ));
    }

    public function complete(Request $request, PutawayList $putawayList): RedirectResponse
    {
        $this->assertSameCompany($putawayList);

        try {
            $list = $this->picking->completePutaway($putawayList, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['complete' => $e->getMessage()]);
        }

        return back()->with('status', "{$list->code} finished — the dock is empty and the bin map knows where these goods live.");
    }

    public function cancel(Request $request, PutawayList $putawayList): RedirectResponse
    {
        $this->assertSameCompany($putawayList);

        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->picking->cancelPutaway($putawayList, $data['cancel_reason'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['cancel_reason' => $e->getMessage()]);
        }

        return back()->with('status', "{$putawayList->code} cancelled — the goods are still on the dock and the receipt can be put away on a new list.");
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Warehouse> */
    protected function warehouses()
    {
        return Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    protected function assignees()
    {
        return User::query()
            ->where('company_id', auth()->user()->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    protected function bins(int $warehouseId)
    {
        return WarehouseBin::query()
            ->with('zone:id,name')
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'warehouse_zone_id']);
    }

    protected function assertSameCompany(PutawayList $list): void
    {
        abort_unless(
            $list->company_id === auth()->user()->company_id,
            404,
        );
    }
}
