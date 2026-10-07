<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Concerns\BranchScope;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ReorderPolicy;
use App\Domain\Inventory\ReorderSuggestion;
use App\Domain\Inventory\StockBalance;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Services\PurchaseOrderService;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * §04-55/04-56/04-58 — what to reorder, written down before anybody acts on it.
 *
 * The alerts desk (§04-23) answers "which shelves are below the line somebody
 * drew?". This desk answers the next question — "and how much should we buy?" —
 * and, crucially, *records the answer before the purchase order exists*, so a
 * month later the order says which window and which figures produced it.
 *
 * Three rules hold this together:
 *
 *  1. the trigger is the policy's own line, but if a policy names a **reorder
 *     point** that is the line: it is the number somebody chose for "order now";
 *  2. the quantity is demand-led when there is demand to lead with (average day ×
 *     the days it takes to arrive + the safety stock, on top of what the
 *     reorder point already asks for), and the policy's plain quantity when
 *     nothing has moved — an average of zero is not evidence of anything, and
 *     inventing cover from it would be;
 *  3. stock already on order is subtracted, in the open purchase orders' own
 *     undelivered quantities, so the same gap is never bought twice.
 *
 * Accepting a suggestion drafts a **purchase order** through the purchase
 * module's own service: same approval ladder, same audit, never posted here.
 */
class ReorderSuggestionService
{
    public function __construct(
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected ReorderService $reorder,
        protected PurchaseOrderService $orders,
        protected SettingService $settings,
    ) {}

    /* ------------------------------------------------------------------ reads --- */

    /**
     * The desk: one row per proposal, newest look first.
     *
     * @param  array{q?: string|null, status?: string|null, warehouse_id?: int|null}  $filters
     */
    public function suggestions(array $filters = []): Builder
    {
        $companyId = $this->companyId();

        $query = ReorderSuggestion::query()
            ->where('company_id', $companyId)
            ->with([
                'product:id,sku,name',
                'warehouse:id,name,code',
                'policy:id,min_level,max_level,reorder_point,safety_stock,reorder_qty,lead_time_days,warehouse_id',
                'supplier:id,name',
                'purchaseOrder:id,code,status',
                'decidedBy:id,name',
            ])
            ->recentFirst();

        return $this->applyFilters($query, $filters);
    }

    /**
     * The same register read as a history: everything that already has an
     * answer on it, newest decision first.
     *
     * @param  array{q?: string|null, status?: string|null, warehouse_id?: int|null}  $filters
     */
    public function history(array $filters = []): Builder
    {
        $query = $this->suggestions($filters)
            ->whereIn('status', [
                ReorderSuggestion::STATUS_ACCEPTED,
                ReorderSuggestion::STATUS_DISMISSED,
                ReorderSuggestion::STATUS_SUPERSEDED,
            ])
            ->orderByDesc('decided_at')
            ->orderByDesc('id');

        return $query;
    }

