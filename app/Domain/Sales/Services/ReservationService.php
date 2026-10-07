<?php

namespace App\Domain\Sales\Services;

use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Sales\StockReservation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Order/POS stock holds. Reservations update stock_balances.reserved
 * (derived cache) under row lock; the immutable movement ledger is NOT
 * written until consume (issue) via StockLedgerService.
 */
class ReservationService
{
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
}
