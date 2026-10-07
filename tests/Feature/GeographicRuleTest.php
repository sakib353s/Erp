<?php

namespace Tests\Feature;

use App\Domain\Masters\Customer;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\PricingRule;
use App\Domain\Sales\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPricingRules;
use Tests\TestCase;

/**
 * 02-111 geographic pricing: zone rules resolve through the customer's
 * district → delivery_zone_district mapping, company-scoped, never
 * leaking across zones, companies or walk-ins.
 */
class GeographicRuleTest extends TestCase
{
    use BuildsPricingRules;
    use RefreshDatabase;

    protected PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPricing();
        $this->pricing = app(PricingService::class);
    }

    public function test_zone_rule_applies_when_the_customer_district_maps_to_the_zone(): void
    {
        $zone = $this->makeZoneFor($this->customer);

        $this->makeRule([
            'rule_type' => PricingRule::TYPE_GEOGRAPHIC,
            'name' => 'Dhaka zone price',
            'delivery_zone_id' => $zone->id,
            'price' => 85,
            'percent_off' => null,
        ]);

        $this->assertEqualsWithDelta(
            85.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15'),
            0.0001,
        );
    }

    public function test_zone_rule_never_applies_outside_its_zone(): void
    {
        $zone = $this->makeZoneFor($this->customer);

        $this->makeRule([
            'rule_type' => PricingRule::TYPE_GEOGRAPHIC,
            'name' => 'Dhaka zone price',
            'delivery_zone_id' => $zone->id,
            'price' => 85,
            'percent_off' => null,
        ]);

        // A second customer with no district resolves to no zone.
        $elsewhere = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PRC-ELSE',
            'name' => 'Customer elsewhere',
            'is_active' => true,
        ]);

        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, $elsewhere, null, '2026-09-15'),
            0.0001,
        );

        // Walk-in POS sales (no customer) never match a zone-scoped rule.
        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, null, null, '2026-09-15'),
            0.0001,
        );
    }

    public function test_another_customers_zone_does_not_capture_this_rule(): void
    {
        // The mapped district belongs to a DIFFERENT zone than the rule's.
        $ruleZone = DeliveryZone::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'ZONE-OTHER',
            'name' => 'Unmapped zone',
        ]);

        $mapped = $this->makeZoneFor($this->customer); // maps customer's district

        $this->makeRule([
            'rule_type' => PricingRule::TYPE_GEOGRAPHIC,
            'name' => 'Wrong zone',
            'delivery_zone_id' => $ruleZone->id,
            'price' => 10,
            'percent_off' => null,
        ]);

        $this->assertNotSame($ruleZone->id, $mapped->id);
        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15'),
            0.0001,
        );
    }

    public function test_geographic_stage_runs_between_quantity_break_and_time_based(): void
    {
        $zone = $this->makeZoneFor($this->customer);

        $this->makeRule([
            'rule_type' => PricingRule::TYPE_QUANTITY_BREAK,
            'name' => 'Qty base',
            'qty_min' => 1,
            'price' => 90,
            'percent_off' => null,
        ]);
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_GEOGRAPHIC,
            'name' => 'Zone price',
            'delivery_zone_id' => $zone->id,
            'price' => 60,
            'percent_off' => null,
        ]);
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'name' => 'Halve it',
            'price' => null,
            'percent_off' => 50,
        ]);

        $explain = $this->pricing->explain($this->product, $this->customer, null, '2026-09-15 12:00:00', 3);

        $applied = array_values(array_filter(
            array_column($explain['stages'], 'stage'),
            fn ($stage, $index) => $explain['stages'][$index]['applied'],
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertSame(
            [
                PricingRule::TYPE_QUANTITY_BREAK,
                PricingRule::TYPE_GEOGRAPHIC,
                PricingRule::TYPE_TIME_BASED,
            ],
            $applied,
        );

        // 100 → 90 (qty) → 60 (geo) → 30 (time −50%)
        $this->assertEqualsWithDelta(30.0, $explain['final'], 0.0001);
    }

    public function test_zone_scope_validation_rejects_foreign_zones_and_binds_404(): void
    {
        $shadowCompanyId = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Geo Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignZoneId = (int) DB::table('delivery_zones')->insertGetId([
            'company_id' => $shadowCompanyId,
            'code' => 'FOREIGN-Z',
            'name' => 'Foreign zone',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('pricing.rules.store'), $this->rulePayload([
                'rule_type' => PricingRule::TYPE_GEOGRAPHIC,
                'delivery_zone_id' => $foreignZoneId,
            ]))
            ->assertSessionHasErrors('delivery_zone_id');

        // Foreign customer groups are equally unusable as a scope.
        $foreignGroupId = (int) DB::table('customer_groups')->insertGetId([
            'company_id' => $shadowCompanyId,
            'code' => 'FOREIGN-G',
            'name' => 'Foreign group',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('pricing.rules.store'), $this->rulePayload([
                'rule_type' => PricingRule::TYPE_CUSTOMER_GROUP,
                'customer_group_id' => $foreignGroupId,
            ]))
            ->assertSessionHasErrors('customer_group_id');
    }
}
