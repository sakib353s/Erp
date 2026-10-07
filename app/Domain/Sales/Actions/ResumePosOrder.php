<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\Warehouse;
use App\Domain\Sales\PosHold;
use App\Domain\Sales\PosSession;
use App\Domain\Sales\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ResumePosOrder (02-37). Marks hold resumed and re-reserves stock under
 * lock (source_type=pos_hold). Insufficient stock surfaces as RuntimeException.
 */
class ResumePosOrder
{
    public function __construct(
        protected ReservationService $reservations,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(PosHold $hold, Request $request): PosHold
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($hold, $companyId, $request) {
            $fresh = PosHold::query()->whereKey($hold->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'held') {
                throw new RuntimeException("Hold {$fresh->hold_no} is not held (status [{$fresh->status}]).");
            }

            $session = $fresh->pos_session_id
                ? PosSession::query()->find($fresh->pos_session_id)
                : null;

            $warehouseId = $session?->warehouse_id
                ?? Warehouse::query()
                    ->where('company_id', $companyId)
                    ->where('is_default', true)
                    ->value('id')
                ?? Warehouse::query()
                    ->where('company_id', $companyId)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->value('id');

            if ($warehouseId === null) {
                throw new RuntimeException('No warehouse available to re-reserve held stock.');
            }

            $lines = collect($fresh->lines ?? [])->map(fn ($l) => [
                'product_id' => (int) $l['product_id'],
                'qty' => (float) $l['qty'],
            ])->all();

            // Throws on insufficient available stock (stock conflict surfaced)
            $this->reservations->reserve(
                $companyId,
                (int) $warehouseId,
                'pos_hold',
                $fresh->id,
                $lines,
            );

            $fresh->status = 'resumed';
            $fresh->resumed_at = now();
            $fresh->save();

            $this->audit->record([
                'action' => 'pos.hold_resumed',
                'entity_type' => 'pos_hold',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'hold_no' => $fresh->hold_no,
                    'status' => 'resumed',
                    'warehouse_id' => (int) $warehouseId,
                ],
            ]);

            return $fresh;
        });
    }
}
