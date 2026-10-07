<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\ProofOfDelivery;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\TrackingEvent;
use App\Domain\Documents\Services\FileUploadService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Actions\MarkDeliveryChallanDelivered;
use App\Domain\Sales\DeliveryChallan;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * RecordProofOfDelivery (02-95): the goods reached the customer.
 * Only dispatched/out-for-delivery shipments qualify — pending and
 * already-delivered are refused with the actual status. Proof (a
 * signature and/or photo file plus the receiver name) goes through
 * the safe upload pipeline into documents; the POD row links them.
 *
 * The delivered state moves through TrackShipmentEvent (the single
 * status ingest: forward-only, order advanced via OrderStateMachine,
 * order creator notified) and linked delivery challans follow.
 * Stock/GL/warranty are NOT touched here — those stay at their
 * configured stages (dispatch TRANSIT_OUT, invoice issue
 * TRANSIT_CLEAR/SALES_OUT + COGS; warranty activation remains
 * Phase L), so delivery never double-posts.
 */
class RecordProofOfDelivery
{
    public function __construct(
        protected TenantContext $context,
        protected FileUploadService $uploads,
        protected TrackShipmentEvent $track,
        protected MarkDeliveryChallanDelivered $markChallan,
        protected AuditRecorder $audit,
    ) {}

    /** @param array{receiver_name?: ?string, notes?: ?string, delivered_at?: ?string, signature?: ?UploadedFile, photo?: ?UploadedFile} $data */
    public function handle(Shipment $shipment, array $data, Request $request): ProofOfDelivery
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if ((int) $shipment->company_id !== $companyId) {
            abort(404);
        }

        $receiver = trim((string) ($data['receiver_name'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));
        $signature = $data['signature'] ?? null;
        $photo = $data['photo'] ?? null;

        if ($receiver === '' && $signature === null && $photo === null) {
            throw new RuntimeException('Record at least one proof: a signature, a photo, or the receiver name.');
        }

        $deliveredAt = ! empty($data['delivered_at'])
            ? Carbon::parse($data['delivered_at'])
            : now();

        return DB::transaction(function () use (
            $shipment, $receiver, $notes, $signature, $photo,
            $deliveredAt, $request, $companyId
        ) {
            $fresh = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, [
                Shipment::STATUS_DISPATCHED,
                Shipment::STATUS_OUT_FOR_DELIVERY,
            ], true)) {
                throw new RuntimeException(sprintf(
                    'Shipment #%d cannot record proof of delivery from status [%s].',
                    $fresh->id,
                    $fresh->status,
                ));
            }

            $signatureDoc = $signature instanceof UploadedFile
                ? $this->uploads->store($signature, $request->user(), [
                    'owner_type' => Shipment::class,
                    'owner_id' => (int) $fresh->id,
                    'purpose' => 'signature',
                ])
                : null;

            $photoDoc = $photo instanceof UploadedFile
                ? $this->uploads->store($photo, $request->user(), [
                    'owner_type' => Shipment::class,
                    'owner_id' => (int) $fresh->id,
                    'purpose' => 'attachment',
                ])
                : null;

            $pod = ProofOfDelivery::query()->create([
                'company_id' => $companyId,
                'branch_id' => $request->user()?->default_branch_id,
                'shipment_id' => $fresh->id,
                'sales_order_id' => $fresh->sales_order_id,
                'delivered_at' => $deliveredAt,
                'receiver_name' => $receiver !== '' ? $receiver : null,
                'notes' => $notes !== '' ? mb_substr($notes, 0, 500) : null,
                'signature_document_id' => $signatureDoc?->id,
                'photo_document_id' => $photoDoc?->id,
                'recorded_by' => $request->user()?->id,
            ]);

            $result = $this->track->handle($fresh, [
                'event_code' => 'delivered',
                'description' => 'Proof of delivery recorded'
                    .($receiver !== '' ? ' — received by '.$receiver : ''),
                'occurred_at' => $deliveredAt->toDateTimeString(),
            ], [
                'source' => TrackingEvent::SOURCE_MANUAL,
                'actor' => $request->user(),
                'idempotency_key' => 'pod:'.$fresh->id,
            ]);

            $challansMarked = 0;
            if ($fresh->sales_order_id !== null) {
                $challans = DeliveryChallan::query()
                    ->where('company_id', $companyId)
                    ->where('sales_order_id', $fresh->sales_order_id)
                    ->whereIn('status', ['dispatched', 'ready'])
                    ->orderBy('id')
                    ->get();

                foreach ($challans as $challan) {
                    $this->markChallan->handle($challan, $request);
                    $challansMarked++;
                }
            }

            $this->audit->record([
                'action' => 'sales.pod_recorded',
                'entity_type' => 'proof_of_delivery',
                'entity_id' => $pod->id,
                'actor_id' => $request->user()?->id,
                'after' => [
                    'shipment_id' => $fresh->id,
                    'sales_order_id' => $fresh->sales_order_id,
                    'delivered_at' => $deliveredAt->toIso8601String(),
                    'receiver_name' => $receiver !== '' ? $receiver : null,
                    'signature_document_id' => $signatureDoc?->id,
                    'photo_document_id' => $photoDoc?->id,
                    'tracking_outcome' => $result['outcome'],
                    'challans_marked_delivered' => $challansMarked,
                ],
            ]);

            return $pod;
        });
    }
}