    /** @param array{q?: string|null, status?: string|null, warehouse_id?: int|null} $filters */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['status'] ?? '');
        $warehouseId = $filters['warehouse_id'] ?? null;

        return $query
            ->when($status !== '' && array_key_exists($status, ReorderSuggestion::STATUSES), fn ($q) => $q->where('status', $status))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($search !== '', fn ($q) => $q->whereHas('product', function ($p) use ($search) {
                $p->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            }));
    }

    /**
     * Headline counts for the desk, plus what is actually missing from the
     * shelves so the two can be read together.
     *
     * @return array{open: int, accepted: int, dismissed: int, superseded: int, value: float, needs_order: int}
     */
    public function stats(): array
    {
        $companyId = $this->companyId();

        $counts = ReorderSuggestion::query()
            ->where('company_id', $companyId)
            ->selectRaw('status, count(*) as rows')
            ->groupBy('status')
            ->pluck('rows', 'status')
            ->all();

        $units = 0.0;

        foreach (ReorderSuggestion::query()->where('company_id', $companyId)->open()->get() as $suggestion) {
            $units += $suggestion->effectiveQty();
        }

        return [
            'open' => (int) ($counts[ReorderSuggestion::STATUS_SUGGESTED] ?? 0),
            'accepted' => (int) ($counts[ReorderSuggestion::STATUS_ACCEPTED] ?? 0),
            'dismissed' => (int) ($counts[ReorderSuggestion::STATUS_DISMISSED] ?? 0),
            'superseded' => (int) ($counts[ReorderSuggestion::STATUS_SUPERSEDED] ?? 0),
            // Units across the proposals nobody has answered yet — the size of
            // the job, not money: the price is decided on the purchase order.
            'units' => round($units, 4),
        ];
    }

    /**
     * One row per shelf that wants buying, in shortage order. This is the list
     * the desk renders *before* anything is written down: a look that changes
     * nothing costs nothing.
     *
     * @return array{rows: array<int, array<string, mixed>>, counts: array{short: int, covered: int, clear: int}}
     */
    public function candidates(?int $warehouseId = null, ?string $search = null): array
    {
        $companyId = $this->companyId();
        $index = $this->reorder->policyIndex();
        $windowDays = $this->windowDays();

        $balances = StockBalance::query()
            ->where('company_id', $companyId)
            ->with(['product:id,sku,name', 'warehouse:id,name,code'])
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($search !== null && $search !== '', fn ($q) => $q->whereHas('product', function ($p) use ($search) {
                $p->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            }))
            ->get();

        $demand = $this->reorder->demandIndex(
            $balances->map(fn (StockBalance $balance) => [(int) $balance->product_id, $balance->warehouse_id])->all(),
            $windowDays,
        );

        $incoming = $this->incomingIndex($balances->map(fn (StockBalance $balance) => [
            (int) $balance->product_id,
            (int) $balance->warehouse_id,
        ])->all());

        $rows = [];
        // Three different answers, kept apart: a shelf below the line that needs
        // buying, one below the line that is already on order, and one that is
        // simply fine. Folding any two of them together is how a desk ends up
        // claiming work it has already done.
        $counts = ['short' => 0, 'covered' => 0, 'clear' => 0];

        foreach ($balances as $balance) {
            $product = $balance->product;

            if ($product === null) {
                continue;
            }

            $policy = $this->reorder->policyFor((int) $product->id, $balance->warehouse_id, $index);

            if ($policy === null || ! $policy->is_active) {
                continue; // no line drawn, no claim — same rule as the alerts desk
            }

            $key = $product->id.':'.($balance->warehouse_id ?? 0);
            $figures = $this->figures($balance, $policy, $demand[$key] ?? null, (float) ($incoming[$key] ?? 0));

            if ($figures['shortage'] <= 0) {
                $counts['clear']++;

                continue;
            }

            // Below the line, but somebody has already ordered enough to fix it:
            // still shown, so the desk never has to guess why a gap produces no
            // proposal — but it is not work waiting for a buyer.
            if ($figures['suggested_qty'] <= 0) {
                $counts['covered']++;
            } else {
                $counts['short']++;
            }

            $rows[] = $figures + [
                'product' => $product,
                'warehouse' => $balance->warehouse,
                'policy' => $policy,
                'scope' => $policy->warehouse_id === null ? 'company-wide rule' : 'this warehouse',
                'covered_by_order' => $figures['suggested_qty'] <= 0,
            ];
        }

        usort($rows, fn ($a, $b) => ($b['shortage'] <=> $a['shortage'])
            ?: strcmp((string) $a['product']->sku, (string) $b['product']->sku));

        return ['rows' => $rows, 'counts' => $counts];
    }

    /* ---------------------------------------------------------------- writes --- */

    /**
     * Write the current look down. One open proposal per product per warehouse:
     * looking again supersedes yesterday's figure rather than stacking a second
     * row for the same shelf, and a superseded row keeps its numbers as they
     * were — that is the history §04-58 reads.
     *
     * @param  array<int, array{product: Product, warehouse: Warehouse, policy: ReorderPolicy, suggested_qty: float, inputs: array<string, mixed>, shortage: float}>  $rows
     * @return array{created: int, superseded: int, skipped: int}
     */
    public function record(array $rows, User $actor): array
    {
        if ($rows === []) {
            throw new RuntimeException('Nothing is short of its trigger, so there is nothing to propose.');
        }

        $companyId = $this->companyId();
        $created = 0;
        $superseded = 0;
        $skipped = 0;

        return DB::transaction(function () use ($rows, $actor, $companyId, &$created, &$superseded, &$skipped) {
            foreach ($rows as $row) {
                // Nothing to buy (already covered by what is on order): a row
                // saying "order 0" would be paperwork, not a decision.
                if ((float) $row['suggested_qty'] <= 0) {
                    $skipped++;

                    continue;
                }

                $open = ReorderSuggestion::query()
                    ->where('company_id', $companyId)
                    ->where('product_id', $row['product']->id)
                    ->where('warehouse_id', $row['warehouse']->id)
                    ->open()
                    ->get();

                foreach ($open as $existing) {
                    $existing->forceFill([
                        'status' => ReorderSuggestion::STATUS_SUPERSEDED,
                        'decision_note' => 'A later look proposed a new figure for the same shelf.',
                        'decided_at' => now(),
                    ])->save();

                    $superseded++;
                }

                $suggestion = ReorderSuggestion::create([
                    'company_id' => $companyId,
                    'branch_id' => $row['warehouse']->branch_id,
                    'warehouse_id' => $row['warehouse']->id,
                    'product_id' => $row['product']->id,
                    'policy_id' => $row['policy']->id,
                    'code' => $this->nextCode($actor),
                    'status' => ReorderSuggestion::STATUS_SUGGESTED,
                    'run_date' => now()->toDateString(),
                    'demand_window_days' => $this->windowDays(),
                    'avg_daily_demand' => $row['inputs']['avg_daily_demand'],
                    'on_hand' => $row['inputs']['on_hand'],
                    'reserved' => $row['inputs']['reserved'],
                    'available' => $row['inputs']['available'],
                    'in_transit' => $row['inputs']['in_transit'],
                    'trigger_qty' => $row['inputs']['trigger_qty'],
                    'shortage' => $row['shortage'],
                    'suggested_qty' => $row['suggested_qty'],
                    'inputs' => $row['inputs'],
                    'created_by' => $actor->id,
                ]);

                $created++;

                $this->audit->record([
                    'action' => 'inventory.reorder_suggested',
                    'entity_type' => 'reorder_suggestion',
                    'entity_id' => $suggestion->id,
                    'branch_id' => $suggestion->branch_id,
                    'actor_id' => $actor->id,
                    'after' => [
                        'code' => $suggestion->code,
                        'sku' => $row['product']->sku,
                        'warehouse_id' => $row['warehouse']->id,
                        'suggested_qty' => (float) $suggestion->suggested_qty,
                        'avg_daily_demand' => (float) $suggestion->avg_daily_demand,
                        'window_days' => $suggestion->demand_window_days,
                    ],
                ]);
            }

            return ['created' => $created, 'superseded' => $superseded, 'skipped' => $skipped];
        });
    }

    /**
     * Turn a proposal into a **draft** purchase order. The purchase module's own
     * service writes the order, its number and its audit — this desk only names
     * the supplier, carries the quantity, and links the two rows so the order
     * can always be traced back to the figures that asked for it.
     */
    public function accept(ReorderSuggestion $suggestion, Supplier $supplier, ?float $quantity, User $actor): PurchaseOrder
    {
        if (! $suggestion->isOpen()) {
            throw new RuntimeException("Suggestion {$suggestion->code} already has an answer on it.");
        }

        $supplier->assertOrderable();

        if ($this->settings->getBool('reorder', 'strict_auto_mode', false)) {
            throw new RuntimeException('Strict auto mode is on: this instance asks a person to review the proposal before a purchase order is drafted, so the draft was refused.');
        }

        $product = Product::query()
            ->where('company_id', $suggestion->company_id)
            ->findOrFail($suggestion->product_id);

        $qty = round($quantity ?? $suggestion->effectiveQty(), 4);

        if ($qty <= 0) {
            throw new RuntimeException('A purchase order needs a quantity greater than zero.');
        }

        // The desk reads the whole company, but an order belongs to one branch:
        // it carries that branch, and its approval ladder is that branch's. So a
        // shelf on somebody else's branch is refused with a sentence rather than
        // explored into a 404 from deep inside the purchase module.
        $warehouse = Warehouse::withoutGlobalScope(BranchScope::class)
            ->where('company_id', $suggestion->company_id)
            ->whereKey($suggestion->warehouse_id)
            ->first();

        if ($warehouse === null) {
            throw new RuntimeException('That shelf no longer exists, so nothing can be ordered for it.');
        }

        if ($this->context->hasBranch()
            && $warehouse->branch_id !== null
            && (int) $warehouse->branch_id !== (int) $this->context->branchId()) {
            throw new RuntimeException(sprintf(
                '%s belongs to another branch, so its purchase order has to be raised by somebody working there.',
                $warehouse->name,
            ));
        }

        $order = $this->orders->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $suggestion->warehouse_id,
            'branch_id' => $suggestion->branch_id,
            'order_date' => now()->toDateString(),
            'expected_date' => $this->expectedDate($suggestion),
            'notes' => sprintf(
                'Drafted from reorder suggestion %s: %s average demand over %d days, %s available, %s on order.',
                $suggestion->code,
                number_format($suggestion->avgDaily(), 4),
                $suggestion->demand_window_days,
                number_format((float) $suggestion->available, 4),
                number_format((float) $suggestion->in_transit, 4),
            ),
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'qty_ordered' => $qty,
                'unit_price' => $this->lastCost($suggestion, $product->id),
            ]],
        ], $actor->id);

        $suggestion->forceFill([
            'status' => ReorderSuggestion::STATUS_ACCEPTED,
            'final_qty' => $quantity !== null ? $qty : null,
            'supplier_id' => $supplier->id,
            'purchase_order_id' => $order->id,
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_note' => 'Drafted as purchase order '.$order->code.'.',
        ])->save();

        $this->audit->record([
            'action' => 'inventory.reorder_suggestion_accepted',
            'entity_type' => 'reorder_suggestion',
            'entity_id' => $suggestion->id,
            'branch_id' => $suggestion->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'code' => $suggestion->code,
                'qty' => $qty,
                'supplier' => $supplier->name,
                'purchase_order' => $order->code,
                'purchase_order_id' => $order->id,
            ],
        ]);

        return $order;
    }

    /**
     * Say no to a proposal, with a reason. A dismissed row is not deleted: the
     * next person wondering why nothing was bought should find the answer.
     */
    public function dismiss(ReorderSuggestion $suggestion, ?string $note, User $actor): ReorderSuggestion
    {
        if (! $suggestion->isOpen()) {
            throw new RuntimeException("Suggestion {$suggestion->code} already has an answer on it.");
        }

        $suggestion->forceFill([
            'status' => ReorderSuggestion::STATUS_DISMISSED,
            'decision_note' => $note,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ])->save();

        $this->audit->record([
            'action' => 'inventory.reorder_suggestion_dismissed',
            'entity_type' => 'reorder_suggestion',
            'entity_id' => $suggestion->id,
            'branch_id' => $suggestion->branch_id,
            'actor_id' => $actor->id,
            'reason' => $note,
            'after' => [
                'code' => $suggestion->code,
                'sku' => $suggestion->product?->sku,
                'suggested_qty' => (float) $suggestion->suggested_qty,
            ],
        ]);

        return $suggestion;
    }

    /* -------------------------------------------------------------- internals --- */

    protected function companyId(): int
    {
        return $this->context->companyId() ?? abort(500, 'No company context for reorder suggestions.');
    }

    public function windowDays(): int
    {
        return max(7, min(365, $this->settings->getInt('reorder', 'demand_window_days', 30)));
    }

    /**
     * The figures one shelf is judged by, all of them stored so the row explains
     * itself: what the ledger holds, what the policy asks, what a day's demand
     * looks like, what is already on its way, and the quantity that follows.
     *
     * @param  array{avg_daily: float, out_qty: float, days: int, last_out: ?string}|null  $demand
     * @return array<string, mixed>
     */
    protected function figures(StockBalance $balance, ReorderPolicy $policy, ?array $demand, float $incoming): array
    {
        $onHand = round((float) $balance->on_hand, 4);
        $reserved = round((float) $balance->reserved, 4);
        $available = round($onHand - $reserved, 4);
        $avgDaily = round((float) ($demand['avg_daily'] ?? 0), 4);

        // The policy's own line. A reorder point is the number somebody picked
        // for "order now", so when it is set it is the trigger; the minimum is
        // the fallback for policies that only ever set a floor.
        $trigger = (float) $policy->reorder_point > 0
            ? (float) $policy->reorder_point
            : (float) $policy->min_level;

        $shortage = round(max(0, $trigger - $available), 4);

        $safety = (float) $policy->safety_stock;
        $maxLevel = (float) $policy->max_level;
        $policyQty = (float) $policy->reorder_qty;

        // Cover the lead time only if this instance says demand should lead the
        // policy. With the switch off the proposal falls back to the numbers a
        // person typed on the policy itself.
        $coverDays = $this->settings->getBool('reorder', 'suggest_lead_time', true) ? (int) $policy->lead_time_days : 0;
        $demandQty = $avgDaily > 0 ? round($avgDaily * $coverDays, 4) : 0.0;

        // A day's demand is evidence only when something actually moved. When it
        // did, buy enough that the safety stock is still on the shelf when the
        // order lands — what sold during the lead time is already gone.
        $demandLed = $avgDaily > 0 ? round($demandQty + $safety - $available, 4) : 0.0;

        // The policy's own answer: the quantity somebody typed, else simply the
        // distance back to the trigger.
        $policyLed = $policyQty > 0 ? $policyQty : $shortage;

        $suggested = max($policyLed, max(0, $demandLed));

        if ($maxLevel > 0) {
            $suggested = min($suggested, max(0, $maxLevel - $available));
        }

        // What is already on order is not bought twice.
        $suggested = round(max(0, $suggested - $incoming), 4);

        return [
            'avg_daily_demand' => $avgDaily,
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => $available,
            'in_transit' => round($incoming, 4),
            'trigger_qty' => $trigger,
            'shortage' => $shortage,
            'suggested_qty' => $suggested,
            'demand_qty' => round($demandQty, 4),
            'cover_days' => $avgDaily > 0 ? round($available / $avgDaily, 1) : null,
            'last_out' => $demand['last_out'] ?? null,
            'inputs' => [
                'formula' => 'max(policy quantity, demand over the lead time + safety) − already on order, capped at the maximum',
                'avg_daily_demand' => $avgDaily,
                'window_days' => $this->windowDays(),
                'out_qty' => round((float) ($demand['out_qty'] ?? 0), 4),
                'last_out' => $demand['last_out'] ?? null,
                'lead_time_days' => (int) $policy->lead_time_days,
                'demand_qty' => round($demandQty, 4),
                'safety_stock' => round($safety, 4),
                'policy_qty' => round($policyQty, 4),
                'trigger_qty' => $trigger,
                'available' => $available,
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'in_transit' => round($incoming, 4),
                'max_level' => round($maxLevel, 4),
                'policy_scope' => $policy->warehouse_id === null ? 'company-wide' : 'warehouse',
            ],
        ];
    }

    /**
     * What is already on its way, per product per warehouse: the undelivered
     * quantity of every open purchase order. The same gap is never bought twice
     * — a proposal is reduced by what somebody has already ordered, and shows
     * that figure so the reduction can be argued with.
     *
     * @param  array<int, array{0: int, 1: int|null}>  $pairs
     * @return array<string, float>
     */
    public function incomingIndex(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_map(fn ($pair) => (int) $pair[0], $pairs)));

        $rows = DB::table('purchase_order_lines as l')
            ->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->where('o.company_id', $this->companyId())
            ->whereIn('o.status', ['draft', 'pending_approval', 'approved', 'partially_received'])
            ->whereIn('l.product_id', $productIds)
            ->whereRaw('l.qty_ordered > l.qty_received')
            ->groupBy('l.product_id', 'o.warehouse_id')
            ->selectRaw('l.product_id as product_id, o.warehouse_id as warehouse_id, sum(l.qty_ordered - l.qty_received) as qty')
            ->get();

        $index = [];

        foreach ($rows as $row) {
            $index[$row->product_id.':'.($row->warehouse_id ?? 0)] = round((float) $row->qty, 4);
        }

        return $index;
    }

    /** Expected delivery, from the policy's own lead time — a promise nobody typed. */
    protected function expectedDate(ReorderSuggestion $suggestion): ?string
    {
        $lead = (int) ($suggestion->policy?->lead_time_days ?? 0);

        return $lead > 0 ? now()->addDays($lead)->toDateString() : null;
    }

    /** The last price paid for this product, or its standard cost when it has never been bought. */
    protected function lastCost(ReorderSuggestion $suggestion, int $productId): float
    {
        $last = DB::table('purchase_order_lines as l')
            ->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->where('o.company_id', $suggestion->company_id)
            ->where('l.product_id', $productId)
            ->where('l.unit_price', '>', 0)
            ->orderByDesc('o.order_date')
            ->orderByDesc('l.id')
            ->value('l.unit_price');

        if ($last !== null) {
            return round((float) $last, 4);
        }

        return round((float) Product::query()->whereKey($productId)->value('standard_cost'), 4);
    }

    protected function nextCode(User $actor): string
    {
        $type = DocumentType::query()->where('code', 'reorder_suggestion')->first();

        if ($type === null) {
            throw new RuntimeException('Document type [reorder_suggestion] is not seeded, so suggestions cannot be numbered.');
        }

        return $this->numbering->allocate($type->id, $actor->default_branch_id ?? 0);
    }
}
