<?php

namespace App\Domain\Sales\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Sales\StockReservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Order/POS stock holds. Reservations update stock_balances.reserved
 * (derived cache) under row lock; the immutable movement ledger is NOT
 * written until consume (issue) via StockLedgerService.
 */
class ReservationService
{
    public function __construct(protected AuditRecorder $audit) {}

    /**
     * @param  array<int, array{product_id: int, qty: float}>  $lines
     */
    public function reserve(
        int $companyId,
        int $warehouseId,
        string $sourceType,
        int $sourceId,
        array $lines,
        ?\DateTimeInterface $expiresAt = null,
    ): void {
        DB::transaction(function () use ($companyId, $warehouseId, $sourceType, $sourceId, $lines, $expiresAt) {
            foreach ($lines as $line) {
                $productId = (int) $line['product_id'];
                $qty = (float) $line['qty'];

                if ($qty <= 0) {
                    continue;
                }

                $balance = StockBalance::query()
                    ->where('company_id', $companyId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                $onHand = $balance !== null ? (float) $balance->on_hand : 0.0;
                $reserved = $balance !== null ? (float) $balance->reserved : 0.0;
                $available = $onHand - $reserved;

                if ($available + 1e-9 < $qty) {
                    $sku = Product::query()->whereKey($productId)->value('sku') ?? $productId;
                    throw new RuntimeException(sprintf(
                        'Insufficient available stock for %s: need %s, available %s.',
                        $sku,
                        $qty,
                        $available,
                    ));
                }

                // Upsert active reservation for this source+product
                $existing = StockReservation::query()
                    ->where('company_id', $companyId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('status', StockReservation::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $existing->qty = number_format((float) $existing->qty + $qty, 4, '.', '');
                    $existing->save();
                } else {
                    StockReservation::create([
                        'company_id' => $companyId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'qty' => number_format($qty, 4, '.', ''),
                        'status' => StockReservation::STATUS_ACTIVE,
                        'expires_at' => $expiresAt,
                    ]);
                }

                if ($balance === null) {
                    StockBalance::create([
                        'company_id' => $companyId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'on_hand' => 0,
                        'reserved' => number_format($qty, 4, '.', ''),
                        'computed_at' => now(),
                    ]);
                } else {
                    $balance->reserved = number_format($reserved + $qty, 4, '.', '');
                    $balance->computed_at = now();
                    $balance->save();
                }
            }
        });
    }

    public function release(int $companyId, string $sourceType, int $sourceId): void
    {
        DB::transaction(function () use ($companyId, $sourceType, $sourceId) {
            $reservations = StockReservation::query()
                ->where('company_id', $companyId)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', StockReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $this->decrementReserved($reservation);
                $reservation->status = StockReservation::STATUS_RELEASED;
                $reservation->save();
            }
        });
    }

    /**
     * Consume reservations (mark consumed) when stock is issued —
     * the actual on_hand decrement happens in StockLedgerService.
     */
    public function consume(int $companyId, string $sourceType, int $sourceId): void
    {
        DB::transaction(function () use ($companyId, $sourceType, $sourceId) {
            $reservations = StockReservation::query()
                ->where('company_id', $companyId)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', StockReservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                $this->decrementReserved($reservation);
                $reservation->status = StockReservation::STATUS_CONSUMED;
                $reservation->save();
            }
        });
    }

    protected function decrementReserved(StockReservation $reservation): void
    {
        $balance = StockBalance::query()
            ->where('company_id', $reservation->company_id)
            ->where('warehouse_id', $reservation->warehouse_id)
            ->where('product_id', $reservation->product_id)
            ->lockForUpdate()
            ->first();

        if ($balance !== null) {
            $newReserved = max(0, (float) $balance->reserved - (float) $reservation->qty);
            $balance->reserved = number_format($newReserved, 4, '.', '');
            $balance->computed_at = now();
            $balance->save();
        }
    }

    /* ---------------------------------------------------------- the desk ---- */

