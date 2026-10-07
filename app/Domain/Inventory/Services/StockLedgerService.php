<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Foundation\Concerns\BranchScope;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE sole code path that creates stock_movements (§8).
 *
 * Every mutation:
 *  1. locks the balance row (SELECT FOR UPDATE);
 *  2. negative-stock guard (on_hand cannot go below 0);
 *  3. valuation layer consume/create via ValuationService;
 *  4. insert immutable movement (source, actor, idempotency key);
 *  5. update derived stock_balances by state.
 *
 * stock_balances is a cache — rebuildable from stock_movements.
 */
class StockLedgerService
{
    public function __construct(
        protected TenantContext $context,
        protected ValuationService $valuation,
    ) {}

    /**
     * Post one stock movement.
     *
     * @param array{
     *   product_id: int,
     *   warehouse_id: int,
     *   movement_type: string,
     *   qty: float,
     *   unit_cost?: float|null,
     *   state?: string,
     *   branch_id?: int|null,
     *   source_type?: string|null,
     *   source_id?: int|null,
     *   source_event?: string|null,
     *   idempotency_key?: string|null,
     *   narration?: string|null,
     *   occurred_at?: string|null,
     * } $command
     */
    public function post(array $command, ?User $actor = null): StockMovement
    {
        $companyId = $this->context->companyId()
            ?? $actor?->company_id
            ?? abort(500, 'No company context for stock posting.');

        $product = Product::query()
            ->where('company_id', $companyId)
            ->whereKey($command['product_id'] ?? 0)
            ->firstOrFail();

        if (! $product->is_stocked) {
            throw new RuntimeException(sprintf('Product %s is not stock-managed.', $product->sku));
        }

        if (! $product->is_active) {
            throw new RuntimeException(sprintf('Product %s is inactive.', $product->sku));
        }

        // Company-wide: transfers post to warehouses on other branches.
        $warehouse = Warehouse::withoutGlobalScope(BranchScope::class)
            ->where('company_id', $companyId)
            ->whereKey($command['warehouse_id'] ?? 0)
            ->firstOrFail();

        $qty = (float) ($command['qty'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('Stock quantity must be greater than zero.');
        }

        $type = (string) ($command['movement_type'] ?? '');
        $state = (string) ($command['state'] ?? $this->defaultState($type));
        $isInbound = $this->isInbound($type);

        if (! in_array($state, [
            StockMovement::STATE_ON_HAND,
            StockMovement::STATE_IN_TRANSIT,
            StockMovement::STATE_DAMAGED,
            StockMovement::STATE_QUARANTINED,
            StockMovement::STATE_RESERVED,
        ], true)) {
            throw new RuntimeException("Invalid stock state: {$state}");
        }

        $idempotencyKey = $command['idempotency_key'] ?? null;
        if ($idempotencyKey !== null) {
            $existing = StockMovement::query()
                ->where('company_id', $companyId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use (
            $command, $product, $warehouse, $qty, $type, $state,
            $isInbound, $companyId, $actor, $idempotencyKey
        ) {
            // Warehouse branch wins so cross-branch receives land on the right branch.
            $branchId = $command['branch_id']
                ?? $warehouse->branch_id
                ?? $this->context->branchId();

            $balance = StockBalance::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                $created = StockBalance::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'on_hand' => 0,
                    'reserved' => 0,
                    'in_transit' => 0,
                    'damaged' => 0,
                    'quarantined' => 0,
                ]);
                $balance = StockBalance::query()->whereKey($created->id)->lockForUpdate()->firstOrFail();
            }

            $unitCost = '0.0000';
            $layerId = null;
            $valuationMethod = $product->cost_method;
            $movesValue = ! in_array($type, StockMovement::COMPARTMENT_MOVE_TYPES, true);

            if ($type === StockMovement::TYPE_TRANSIT_CLEAR) {
                // Layers already consumed on TRANSIT_OUT; only close origin in_transit.
                $unitCost = number_format((float) ($command['unit_cost'] ?? 0), 4, '.', '');
            } elseif (! $movesValue) {
                // on_hand ⇄ damaged: the goods stay ours at the same cost, so no
                // layer is created or consumed. The value is reported from the
                // layers all the same, which is why stock value does not move.
                $unitCost = number_format((float) ($command['unit_cost'] ?? 0), 4, '.', '');
            } elseif ($isInbound) {
                [$unitCost, $layer] = $this->valuation->receive(
                    $product,
                    $warehouse,
                    $qty,
                    $command['unit_cost'] ?? null,
                    $command['source_type'] ?? null,
                    $command['source_id'] ?? null,
                );
                $layerId = $layer?->id;
            } else {
                // Outbound always consumes valuation layers (cost of removal)
                [, $unitCost] = $this->valuation->consume(
                    $product,
                    $warehouse,
                    $qty,
                    $command['source_type'] ?? null,
                    $command['source_id'] ?? null,
                );
            }

            $this->assertNonNegative($balance, $state, $qty, $isInbound, $product);

            if ($type === StockMovement::TYPE_TRANSIT_CLEAR) {
                $projectedTransit = (float) $balance->in_transit - $qty;
                if ($projectedTransit < -1e-9) {
                    throw new RuntimeException(sprintf(
                        'In-transit underflow for %s: have %s, clearing %s.',
                        $product->sku,
                        $balance->in_transit,
                        $qty,
                    ));
                }
            }

            // Compartment moves get the same loud refusal as sellable stock: a
            // silent floor would hide a real inconsistency in the balance row.
            if ($type === StockMovement::TYPE_DAMAGE_IN && (float) $balance->on_hand - $qty < -1e-9) {
                throw new RuntimeException(sprintf(
                    'Not enough sellable stock to flag as damaged for %s: on hand %s, flagging %s.',
                    $product->sku,
                    $balance->on_hand,
                    $qty,
                ));
            }

            if ($type === StockMovement::TYPE_DAMAGE_RELEASE && (float) $balance->damaged - $qty < -1e-9) {
                throw new RuntimeException(sprintf(
                    'Damaged-stock underflow for %s: held %s, releasing %s.',
                    $product->sku,
                    $balance->damaged,
                    $qty,
                ));
            }

            if ($type === StockMovement::TYPE_DAMAGE_OUT && (float) $balance->damaged - $qty < -1e-9) {
                throw new RuntimeException(sprintf(
                    'Damaged-stock underflow for %s: held %s, writing off %s.',
                    $product->sku,
                    $balance->damaged,
                    $qty,
                ));
            }

            $signedQty = $isInbound ? $qty : -$qty;

            $this->applyBalanceDelta($balance, $type, $state, $qty, $isInbound);

            $balance->branch_id = $branchId;
            $balance->computed_at = now();
            $balance->save();

            return StockMovement::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'movement_type' => $type,
                'state' => $state,
                'qty_signed' => number_format($signedQty, 4, '.', ''),
                'unit_cost' => $unitCost,
                'valuation_method' => $valuationMethod,
                'layer_id' => $layerId,
                'source_type' => $command['source_type'] ?? null,
                'source_id' => $command['source_id'] ?? null,
                'source_event' => $command['source_event'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $command['occurred_at'] ?? now(),
                'actor_id' => $actor?->id ?? $this->context->user()?->id,
                'narration' => $command['narration'] ?? null,
            ]);
        });
    }

    /**
     * Rebuild stock_balances by replaying the immutable ledger in order.
     * Live path and rebuild share applyBalanceDelta — they cannot drift.
     */
    public function rebuildBalances(int $companyId): int
    {
        return DB::transaction(function () use ($companyId) {
            StockBalance::query()->where('company_id', $companyId)->delete();

            $balances = [];

            StockMovement::query()
                ->where('company_id', $companyId)
                ->orderBy('id')
                ->chunkById(500, function ($movements) use (&$balances, $companyId) {
                    foreach ($movements as $movement) {
                        $key = $movement->warehouse_id.':'.$movement->product_id;

                        if (! isset($balances[$key])) {
                            $balances[$key] = [
                                'company_id' => $companyId,
                                'branch_id' => $movement->branch_id,
                                'warehouse_id' => $movement->warehouse_id,
                                'product_id' => $movement->product_id,
                                'on_hand' => 0.0,
                                'reserved' => 0.0,
                                'in_transit' => 0.0,
                                'damaged' => 0.0,
                                'quarantined' => 0.0,
                            ];
                        }

                        $row = $balances[$key];
                        $qty = abs((float) $movement->qty_signed);
                        $isInbound = $movement->isInbound();

                        $this->applyBalanceDeltaFromArray(
                            $row,
                            $movement->movement_type,
                            $movement->state,
                            $qty,
                            $isInbound,
                        );

                        $row['branch_id'] = $movement->branch_id ?? $row['branch_id'];
                        $balances[$key] = $row;
                    }
                });

            foreach ($balances as $row) {
                StockBalance::create($row + ['computed_at' => now()]);
            }

            return count($balances);
        });
    }

    /** Apply one movement's effect onto a live StockBalance model. */
    protected function applyBalanceDelta(
        StockBalance $balance,
        string $type,
        string $state,
        float $qty,
        bool $isInbound,
    ): void {
        $arr = [
            'on_hand' => (float) $balance->on_hand,
            'reserved' => (float) $balance->reserved,
            'in_transit' => (float) $balance->in_transit,
            'damaged' => (float) $balance->damaged,
            'quarantined' => (float) $balance->quarantined,
        ];

        StockMovement::applyDelta($arr, $type, $state, $qty, $isInbound);

        $balance->on_hand = number_format($arr['on_hand'], 4, '.', '');
        $balance->reserved = number_format($arr['reserved'], 4, '.', '');
        $balance->in_transit = number_format($arr['in_transit'], 4, '.', '');
        $balance->damaged = number_format($arr['damaged'], 4, '.', '');
        $balance->quarantined = number_format($arr['quarantined'], 4, '.', '');
    }

    /**
     * Shared state machine for live post and ledger rebuild — the definition
     * lives on the movement model so the ledger view cannot drift from it.
     *
     * @param  array<string, float>  $row
     */
    protected function applyBalanceDeltaFromArray(
        array &$row,
        string $type,
        string $state,
        float $qty,
        bool $isInbound,
    ): void {
        StockMovement::applyDelta($row, $type, $state, $qty, $isInbound);
    }

    protected function isInbound(string $type): bool
    {
        // The direction vocabulary lives on the model (StockMovement::INBOUND_TYPES)
        // so a new movement type cannot be added inbound in one place and read as
        // outbound here — which is exactly what happened to PURCHASE_RECEIPT.
        return in_array($type, StockMovement::INBOUND_TYPES, true);
    }

    protected function defaultState(string $type): string
    {
        return match ($type) {
            StockMovement::TYPE_TRANSIT_OUT,
            StockMovement::TYPE_TRANSIT_IN,
            StockMovement::TYPE_TRANSIT_CLEAR => StockMovement::STATE_IN_TRANSIT,
            default => StockMovement::STATE_ON_HAND,
        };
    }

    protected function assertNonNegative(
        StockBalance $balance,
        string $state,
        float $qty,
        bool $isInbound,
        Product $product,
    ): void {
        if ($isInbound || $state !== StockMovement::STATE_ON_HAND) {
            return;
        }

        $projected = (float) $balance->on_hand - $qty;
        if ($projected < -1e-9) {
            throw new RuntimeException(sprintf(
                'Negative stock blocked for %s: on_hand %s cannot cover %s.',
                $product->sku,
                $balance->on_hand,
                $qty,
            ));
        }
    }
}
