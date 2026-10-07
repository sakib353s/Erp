<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\PostStockAdjustment;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockCount;
use App\Domain\Inventory\StockCountLine;
use App\Domain\Inventory\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock count / cycle count (§04-31).
 *
 * A count is how the system finds out it was wrong. Three rules make it worth
 * anything:
 *
 *  · the sheet is a SNAPSHOT. Every line freezes the balance the ledger showed
 *    when the sheet was opened, because a sheet that followed the warehouse
 *    would always agree with itself.
 *  · the count is AUTHORITATIVE for the lines that were actually counted.
 *    Posting applies the difference between what is on the shelf *now* and what
 *    the counter found, not the difference against the snapshot — otherwise
 *    stock that moved during the count would be left in a state nobody counted.
 *    Both numbers are kept so the movement during the count stays visible.
 *  · posting goes through the adjustment path (`PostStockAdjustment`), so the
 *    ledger has one inbound/outbound write path and the variance is auditable
 *    as an adjustment document with a reason, not as a silent correction.
 *
 * A line nobody counted is not a zero: it is left out of the adjustment and
 * stays visible as uncounted.
 */
class StockCountService
{
    public function __construct(
        protected PostStockAdjustment $adjustments,
        protected ValuationService $valuation,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /* ------------------------------------------------------------- opening ---- */

    /**
     * Open a sheet and freeze the numbers it will ask about.
     *
     * @param  array{
     *   warehouse_id: int,
     *   count_date?: string|null,
     *   scope?: string|null,
     *   notes?: string|null,
     *   products?: array<int, int|string>|null,
     * } $payload
     */
    public function open(array $payload, User $actor): StockCount
    {
        $scope = (string) ($payload['scope'] ?? StockCount::SCOPE_FULL);

        if (! array_key_exists($scope, StockCount::SCOPES)) {
            throw new RuntimeException("Unknown count scope [{$scope}].");
        }

        $companyId = $this->companyId($actor);
        $warehouse = $this->warehouse($payload['warehouse_id'] ?? 0, $companyId);
        $countDate = (string) ($payload['count_date'] ?? now()->toDateString());

        $products = $this->productsForScope($scope, $payload['products'] ?? [], $companyId);

        if ($products->isEmpty()) {
            throw new RuntimeException(
                $scope === StockCount::SCOPE_CYCLE
                    ? 'A cycle count needs at least one product to count.'
                    : 'There is no stocked product to count — add products first.'
            );
        }

        return DB::transaction(function () use ($scope, $warehouse, $countDate, $payload, $products, $actor, $companyId) {
            $count = StockCount::create([
                'company_id' => $companyId,
                'branch_id' => $warehouse->branch_id ?? $actor->default_branch_id,
                'warehouse_id' => $warehouse->id,
                'code' => $this->nextCode($actor),
                'scope' => $scope,
                'count_date' => $countDate,
                'status' => StockCount::STATUS_COUNTING,
                'notes' => $payload['notes'] ?? null,
                'line_count' => $products->count(),
                'created_by' => $actor->id,
            ]);

            // One query for the balances the ledger shows right now; anything
            // without a row is genuinely zero in this warehouse.
            $balances = StockBalance::query()
                ->where('company_id', $companyId)
                ->where('warehouse_id', $warehouse->id)
                ->whereIn('product_id', $products->pluck('id'))
                ->pluck('on_hand', 'product_id');

            $lineNo = 0;

            foreach ($products as $product) {
                $lineNo++;
                $systemQty = round((float) ($balances[$product->id] ?? 0), 4);

                StockCountLine::create([
                    'stock_count_id' => $count->id,
                    'product_id' => $product->id,
                    'line_no' => $lineNo,
                    'system_qty' => number_format($systemQty, 4, '.', ''),
                    'counted_qty' => null,
                    'variance' => 0,
                ]);
            }

            $this->audit->record([
                'action' => 'inventory.count_opened',
                'entity_type' => 'stock_count',
                'entity_id' => $count->id,
                'branch_id' => $count->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'code' => $count->code,
                    'scope' => $scope,
                    'warehouse_id' => $warehouse->id,
                    'lines' => $lineNo,
                ],
            ]);

            return $count->refresh()->load('lines.product', 'warehouse');
        });
    }

    /* ------------------------------------------------------------ counting ---- */

