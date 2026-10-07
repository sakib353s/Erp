<?php

namespace Tests\Feature;

use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\ZoneCharge;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\Actions\CreateSalesOrder;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPricingRules;
use Tests\TestCase;

/**
 * 02-88b zone-wise charges feed totals: when a payload carries no
 * explicit shipping, PricingService::resolveShipping derives it from
 * the customer's district zone — base_charge + per_kg_charge × weight
 * plus the first active weight-slab zone_charge covering the weight.
 * Explicit shipping wins; nothing is charged without a real zone;
 * inactive zones/charges and other companies' zones never charge.
 */
class ZoneChargeAppliesToTotalsTest extends TestCase
{
    use BuildsPricingRules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPricing();
        $this->seed(DocumentTypeSeeder::class);
    }

    /** @return array<string, mixed> */
    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 100],
            ],
        ], $overrides);
    }

    private function zoneWithCharges(float $base, float $perKg): DeliveryZone
    {
        $zone = $this->makeZoneFor($this->customer);
        $zone->forceFill(['base_charge' => $base, 'per_kg_charge' => $perKg])->save();

        return $zone;
    }

    public function test_order_shipping_applies_base_per_kg_and_covering_slab(): void
    {
        $zone = $this->zoneWithCharges(40, 10);
        ZoneCharge::query()->create([
            'company_id' => $this->admin->company_id,
            'delivery_zone_id' => $zone->id,
            'code' => 'SLAB-5',
            'weight_from' => 0,
            'weight_to' => 5,
            'amount' => 30,
        ]);

        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload(['shipping_weight_kg' => 2]),
            $this->pricingRequest(),
        );

        // 40 base + 10×2kg + 30 slab = 90
        $this->assertEqualsWithDelta(90.0, (float) $order->shipping, 0.0001);
        $this->assertEqualsWithDelta(190.0, (float) $order->grand_total, 0.0001);
    }

    public function test_weight_outside_every_slab_pays_zone_only(): void
    {
        $zone = $this->zoneWithCharges(40, 10);
        ZoneCharge::query()->create([
            'company_id' => $this->admin->company_id,
            'delivery_zone_id' => $zone->id,
            'code' => 'SLAB-5',
            'weight_from' => 0,
            'weight_to' => 5,
            'amount' => 30,
        ]);

        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload(['shipping_weight_kg' => 6]),
            $this->pricingRequest(),
        );

        // 40 base + 10×6kg = 100, slab (≤5kg) does not cover
        $this->assertEqualsWithDelta(100.0, (float) $order->shipping, 0.0001);
    }

    public function test_explicit_shipping_wins_over_the_zone(): void
    {
        $this->zoneWithCharges(40, 10);

        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload(['shipping' => 15, 'shipping_weight_kg' => 2]),
            $this->pricingRequest(),
        );

        $this->assertEqualsWithDelta(15.0, (float) $order->shipping, 0.0001);
    }

    public function test_no_zone_mapping_ships_free(): void
    {
        // Customer never gets a district/zone mapping.
        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload(['shipping_weight_kg' => 2]),
            $this->pricingRequest(),
        );

        $this->assertEqualsWithDelta(0.0, (float) $order->shipping, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $order->grand_total, 0.0001);
    }

    public function test_inactive_zone_or_charge_never_charges(): void
    {
        $zone = $this->zoneWithCharges(40, 10);
        $charge = ZoneCharge::query()->create([
            'company_id' => $this->admin->company_id,
            'delivery_zone_id' => $zone->id,
            'code' => 'SLAB-5',
            'weight_from' => 0,
            'weight_to' => 5,
            'amount' => 30,
        ]);

        // Charge off → base + per-kg only.
        $charge->forceFill(['is_active' => false])->save();
        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload(['shipping_weight_kg' => 2]),
            $this->pricingRequest(),
        );
        $this->assertEqualsWithDelta(60.0, (float) $order->shipping, 0.0001);

        // Zone off → nothing at all, even an active charge.
        $zone->forceFill(['is_active' => false])->save();
        $charge->forceFill(['is_active' => true])->save();
        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload(['shipping_weight_kg' => 2]),
            $this->pricingRequest(),
        );
        $this->assertEqualsWithDelta(0.0, (float) $order->shipping, 0.0001);
    }

    public function test_quotation_uses_the_same_zone_shipping(): void
    {
        $zone = $this->zoneWithCharges(40, 10);
        ZoneCharge::query()->create([
            'company_id' => $this->admin->company_id,
            'delivery_zone_id' => $zone->id,
            'code' => 'SLAB-5',
            'weight_from' => 0,
            'weight_to' => 5,
            'amount' => 30,
        ]);

        $quote = app(CreateQuotation::class)->handle(
            $this->orderPayload(['shipping_weight_kg' => 2]),
            $this->pricingRequest(),
        );

        $this->assertEqualsWithDelta(90.0, (float) $quote->shipping, 0.0001);
        $this->assertEqualsWithDelta(190.0, (float) $quote->grand_total, 0.0001);
    }

    public function test_another_companys_zone_never_charges_my_shipping(): void
    {
        // The customer's district maps ONLY to a zone owned by another company.
        $districtId = DB::table('districts')->orderBy('id')->value('id');
        $this->customer->forceFill(['district_id' => $districtId])->save();

        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Shipping Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowZone = DeliveryZone::query()->create([
            'company_id' => $shadowId,
            'code' => 'SHDW-Z',
            'name' => 'Shadow zone',
            'base_charge' => 40,
            'per_kg_charge' => 10,
        ]);
        DB::table('delivery_zone_district')->insert([
            'delivery_zone_id' => $shadowZone->id,
            'district_id' => $districtId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ZoneCharge::query()->create([
            'company_id' => $shadowId,
            'delivery_zone_id' => $shadowZone->id,
            'code' => 'SHDW-S',
            'weight_from' => 0,
            'amount' => 99,
        ]);

        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload(['shipping_weight_kg' => 2]),
            $this->pricingRequest(),
        );

        $this->assertEqualsWithDelta(0.0, (float) $order->shipping, 0.0001);
    }
}
