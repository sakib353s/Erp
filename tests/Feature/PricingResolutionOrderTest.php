<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Masters\PricingRule;
use App\Domain\Sales\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPricingRules;
use Tests\TestCase;

/**
 * 02-111 resolution order: base list → customer group → quantity break
 * → geographic → time-based → special, cascading stage by stage with
 * within-stage priority — deterministic, explainable, server-authoritative.
 */
class PricingResolutionOrderTest extends TestCase
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

    public function test_base_price_list_resolves_unchanged_when_no_rules_match(): void
    {
        $price = $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15');

        $this->assertEqualsWithDelta(100.0, $price, 0.0001);

        $explain = $this->pricing->explain($this->product, $this->customer, null, '2026-09-15');

        $this->assertSame('price_list', $explain['base']['source']);
        $this->assertSame($this->list->id, $explain['base']['price_list_id']);
        $this->assertCount(5, $explain['stages']);
        $this->assertTrue(
            collect($explain['stages'])->every(fn ($s) => $s['applied'] === false),
        );
        $this->assertEqualsWithDelta(100.0, $explain['final'], 0.0001);
    }

    public function test_explicit_override_short_circuits_the_whole_cascade(): void
    {
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'price' => 90,
            'percent_off' => null,
        ]);
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_SPECIAL,
            'name' => 'Special',
            'customer_id' => $this->customer->id,
            'price' => 70,
            'percent_off' => null,
        ]);

        $price = $this->pricing->resolveUnitPrice($this->product, $this->customer, 55.0, '2026-09-15');

        $this->assertEqualsWithDelta(55.0, $price, 0.0001);

        $explain = $this->pricing->explain($this->product, $this->customer, 55.0, '2026-09-15');
        $this->assertSame('explicit', $explain['base']['source']);
        $this->assertSame([], $explain['stages']);
    }

    public function test_stages_cascade_in_documented_order_with_before_after_chain(): void
    {
        $group = $this->makeGroup();
        $this->customer->customer_group_id = $group->id;
        $this->customer->save();
        $zone = $this->makeZoneFor($this->customer);

        $this->makeRule([
            'rule_type' => PricingRule::TYPE_CUSTOMER_GROUP,
            'name' => 'Group price',
            'customer_group_id' => $group->id,
            'price' => 90,
            'percent_off' => null,
        ]);
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_QUANTITY_BREAK,
            'name' => 'Qty 10 off 10%',
            'qty_min' => 5,
            'price' => null,
            'percent_off' => 10,
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
            'name' => 'Always-on 50% off',
            'price' => null,
            'percent_off' => 50,
        ]);
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_SPECIAL,
            'name' => 'Special 10% off',
            'customer_id' => $this->customer->id,
            'price' => null,
            'percent_off' => 10,
        ]);

        $explain = $this->pricing->explain($this->product, $this->customer, null, '2026-09-15 12:00:00', 10);

        $this->assertSame(
            PricingRule::STAGES,
            array_column($explain['stages'], 'stage'),
        );

        // 100 → 90 (group) → 81 (qty −10%) → 60 (geo) → 30 (time −50%) → 27 (special −10%)
        $this->assertSame(
            [100.0, 90.0, 81.0, 60.0, 30.0],
            array_map(fn ($s) => (float) $s['before'], $explain['stages']),
        );
        $this->assertSame(
            [90.0, 81.0, 60.0, 30.0, 27.0],
            array_map(fn ($s) => (float) $s['after'], $explain['stages']),
        );
        $this->assertEqualsWithDelta(27.0, $explain['final'], 0.0001);
        $this->assertEqualsWithDelta(
            27.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15 12:00:00', 10),
            0.0001,
        );
    }

    public function test_special_absolute_price_beats_earlier_stages(): void
    {
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'name' => 'Happy hour',
            'price' => null,
            'percent_off' => 50,
        ]);
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_SPECIAL,
            'name' => 'Negotiated special',
            'customer_id' => $this->customer->id,
            'price' => 75,
            'percent_off' => null,
        ]);

        // 100 → 50 (time −50%) → 75 (special absolute restores negotiated price)
        $this->assertEqualsWithDelta(
            75.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15 12:00:00'),
            0.0001,
        );
    }

    public function test_within_a_stage_the_lowest_priority_number_wins(): void
    {
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_QUANTITY_BREAK,
            'name' => 'Later break',
            'priority' => 50,
            'qty_min' => 5,
            'price' => 85,
            'percent_off' => null,
        ]);
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_QUANTITY_BREAK,
            'name' => 'Earlier break',
            'priority' => 10,
            'qty_min' => 5,
            'price' => 70,
            'percent_off' => null,
        ]);

        $this->assertEqualsWithDelta(
            70.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15', 6),
            0.0001,
        );

        // Below the break quantity neither applies → base price.
        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15', 1),
            0.0001,
        );
    }

    public function test_inactive_or_foreign_company_rules_never_apply(): void
    {
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'name' => 'Disabled',
            'is_active' => false,
            'price' => 1,
            'percent_off' => null,
        ]);

        $shadowCompanyId = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Pricing Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pricing_rules')->insert([
            'company_id' => $shadowCompanyId,
            'rule_type' => 'time_based',
            'name' => 'Foreign rule',
            'priority' => 1,
            'is_active' => 1,
            'price' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15'),
            0.0001,
        );
    }

    public function test_rules_routes_require_the_pricing_rules_permission(): void
    {
        $denied = $this->makeUser(['name' => 'Price Manager Only']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.manage'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)->get(route('pricing.rules.index'))->assertForbidden();
        $this->actingAs($denied)->get(route('pricing.rules.create'))->assertForbidden();
        $this->actingAs($denied)
            ->post(route('pricing.rules.store'), $this->rulePayload())
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Rule Manager']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.rules'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)->get(route('pricing.rules.index'))->assertOk();
        $this->actingAs($allowed)->get(route('pricing.rules.create'))->assertOk();
        $this->actingAs($allowed)
            ->post(route('pricing.rules.store'), $this->rulePayload())
            ->assertRedirect();

        $rule = PricingRule::query()->where('name', 'Probe rule')->firstOrFail();
        $this->actingAs($allowed)->get(route('pricing.rules.edit', $rule))->assertOk();
    }
}