    /**
     * Save what the counter wrote down.
     *
     * @param  array<int|string, float|int|string|null>  $counts  product_id => counted qty
     */
    public function recordCounts(StockCount $count, array $counts, User $actor): StockCount
    {
        $this->assertOpen($count);

        return DB::transaction(function () use ($count, $counts, $actor) {
            $counted = 0;
            $variances = 0;
            $touched = 0;

            foreach ($count->lines()->with('product')->get() as $line) {
                if (! array_key_exists($line->product_id, $counts) && ! array_key_exists((string) $line->product_id, $counts)) {
                    continue;
                }

                $raw = $counts[$line->product_id] ?? $counts[(string) $line->product_id];
                $touched++;

                if ($raw === null || $raw === '') {
                    // Cleared back to "not counted" rather than counted as zero.
                    $line->forceFill(['counted_qty' => null, 'variance' => 0])->save();

                    continue;
                }

                if (! is_numeric($raw)) {
                    throw new RuntimeException(sprintf(
                        'The counted quantity for %s must be a number.',
                        $line->product?->sku ?? ('product #'.$line->product_id),
                    ));
                }

                $qty = round((float) $raw, 4);

                if ($qty < 0) {
                    throw new RuntimeException(sprintf(
                        'A counted quantity cannot be negative (%s).',
                        $line->product?->sku ?? ('product #'.$line->product_id),
                    ));
                }

                $line->forceFill([
                    'counted_qty' => number_format($qty, 4, '.', ''),
                    'variance' => number_format($qty - (float) $line->system_qty, 4, '.', ''),
                ])->save();

                $counted++;

                if ($line->hasVariance()) {
                    $variances++;
                }
            }

            if ($touched === 0) {
                throw new RuntimeException('No counted quantities were submitted.');
            }

            $count->forceFill([
                'counted_lines' => $this->countedLines($count),
                'variance_lines' => $this->varianceLines($count),
            ])->save();

            $this->audit->record([
                'action' => 'inventory.count_recorded',
                'entity_type' => 'stock_count',
                'entity_id' => $count->id,
                'branch_id' => $count->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'code' => $count->code,
                    'lines_submitted' => $touched,
                    'counted' => $counted,
                    'with_variance' => $variances,
                ],
            ]);

            return $count->refresh()->load('lines.product', 'warehouse');
        });
    }

    /* ------------------------------------------------------------- posting ---- */

    /**
     * Apply the count: the counted figure becomes the truth for every line that
     * was actually counted. Returns the closed sheet.
     */
    public function post(StockCount $count, User $actor): StockCount
    {
        $this->assertOpen($count);

        $count->load('lines.product', 'warehouse');

        $counted = $count->lines->filter(fn (StockCountLine $line) => $line->isCounted());

        if ($counted->isEmpty()) {
            throw new RuntimeException('Nothing has been counted yet — there is no variance to post.');
        }

        return DB::transaction(function () use ($count, $counted, $actor) {
            // What the ledger says NOW, line by line. The delta is measured
            // against this, so the shelf ends at the counted figure even if the
            // warehouse moved while the sheet was open.
            $current = StockBalance::query()
                ->where('warehouse_id', $count->warehouse_id)
                ->whereIn('product_id', $counted->pluck('product_id'))
                ->pluck('on_hand', 'product_id');

            $payload = [];
            $deltas = [];

            foreach ($counted as $index => $line) {
                $delta = round((float) $line->counted_qty - (float) ($current[$line->product_id] ?? 0), 4);

                if (abs($delta) < 1e-9) {
                    continue; // counted and ledger agree — nothing to write
                }

                $deltas[$line->product_id] = $delta;

                $payload[] = [
                    'product_id' => $line->product_id,
                    'qty_delta' => $delta,
                    'unit_cost' => $delta > 0
                        ? $this->valuation->unitCost($line->product, $count->warehouse)
                        : null,
                    'narration' => sprintf(
                        '%s: counted %s, ledger said %s',
                        $count->code,
                        $this->qty($line->counted_qty),
                        $this->qty($line->system_qty),
                    ),
                ];
            }

            $adjustment = null;
            $unitCosts = [];

            if ($payload !== []) {
                $adjustment = $this->adjustments->post([
                    'warehouse_id' => $count->warehouse_id,
                    'adjustment_date' => $count->count_date?->toDateString() ?? now()->toDateString(),
                    'reason' => sprintf('%s — %s', $count->code, $this->reasonFor($count)),
                    'lines' => $payload,
                ], $actor);

                // The movement is the record of what the ledger actually did —
                // including the cost it consumed or took on. Read it back rather
                // than guessing from the layers afterwards.
                foreach (StockMovement::query()
                    ->where('source_type', 'stock_adjustment')
                    ->where('source_id', $adjustment->id)
                    ->get() as $movement) {
                    $unitCosts[$movement->product_id] = [
                        'unit_cost' => (float) $movement->unit_cost,
                        'qty' => (float) $movement->qty_signed,
                    ];
                }
            }

            $value = 0.0;
            $varianceLines = 0;
            $movedLines = 0;

            foreach ($count->lines as $line) {
                if (! $line->isCounted()) {
                    continue;
                }

                $posted = $deltas[$line->product_id] ?? 0.0;
                $movement = $unitCosts[$line->product_id] ?? null;
                $unitCost = (float) ($movement['unit_cost'] ?? 0);
                $lineValue = round($posted * $unitCost, 4);

                // Two different questions, two different numbers. `variance_lines`
                // is the finding — where the shelf disagreed with the sheet.
                // `moved_lines` is what the ledger then had to do about it, which
                // differs whenever stock moved while the count was open.
                if ($line->hasVariance()) {
                    $varianceLines++;
                }

                if (abs($posted) > 1e-9) {
                    $movedLines++;
                }

                $value += $lineValue;

                $line->forceFill([
                    'posted_delta' => number_format($posted, 4, '.', ''),
                    'unit_cost' => number_format($unitCost, 4, '.', ''),
                    'value' => number_format($lineValue, 4, '.', ''),
                ])->save();
            }

            $count->forceFill([
                'status' => StockCount::STATUS_POSTED,
                'counted_lines' => $counted->count(),
                'variance_lines' => $varianceLines,
                'variance_value' => number_format(round($value, 4), 4, '.', ''),
                'stock_adjustment_id' => $adjustment?->id,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            $this->audit->record([
                'action' => 'inventory.count_posted',
                'entity_type' => 'stock_count',
                'entity_id' => $count->id,
                'branch_id' => $count->branch_id,
                'actor_id' => $actor->id,
                'after' => [
                    'code' => $count->code,
                    'counted_lines' => $counted->count(),
                    'variance_lines' => $varianceLines,
                    'moved_lines' => $movedLines,
                    'variance_value' => round($value, 4),
                    'adjustment_no' => $adjustment?->adjustment_no,
                ],
            ]);

            return $count->refresh()->load('lines.product', 'warehouse', 'adjustment', 'poster');
        });
    }

    /** Abandon a sheet. A cancelled count corrects nothing, ever. */
    public function cancel(StockCount $count, ?string $note, User $actor): StockCount
    {
        $this->assertOpen($count);

        $note = trim((string) $note);

        $count->forceFill([
            'status' => StockCount::STATUS_CANCELLED,
            'cancel_note' => $note !== '' ? $note : null,
        ])->save();

        $this->audit->record([
            'action' => 'inventory.count_cancelled',
            'entity_type' => 'stock_count',
            'entity_id' => $count->id,
            'branch_id' => $count->branch_id,
            'actor_id' => $actor->id,
            'after' => ['code' => $count->code, 'note' => $note ?: null],
        ]);

        return $count->refresh();
    }

    /* ---------------------------------------------------------- registers ----- */

    /**
     * Count sheets, newest first.
     *
     * @param  array{status?: string|null, scope?: string|null, warehouse?: int|null, from?: string|null, to?: string|null}  $filters
     */
    public function sessions(array $filters = [], int $perPage = 15)
    {
        return StockCount::query()
            ->where('company_id', $this->context->companyId())
            ->with(['warehouse', 'creator', 'poster', 'adjustment'])
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['scope'] ?? null) !== null, fn ($q) => $q->where('scope', $filters['scope']))
            ->when(($filters['warehouse'] ?? null) !== null, fn ($q) => $q->where('warehouse_id', $filters['warehouse']))
            ->when(($filters['from'] ?? null) !== null, fn ($q) => $q->whereDate('count_date', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($q) => $q->whereDate('count_date', '<=', $filters['to']))
            ->orderByDesc('count_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** One sheet with everything the screen needs. */
    public function sheet(StockCount $count): StockCount
    {
        return $count->load(['lines.product', 'warehouse', 'creator', 'poster', 'adjustment']);
    }

    /**
     * What the count programme has found: sheets opened and posted, lines
     * counted, the value the ledger moved because of them, and how often the
     * warehouse agreed with the system at all.
     *
     * @return array{
     *   from: string, to: string,
     *   open: int, posted: int, cancelled: int,
     *   counted_lines: int, variance_lines: int, uncounted_lines: int,
     *   variance_value: int|float, accuracy: float|null,
     *   by_warehouse: \Illuminate\Support\Collection<int, array{warehouse: ?Warehouse, sessions: int, lines: int, value: float}>,
     *   recent: \Illuminate\Support\Collection<int, StockCount>,
     * }
     */
    public function analytics(?string $from = null, ?string $to = null, ?int $warehouseId = null): array
    {
        $from ??= now()->subMonths(11)->startOfMonth()->toDateString();
        $to ??= now()->toDateString();

        $inWindow = fn () => StockCount::query()
            ->where('company_id', $this->context->companyId())
            ->whereDate('count_date', '>=', $from)
            ->whereDate('count_date', '<=', $to)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId));

        $posted = (clone $inWindow())->where('status', StockCount::STATUS_POSTED)->get();

        $countedLines = (int) $posted->sum('counted_lines');
        $varianceLines = (int) $posted->sum('variance_lines');

        return [
            'from' => $from,
            'to' => $to,
            'open' => (int) (clone $inWindow())->open()->count(),
            'posted' => $posted->count(),
            'cancelled' => (int) (clone $inWindow())->where('status', StockCount::STATUS_CANCELLED)->count(),
            'counted_lines' => $countedLines,
            'variance_lines' => $varianceLines,
            'uncounted_lines' => (int) $posted->sum(fn (StockCount $c) => max(0, (int) $c->line_count - (int) $c->counted_lines)),
            'variance_value' => round((float) $posted->sum(fn (StockCount $c) => (float) $c->variance_value), 4),
            'accuracy' => $countedLines > 0 ? round(1 - ($varianceLines / $countedLines), 4) : null,
            'by_warehouse' => $posted
                ->groupBy('warehouse_id')
                ->map(fn ($rows) => [
                    'warehouse' => $rows->first()->warehouse,
                    'sessions' => $rows->count(),
                    'lines' => (int) $rows->sum('counted_lines'),
                    'value' => round((float) $rows->sum(fn (StockCount $c) => (float) $c->variance_value), 4),
                ])
                ->sortByDesc('lines')
                ->values(),
            'recent' => (clone $inWindow())->with('warehouse')->orderByDesc('count_date')->orderByDesc('id')->limit(5)->get(),
        ];
    }

    /* ------------------------------------------------------------ helpers ----- */

    protected function assertOpen(StockCount $count): void
    {
        if (! $count->isOpen()) {
            throw new RuntimeException(sprintf(
                'Count %s is %s — only a sheet that is still counting can change.',
                $count->code,
                $count->status,
            ));
        }
    }

    protected function reasonFor(StockCount $count): string
    {
        return $count->scope === StockCount::SCOPE_CYCLE
            ? 'cycle count variance'
            : 'full stock count variance';
    }

    protected function countedLines(StockCount $count): int
    {
        return $count->lines()->whereNotNull('counted_qty')->count();
    }

    protected function varianceLines(StockCount $count): int
    {
        return $count->lines()->whereNotNull('counted_qty')->where('variance', '!=', 0)->count();
    }

    protected function qty(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.') ?: '0';
    }

    /**
     * Which products the sheet asks about. A full count lists every stocked
     * product the company sells — the warehouse may hold something the ledger
     * thinks is at zero, which is exactly the finding a count exists for.
     *
     * @param  array<int, int|string>  $chosen
     * @return \Illuminate\Support\Collection<int, Product>
     */
    protected function productsForScope(string $scope, array $chosen, int $companyId)
    {
        $query = Product::query()
            ->where('company_id', $companyId)
            ->active()
            ->stocked();

        if ($scope === StockCount::SCOPE_CYCLE) {
            $ids = array_values(array_filter(array_map('intval', $chosen)));

            if ($ids === []) {
                throw new RuntimeException('A cycle count needs at least one product to count.');
            }

            return $query->whereIn('id', $ids)->orderBy('sku')->get();
        }

        return $query->orderBy('sku')->get();
    }

    protected function nextCode(User $actor): string
    {
        $type = DocumentType::query()->where('code', 'stock_count')->first();

        if ($type === null) {
            throw new RuntimeException('Document type [stock_count] is not seeded, so count sheets cannot be numbered.');
        }

        return $this->numbering->allocate($type->id, $actor->default_branch_id ?: null);
    }

    protected function warehouse(int $warehouseId, int $companyId): Warehouse
    {
        return Warehouse::query()
            ->where('company_id', $companyId)
            ->findOrFail($warehouseId);
    }

    protected function companyId(User $actor): int
    {
        return (int) ($this->context->companyId() ?? $actor->company_id);
    }
}