    /**
     * Release one hold by hand (§04-34). Used when an order is abandoned but its
     * lifecycle never reached the release path, or when the storekeeper can see
     * the goods are not actually going anywhere. Always audited with the reason.
     */
    public function releaseOne(StockReservation $reservation, ?int $actorId = null, ?string $reason = null): void
    {
        if ($reservation->status !== StockReservation::STATUS_ACTIVE) {
            throw new RuntimeException(sprintf(
                'That hold is already %s — a reservation can only be released once.',
                $reservation->status,
            ));
        }

        DB::transaction(function () use ($reservation, $actorId, $reason) {
            $fresh = StockReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== StockReservation::STATUS_ACTIVE) {
                throw new RuntimeException('That hold was released a moment ago — nothing left to release.');
            }

            $this->decrementReserved($fresh);
            $fresh->status = StockReservation::STATUS_RELEASED;
            $fresh->save();

            $this->audit->record([
                'action' => 'inventory.reservation_released',
                'entity_type' => 'stock_reservation',
                'entity_id' => $fresh->id,
                'actor_id' => $actorId,
                'before' => ['status' => StockReservation::STATUS_ACTIVE, 'qty' => (string) $reservation->qty],
                'after' => ['status' => StockReservation::STATUS_RELEASED, 'qty' => (string) $fresh->qty],
                'reason' => $reason,
            ]);
        });
    }

    /**
     * The expiry job (§04-34): every active hold whose deadline has passed gives
     * its stock back to availability. Returns how many holds were released.
     * Run from the reservations screen or `erp:reservations:expire`.
     */
    public function expireDue(int $companyId, ?int $actorId = null): int
    {
        $expired = DB::transaction(function () use ($companyId, $actorId) {
            $due = StockReservation::query()
                ->where('company_id', $companyId)
                ->overdue()
                ->lockForUpdate()
                ->get();

            $qty = 0.0;

            foreach ($due as $reservation) {
                $this->decrementReserved($reservation);
                $reservation->status = StockReservation::STATUS_EXPIRED;
                $reservation->save();
                $qty += (float) $reservation->qty;
            }

            if ($due->isNotEmpty()) {
                $this->audit->record([
                    'action' => 'inventory.reservations_expired',
                    'entity_type' => 'stock_reservation',
                    'actor_id' => $actorId,
                    'before' => ['active_holds' => $due->count(), 'qty' => number_format($qty, 4, '.', '')],
                    'after' => ['status' => StockReservation::STATUS_EXPIRED, 'note' => 'deadline passed — stock returned to availability'],
                ]);
            }

            return $due->count();
        });

        return $expired;
    }

    /**
     * The holds, with the filters the desk actually argues with: what is held,
     * where, by which document, and which ones are past their deadline.
     */
    public function holds(int $companyId, array $filters = []): Collection
    {
        return StockReservation::query()
            ->where('company_id', $companyId)
            ->with(['product:id,sku,name', 'warehouse:id,name,code'])
            ->when(($filters['status'] ?? null) === 'open', fn ($q) => $q->open())
            ->when(($filters['status'] ?? null) === 'overdue', fn ($q) => $q->overdue())
            ->when(($filters['status'] ?? null) === 'released', fn ($q) => $q->where('status', StockReservation::STATUS_RELEASED))
            ->when(($filters['status'] ?? null) === 'consumed', fn ($q) => $q->where('status', StockReservation::STATUS_CONSUMED))
            ->when(($filters['status'] ?? null) === 'expired', fn ($q) => $q->where('status', StockReservation::STATUS_EXPIRED))
            ->when(($filters['warehouse'] ?? null) !== null, fn ($q) => $q->where('warehouse_id', $filters['warehouse']))
            ->when(($filters['source'] ?? null) !== null && $filters['source'] !== '', fn ($q) => $q->where('source_type', $filters['source']))
            ->when(($filters['q'] ?? null) !== null && $filters['q'] !== '', fn ($q) => $q->whereHas('product', function ($p) use ($filters) {
                $p->where('sku', 'like', '%'.$filters['q'].'%')->orWhere('name', 'like', '%'.$filters['q'].'%');
            }))
            // Past-deadline first (they need a decision), then live holds by the
            // soonest deadline, then holds with no deadline, then settled ones.
            // Written without SQL functions so the same query runs on MySQL and
            // SQLite (the test database).
            ->orderByRaw('case when status = ? then 0 else 1 end', [StockReservation::STATUS_ACTIVE])
            ->orderByRaw('case when expires_at is null then 1 else 0 end')
            ->orderBy('expires_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * What the shelf is really promising: open holds, overdue holds, and how much
     * quantity is held in total — the numbers behind the reserved column.
     *
     * @return array{open: int, overdue: int, released: int, expired: int, qty_held: float}
     */
    public function counts(int $companyId): array
    {
        $base = fn () => StockReservation::query()->where('company_id', $companyId);

        return [
            'open' => (clone $base())->open()->count(),
            'overdue' => (clone $base())->overdue()->count(),
            'released' => (clone $base())->where('status', StockReservation::STATUS_RELEASED)->count(),
            'expired' => (clone $base())->where('status', StockReservation::STATUS_EXPIRED)->count(),
            'qty_held' => round((float) (clone $base())->where('status', StockReservation::STATUS_ACTIVE)->sum('qty'), 4),
        ];
    }
}
