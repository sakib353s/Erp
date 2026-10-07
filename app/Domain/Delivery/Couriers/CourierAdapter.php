<?php

namespace App\Domain\Delivery\Couriers;

use App\Domain\Delivery\Shipment;
use App\Domain\Masters\Courier;
use App\Domain\Sales\SalesOrder;

/**
 * Per-provider courier adapter (02-92). Every adapter speaks the same
 * four capabilities — create-shipment (assign), rates, track, webhook —
 * and every one of them is truthful by construction:
 *
 *  - a courier that is not configured (or whose integration is off)
 *    never dispatches, never quotes a rate, never reports tracking —
 *    each call returns an explicit reason instead;
 *  - rates come only from the tenant's own delivery-zone configuration
 *    (source says so) — no provider API is ever called or faked;
 *  - track maps locally recorded tracking events; history is never
 *    invented;
 *  - webhook vocabulary is exactly `eventMap()` — anything outside it
 *    is rejected with an honest error, never guessed.
 */
interface CourierAdapter
{
    /** Provider slug for routes/menus; null for the generic fallback. */
    public function slug(): ?string;

    /** Canonical courier code (uppercase) this adapter owns. */
    public function code(): string;

    public function label(): string;

    /**
     * Adapter-declared webhook vocabulary: provider event code => one of
     * TrackShipmentEvent::CODES.
     *
     * @return array<string, string>
     */
    public function eventMap(): array;

    /**
     * Create-shipment/assignment. Configured + enabled yields the
     * provider-shaped consignment ref; otherwise a local, reference-less
     * pending assignment.
     *
     * @return array{dispatched: bool, external_ref: ?string, provider: ?string, tracking_url: ?string}
     */
    public function assign(Courier $courier, SalesOrder $order): array;

    /**
     * Rate quote from local delivery-zone configuration only.
     *
     * @param  array{district_id?: int|null, weight_kg?: float}  $quote
     * @return array{available: bool, amount?: float, currency?: string, source?: ?string, reason?: string}
     */
    public function quoteRate(Courier $courier, array $quote): array;

    /**
     * Provider-shaped view of the shipment's locally recorded events.
     *
     * @return array{available: bool, source: ?string, consignment_ref: ?string, tracking_url: ?string, events: list<array{code: string, label: string, occurred_at: ?string, location: ?string, source: string}>, reason?: string}
     */
    public function track(Courier $courier, Shipment $shipment): array;

    /**
     * Translate a webhook event code into a canonical one, or null when
     * the code is outside both the canonical set and eventMap().
     */
    public function mapWebhookEvent(string $event): ?string;
}
