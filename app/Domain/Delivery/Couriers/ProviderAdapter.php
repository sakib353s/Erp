<?php

namespace App\Domain\Delivery\Couriers;

use App\Domain\Delivery\Actions\TrackShipmentEvent;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\TrackingEvent;
use App\Domain\Masters\Courier;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\PricingService;

/**
 * Shared adapter logic (02-92). Provider subclasses only declare their
 * identity (slug/code/label) and webhook vocabulary — the four
 * capabilities live here so every provider is held to the same
 * truthfulness rules.
 */
abstract class ProviderAdapter implements CourierAdapter
{
    public function __construct(
        protected PricingService $pricing,
    ) {}

    abstract public function slug(): ?string;

    abstract public function code(): string;

    abstract public function label(): string;

    abstract public function eventMap(): array;

    public function assign(Courier $courier, SalesOrder $order): array
    {
        if (! $courier->isConfigured() || ! $courier->integration_enabled) {
            return [
                'dispatched' => false,
                'external_ref' => null,
                'provider' => $this->slug(),
                'tracking_url' => null,
            ];
        }

        $ref = sprintf(
            '%s-%s',
            strtoupper((string) $courier->code),
            strtoupper((string) $order->order_no),
        );

        return [
            'dispatched' => true,
            'external_ref' => $ref,
            'provider' => $this->slug(),
            'tracking_url' => $this->trackingUrl($courier, $ref),
        ];
    }

    public function quoteRate(Courier $courier, array $quote): array
    {
        if (! $courier->isConfigured() || ! $courier->integration_enabled) {
            return [
                'available' => false,
                'source' => null,
                'reason' => sprintf(
                    '%s is not configured for integration — no rate can be quoted.',
                    $this->label(),
                ),
            ];
        }

        $districtId = isset($quote['district_id']) ? (int) $quote['district_id'] : 0;
        if ($districtId <= 0) {
            return [
                'available' => false,
                'source' => null,
                'reason' => 'No destination district provided.',
            ];
        }

        $amount = $this->pricing->shippingForDistrict(
            (int) $courier->company_id,
            $districtId,
            (float) ($quote['weight_kg'] ?? 0.0),
        );

        if ($amount === null) {
            return [
                'available' => false,
                'source' => null,
                'reason' => 'No active delivery zone covers this destination.',
            ];
        }

        return [
            'available' => true,
            'amount' => $amount,
            'currency' => 'BDT',
            'source' => 'delivery_zone',
        ];
    }

    public function track(Courier $courier, Shipment $shipment): array
    {
        if (! $courier->isConfigured() || ! $courier->integration_enabled) {
            return [
                'available' => false,
                'source' => null,
                'consignment_ref' => $shipment->external_ref,
                'tracking_url' => null,
                'events' => [],
                'reason' => sprintf(
                    '%s is not configured for integration — no tracking is available.',
                    $this->label(),
                ),
            ];
        }

        $ref = (string) ($shipment->external_ref ?? '');

        $events = TrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TrackingEvent $event) => [
                'code' => $event->event_code,
                'label' => TrackShipmentEvent::CODES[$event->event_code] ?? $event->event_code,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'location' => $event->location,
                'source' => $event->source,
            ])
            ->all();

        return [
            'available' => true,
            'source' => 'local_tracking_events',
            'consignment_ref' => $shipment->external_ref,
            'tracking_url' => $ref === '' ? null : $this->trackingUrl($courier, $ref),
            'events' => $events,
        ];
    }

    public function mapWebhookEvent(string $event): ?string
    {
        if (array_key_exists($event, TrackShipmentEvent::CODES)) {
            return $event;
        }

        $map = $this->eventMap();

        return $map[$event] ?? $map[strtoupper($event)] ?? null;
    }

    protected function trackingUrl(Courier $courier, string $externalRef): ?string
    {
        $pattern = (string) ($courier->tracking_url_pattern ?? '');
        if ($pattern === '' || $externalRef === '') {
            return null;
        }

        return str_replace('{external_ref}', $externalRef, $pattern);
    }
}
