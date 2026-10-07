<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\PickList;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\PickingService;
use App\Http\Requests\RecordPickRequest;
use App\Http\Requests\StorePickListRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pick lists (§04-44).
 *
 * The controller shapes the walk: pick an order or write the lines by hand, hand
 * the list to somebody, write down what came off the shelf, finish. It never
 * decides a quantity and never writes stock — that is PickingService's job, and
 * the ledger's only.
 */
class PickListController extends Controller
{
    public function __construct(protected PickingService $picking) {}

    public function index(Request $request): View
    {
        $status = array_key_exists((string) $request->query('status'), PickList::STATUSES)
            ? (string) $request->query('status')
            : null;

        $mine = $request->query('mine') === '1';

        $filters = [
            'q' => $request->filled('q') ? trim((string) $request->query('q')) : null,
            'status' => $status,
            'warehouse_id' => $request->filled('warehouse') ? (int) $request->query('warehouse') : null,
            'assigned_to' => $mine ? (int) auth()->id() : null,
        ];

        return view('inventory.picking.index', [
            'lists' => $this->picking->pickLists($filters)->paginate(20)->withQueryString(),
            'filters' => $filters,
            'mine' => $mine,
            'statuses' => PickList::STATUSES,
            'warehouses' => $this->warehouses(),
            'stats' => $this->picking->pickStats(),
        ]);
    }

    public function create(Request $request): View
    {
        $orders = $this->picking->ordersToPick();

        // ?order=12 opens the form with that order already chosen — the register
        // of open orders links here, and a link that forgets what it was for is
        // a link that makes somebody pick the same thing twice.
        $selected = null;

        if ($request->filled('order')) {
            $selected = $orders->firstWhere('id', (int) $request->query('order'));
        }

        return view('inventory.picking.create', [
            'warehouses' => $this->warehouses(),
            'orders' => $orders,
            'selectedOrder' => $selected,
            'products' => $this->products(),
            'blankRows' => 5,
        ]);
    }

    public function store(StorePickListRequest $request): RedirectResponse
    {
        try {
            $list = $this->picking->createPickList($request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['pick_list' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.pick-lists.show', $list)
            ->with('status', "Pick list {$list->code} is ready — {$list->lines->count()} line(s) to walk.");
    }

    public function show(PickList $pickList): View
    {
        $this->assertSameCompany($pickList);

        $pickList->load([
            'lines.product:id,sku,name,track_batch',
            'lines.bin:id,code,name,warehouse_zone_id',
            'lines.bin.zone:id,name,purpose',
            'lines.batch:id,batch_no,expires_on',
            'warehouse:id,name,code',
            'order:id,order_no,status,customer_id',
            'order.customer:id,name',
            'assignee:id,name',
            'creator:id,name',
        ]);

        // No bin editing on a pick sheet: the bin says where the goods *are*, and
        // correcting that is a map change on the warehouse screen. What a picker
        // found (or did not find) is written down as a shortfall and a note.
        return view('inventory.picking.show', [
            'list' => $pickList,
            'users' => $this->assignees(),
        ]);
    }

    public function assign(Request $request, PickList $pickList): RedirectResponse
    {
        $this->assertSameCompany($pickList);

        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignee = filled($data['assigned_to'] ?? null)
            ? User::query()->where('company_id', $pickList->company_id)->find((int) $data['assigned_to'])
            : null;

        try {
            $this->picking->assign($pickList, $assignee, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['assign' => $e->getMessage()]);
        }

        return back()->with('status', $assignee === null
            ? "{$pickList->code} is back to unassigned."
            : "{$pickList->code} handed to {$assignee->name}.");
    }

    public function recordPick(RecordPickRequest $request, PickList $pickList): RedirectResponse
    {
        $this->assertSameCompany($pickList);

        $rows = [];

        foreach ((array) $request->input('picked', []) as $lineId => $qty) {
            $rows[(int) $lineId] = [
                'qty' => $qty,
                'note' => $request->input("line_notes.{$lineId}"),
            ];
        }

        try {
            $list = $this->picking->recordPick($pickList, $rows, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['picked' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s is %s: %s of %s picked, %d line(s) to go.',
            $list->code,
            $list->stateLabel(),
            number_format($list->pickedQty(), 4),
            number_format($list->requiredQty(), 4),
            $list->pendingLines(),
        ));
    }

    public function complete(Request $request, PickList $pickList): RedirectResponse
    {
        $this->assertSameCompany($pickList);

        try {
            $list = $this->picking->complete($pickList, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['complete' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            '%s finished — %s picked%s. Stock moves when the goods are dispatched, not here.',
            $list->code,
            number_format($list->pickedQty(), 4),
            $list->pendingLines() > 0 ? ', '.$list->pendingLines().' line(s) short' : ' in full',
        ));
    }

    public function cancel(Request $request, PickList $pickList): RedirectResponse
    {
        $this->assertSameCompany($pickList);

        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->picking->cancelPick($pickList, $data['cancel_reason'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['cancel_reason' => $e->getMessage()]);
        }

        return back()->with('status', "{$pickList->code} cancelled — nothing was picked out of the ledger, because a pick list never moves stock.");
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Warehouse> */
    /**
     * The manual rows of a walk are typed from the catalogue, and the catalogue
     * stops at the company line: another tenant's products are never offered.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    protected function products()
    {
        return Product::query()
            ->where('company_id', auth()->user()->company_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(400)
            ->get(['id', 'sku', 'name']);
    }

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

    protected function assertSameCompany(PickList $list): void
    {
        abort_unless(
            $list->company_id === auth()->user()->company_id,
            404,
        );
    }
}
