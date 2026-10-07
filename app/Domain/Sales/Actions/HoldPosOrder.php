<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Sales\PosHold;
use App\Domain\Sales\PosSession;
use App\Domain\Sales\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * HoldPosOrder (02-36). Snapshot cart JSON into pos_holds. While held,
 * no stock reservation is kept (release if present under pos_hold source).
 * Hold number synthesised from money_receipt numbering with HOLD- prefix
 * when available, else timestamped.
 */
class HoldPosOrder
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected ReservationService $reservations,
        protected NumberingService $numbering,
    ) {}

    /**
     * @param array{
     *   pos_session_id?: int|null,
     *   customer_id?: int|null,
     *   lines: array<int, array{product_id: int, qty: float, unit_price?: float|null, discount?: float, description?: string|null}>
     * } $payload
     */
    public function handle(array $payload, Request $request): PosHold
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $lines = $payload['lines'] ?? [];

        if ($lines === []) {
            throw new RuntimeException('Hold requires at least one line.');
        }

        return DB::transaction(function () use ($payload, $lines, $companyId, $request) {
            $sessionId = $payload['pos_session_id'] ?? null;
            $session = $sessionId
                ? PosSession::query()->where('company_id', $companyId)->find($sessionId)
                : PosSession::query()
                    ->where('company_id', $companyId)
                    ->where('branch_id', $request->user()->default_branch_id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

            $resolved = [];
            $total = 0.0;
            foreach ($lines as $line) {
                $product = Product::query()
                    ->where('company_id', $companyId)
                    ->findOrFail($line['product_id']);
                $qty = (float) ($line['qty'] ?? 0);
                if ($qty <= 0) {
                    throw new RuntimeException('Hold line qty must be greater than zero.');
                }
                $unit = round((float) ($line['unit_price'] ?? 0), 4);
                $discount = round((float) ($line['discount'] ?? 0), 4);
                $lineTotal = round($qty * $unit - $discount, 4);
                $total += $lineTotal;
                $resolved[] = [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'discount' => $discount,
                    'description' => $line['description'] ?? null,
                    'line_total' => $lineTotal,
                ];
            }

            // While held: release any pos_hold reservation for a prior hold id if provided
            if (! empty($payload['release_hold_id'])) {
                $this->reservations->release($companyId, 'pos_hold', (int) $payload['release_hold_id']);
            }

            $docType = DocumentType::query()->where('code', 'money_receipt')->first();
            $holdNo = $docType !== null
                ? 'HOLD-'.$this->numbering->allocate($docType->id, $request->user()->default_branch_id)
                : 'HOLD-'.now()->format('YmdHis').'-'.uniqid();

            $hold = PosHold::create([
                'company_id' => $companyId,
                'pos_session_id' => $session?->id,
                'customer_id' => $payload['customer_id'] ?? null,
                'hold_no' => $holdNo,
                'lines' => $resolved,
                'total' => number_format($total, 4, '.', ''),
                'status' => 'held',
                'held_at' => now(),
                'created_by' => $request->user()->id,
            ]);

            $this->audit->record([
                'action' => 'pos.hold_created',
                'entity_type' => 'pos_hold',
                'entity_id' => $hold->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'hold_no' => $holdNo,
                    'total' => $total,
                    'line_count' => count($resolved),
                ],
            ]);

            return $hold;
        });
    }
}
