<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\Actions\TrackShipmentEvent;
use App\Domain\Delivery\Couriers\ProviderRegistry;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\TrackingEvent;
use App\Domain\Masters\Courier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Shipment tracking screen + ingest endpoints (02-90). GET shows the
 * honest event timeline; the manual POST needs
 * sales.delivery.shipments.create; the public courier webhook is HMAC
 * signed (X-ERP-Signature over the raw body) and idempotent per
 * external event id — an unsigned or mis-signed push never reaches the
 * log. Since 02-92 the provider's declared webhook vocabulary is
 * translated into canonical codes before validation; unknown codes are
 * rejected with an honest 422, never guessed.
 */
class TrackingEventController extends Controller
{
    public function __construct(
        protected TrackShipmentEvent $tracker,
        protected ProviderRegistry $providers,
    ) {}

    public function show(Request $request, Shipment $shipment): View
    {
        abort_unless(
            (int) $shipment->company_id === (int) $request->user()->company_id,
            404,
        );

        return view('sales.shipments.tracking', [
            'shipment' => $shipment->load(['order', 'courier']),
            'events' => TrackingEvent::query()
                ->where('shipment_id', $shipment->id)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get(),
            'codes' => TrackShipmentEvent::CODES,
        ]);
    }

    public function store(Request $request, Shipment $shipment): RedirectResponse
    {
        abort_unless(
            (int) $shipment->company_id === (int) $request->user()->company_id,
            404,
        );

        $data = $request->validate([
            'event_code' => ['required', Rule::in(array_keys(TrackShipmentEvent::CODES))],
            'description' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:150'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        try {
            $result = $this->tracker->handle($shipment, $data, [
                'source' => TrackingEvent::SOURCE_MANUAL,
                'actor' => $request->user(),
            ]);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['event_code' => $e->getMessage()]);
        }

        return redirect()
            ->route('sales.shipments.tracking', $shipment)
            ->with(
                'status',
                $result['outcome'] === 'duplicate'
                    ? 'That tracking event is already recorded.'
                    : 'Tracking event recorded.',
            );
    }

    public function webhook(Request $request, Courier $courier): JsonResponse
    {
        $raw = (string) $request->getContent();

        $secret = $courier->webhook_secret;
        if ($secret === null || $secret === '') {
            abort(response()->json([
                'message' => 'Webhook signing secret is not configured for this courier.',
            ], 401));
        }

        $provided = (string) $request->header('X-ERP-Signature', '');
        $provided = (string) preg_replace('/^sha256=/i', '', $provided);

        if ($provided === '' || ! hash_equals(hash_hmac('sha256', $raw, $secret), $provided)) {
            abort(response()->json(['message' => 'Invalid webhook signature.'], 401));
        }

        // 02-92: translate the provider's declared webhook vocabulary
        // into canonical codes. Codes outside both sets are rejected
        // with an honest message — never guessed.
        $adapter = $this->providers->adapterFor($courier);
        $rawCode = (string) $request->input('event_code', '');
        $mapped = $rawCode !== '' ? $adapter->mapWebhookEvent($rawCode) : null;

        if ($rawCode !== '' && $mapped === null) {
            return response()->json([
                'message' => sprintf(
                    '%s webhook event not recognized: %s',
                    $adapter->label(),
                    $rawCode,
                ),
            ], 422);
        }

        if ($mapped !== null) {
            $request->merge(['event_code' => $mapped]);
        }

        $data = $request->validate([
            'shipment_id' => ['required', 'integer'],
            'external_event_id' => ['required', 'string', 'max:100'],
            'event_code' => ['required', 'string', Rule::in(array_keys(TrackShipmentEvent::CODES))],
            'description' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:150'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $shipment = Shipment::query()
            ->whereKey((int) $data['shipment_id'])
            ->where('courier_id', $courier->id)
            ->first();

        abort_if($shipment === null, 404);

        $result = $this->tracker->handle($shipment, $data, [
            'source' => TrackingEvent::SOURCE_WEBHOOK,
            'actor' => null,
            'idempotency_key' => sprintf('wh:%d:%s', $courier->id, $data['external_event_id']),
        ]);

        return response()->json([
            'outcome' => $result['outcome'],
            'tracking_event_id' => $result['event']->id,
        ]);
    }
}
