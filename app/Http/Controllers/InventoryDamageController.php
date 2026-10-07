<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\DamageService;
use App\Domain\Inventory\StockDamageEntry;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\StockWriteoff;
use App\Http\Requests\StoreStockDamageEntryRequest;
use App\Http\Requests\StoreStockWriteoffRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Damage & loss (§04-46…04-49). Every quantity reaches the ledger through
 * DamageService → StockLedgerService; the controller only shapes requests and
 * screens, and it never decides what a value is worth.
 */
class InventoryDamageController extends Controller
{
    public function __construct(protected DamageService $damage) {}

    /* ------------------------------------------------- registers (04-46/47) --- */

    public function damage(Request $request): View
    {
        return $this->register($request, StockDamageEntry::KIND_DAMAGE);
    }

    public function losses(Request $request): View
    {
        return $this->register($request, StockDamageEntry::KIND_LOSS);
    }

    protected function register(Request $request, string $kind): View
    {
        $filters = [
            'kind' => $kind,
            'warehouse' => $request->filled('warehouse') ? (int) $request->query('warehouse') : null,
            'reason_code' => $request->filled('reason') ? (string) $request->query('reason') : null,
            'from' => $request->filled('from') ? (string) $request->query('from') : null,
            'to' => $request->filled('to') ? (string) $request->query('to') : null,
        ];

        return view('inventory.damage.index', [
            'kind' => $kind,
            'entries' => $this->damage->entries($filters),
            'filters' => $filters,
            'reasons' => $kind === StockDamageEntry::KIND_LOSS
                ? StockDamageEntry::LOSS_REASONS
                : StockDamageEntry::DAMAGE_REASONS,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function createDamage(): View
    {
        return $this->entryForm(StockDamageEntry::KIND_DAMAGE);
    }

    public function createLoss(): View
    {
        return $this->entryForm(StockDamageEntry::KIND_LOSS);
    }

    protected function entryForm(string $kind): View
    {
        return view('inventory.damage.form', [
            'kind' => $kind,
            'reasons' => $kind === StockDamageEntry::KIND_LOSS
                ? StockDamageEntry::LOSS_REASONS
                : StockDamageEntry::DAMAGE_REASONS,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'products' => Product::query()->active()->stocked()->orderBy('sku')->get(['id', 'sku', 'name']),
        ]);
    }

    public function storeDamage(StoreStockDamageEntryRequest $request): RedirectResponse
    {
        return $this->storeEntry($request, StockDamageEntry::KIND_DAMAGE);
    }

    public function storeLoss(StoreStockDamageEntryRequest $request): RedirectResponse
    {
        return $this->storeEntry($request, StockDamageEntry::KIND_LOSS);
    }

    protected function storeEntry(StoreStockDamageEntryRequest $request, string $kind): RedirectResponse
    {
        try {
            $entry = $this->damage->recordEntry($kind, $request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route($kind === StockDamageEntry::KIND_LOSS ? 'inventory.loss.index' : 'inventory.damage.index')
            ->with('status', sprintf(
                '%s %s recorded — %s.',
                $kind === StockDamageEntry::KIND_LOSS ? 'Loss' : 'Damage',
                $entry->code,
                $kind === StockDamageEntry::KIND_LOSS ? 'stock and its value have left the books' : 'goods are now held as damaged, still valued',
            ));
    }

    public function release(Request $request, StockDamageEntry $entry): RedirectResponse
    {
        try {
            $this->damage->releaseDamage($entry, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['entry' => $e->getMessage()]);
        }

        return back()->with('status', "Entry {$entry->code} released — the goods are sellable again.");
    }

    /* ------------------------------------------------- write-offs (04-48) ----- */

    public function writeoffs(Request $request): View
    {
        $status = in_array($request->query('status'), [
            StockWriteoff::STATUS_PENDING,
            StockWriteoff::STATUS_APPROVED,
            StockWriteoff::STATUS_REJECTED,
            StockWriteoff::STATUS_CANCELLED,
        ], true) ? (string) $request->query('status') : '';

        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;

        // The counts answer for the same slice of the business the list does —
        // a warehouse filter that left the numbers company-wide would be a
        // number nobody could reconcile.
        $companyId = (int) $request->user()->company_id;
        $countFor = fn (string $state) => StockWriteoff::query()
            ->forCompany($companyId)
            ->where('status', $state)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->count();

        return view('inventory.writeoffs.index', [
            'writeoffs' => $this->damage->writeoffs($status, $warehouseId),
            'status' => $status,
            'warehouse' => $warehouseId,
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name', 'code']),
            'holdings' => $this->damage->compartmentHoldings(StockMovement::STATE_DAMAGED, $warehouseId),
            'counts' => [
                'pending' => $countFor(StockWriteoff::STATUS_PENDING),
                'approved' => $countFor(StockWriteoff::STATUS_APPROVED),
                'rejected' => $countFor(StockWriteoff::STATUS_REJECTED),
            ],
        ]);
    }

    public function createWriteoff(Request $request): View
    {
        $warehouseId = $request->filled('warehouse') ? (int) $request->query('warehouse') : null;

        // §04-41: the expiry desk hands over what it is looking at — the product,
        // how much of it is left and why — so raising the write-off starts filled
        // in instead of making somebody retype what the screen already knew.
        $prefill = [
            'product_id' => $request->filled('product') ? (int) $request->query('product') : null,
            'qty' => $request->filled('qty') ? (float) $request->query('qty') : null,
            'source_state' => $request->filled('source') ? (string) $request->query('source') : null,
            'reason' => $request->filled('reason') ? (string) $request->query('reason') : null,
        ];

        return view('inventory.writeoffs.form', [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'products' => Product::query()->active()->stocked()->orderBy('sku')->get(['id', 'sku', 'name']),
            'sources' => StockWriteoff::SOURCE_STATES,
            'holdings' => $this->damage->compartmentHoldings(StockMovement::STATE_DAMAGED, $warehouseId),
            'prefill' => $prefill,
        ]);
    }

    public function storeWriteoff(StoreStockWriteoffRequest $request): RedirectResponse
    {
        try {
            $writeoff = $this->damage->raiseWriteoff($request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('inventory.writeoffs.index')
            ->with('status', "Write-off {$writeoff->code} raised — it now waits for a second person to approve it.");
    }

    public function approveWriteoff(Request $request, StockWriteoff $writeoff): RedirectResponse
    {
        try {
            $approved = $this->damage->approveWriteoff($writeoff, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['writeoff' => $e->getMessage()]);
        }

        return back()->with('status', sprintf(
            'Write-off %s approved — %s left stock and the cost is posted.',
            $approved->code,
            $approved->sourceLabel(),
        ));
    }

    public function rejectWriteoff(Request $request, StockWriteoff $writeoff): RedirectResponse
    {
        $data = $request->validate([
            'decision_note' => ['required', 'string', 'max:500'],
        ], [], ['decision_note' => 'reason']);

        try {
            $this->damage->rejectWriteoff($writeoff, $data['decision_note'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['writeoff' => $e->getMessage()]);
        }

        return back()->with('status', "Write-off {$writeoff->code} rejected — nothing moved.");
    }
}
