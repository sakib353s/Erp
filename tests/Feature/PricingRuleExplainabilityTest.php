<?php

namespace Tests\Feature;

use App\Domain\Masters\PricingRule;
use App\Domain\Masters\ProductCategory;
use App\Domain\Sales\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPricingRules;
use Tests\TestCase;

/**
 * 02-111 explainable quote breakdown: every resolution exposes its base
 * source plus one honest entry per stage (applied or not), and rule CRUD
 * is validated, company-scoped and audited.
 */
class PricingRuleExplainabilityTest extends TestCase
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

    public function test_explain_final_always_matches_resolve_unit_price(): void
    {
        $group = $this->makeGroup();
        $this->customer->customer_group_id = $group->id;
        $this->customer->save();

        $this->makeRule([
            'rule_type' => PricingRule::TYPE_CUSTOMER_GROUP,
            'name' => 'Group tier',
            'customer_group_id' => $group->id,
            'price' => 85,
            'percent_off' => null,
        ]);

        $explain = $this->pricing->explain($this->product, $this->customer, null, '2026-09-15');

        $this->assertSame('price_list', $explain['base']['source']);
        $this->assertSame('PRC-LIST', $explain['base']['price_list_code']);
        $this->assertEqualsWithDelta(100.0, (float) $explain['base']['price'], 0.0001);

        $groupStage = collect($explain['stages'])->firstWhere('stage', PricingRule::TYPE_CUSTOMER_GROUP);
        $this->assertTrue($groupStage['applied']);
        $this->assertSame('price', $groupStage['mode']);
        $this->assertEqualsWithDelta(85.0, (float) $groupStage['after'], 0.0001);

        $this->assertEqualsWithDelta(
            $explain['final'],
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, '2026-09-15'),
            0.0001,
        );
    }

    public function test_product_missing_from_lists_reports_standard_cost_base(): void
    {
        $orphan = $this->pricingProduct('PRC-ORPH', 'No list product', 55);

        $explain = $this->pricing->explain($orphan, null, null, '2026-09-15');

        $this->assertSame('standard_cost', $explain['base']['source']);
        $this->assertNull($explain['base']['price_list_id']);
        $this->assertEqualsWithDelta(55.0, $explain['final'], 0.0001);
        $this->assertTrue(
            collect($explain['stages'])->every(fn ($s) => $s['applied'] === false),
        );
    }

    public function test_product_scope_applies_only_to_the_named_product(): void
    {
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'name' => 'Only PRC-2',
            'product_id' => $this->other->id,
            'price' => 150,
            'percent_off' => null,
        ]);

        $forOther = $this->pricing->explain($this->other, null, null, '2026-09-15');
        $stage = collect($forOther['stages'])->firstWhere('stage', PricingRule::TYPE_TIME_BASED);
        $this->assertTrue($stage['applied']);
        $this->assertEqualsWithDelta(150.0, $forOther['final'], 0.0001);

        $forProduct = $this->pricing->explain($this->product, null, null, '2026-09-15');
        $stage = collect($forProduct['stages'])->firstWhere('stage', PricingRule::TYPE_TIME_BASED);
        $this->assertFalse($stage['applied']);
        $this->assertEqualsWithDelta(100.0, $forProduct['final'], 0.0001);
    }

    public function test_customer_group_scope_applies_only_to_group_members(): void
    {
        $group = $this->makeGroup();

        $this->makeRule([
            'rule_type' => PricingRule::TYPE_CUSTOMER_GROUP,
            'name' => 'Members only',
            'customer_group_id' => $group->id,
            'price' => 60,
            'percent_off' => null,
        ]);

        $outsider = $this->pricing->explain($this->product, $this->customer, null, '2026-09-15');
        $this->assertEqualsWithDelta(100.0, $outsider['final'], 0.0001);

        $this->customer->customer_group_id = $group->id;
        $this->customer->save();

        $member = $this->pricing->explain($this->product, $this->customer, null, '2026-09-15');
        $this->assertEqualsWithDelta(60.0, $member['final'], 0.0001);

        $walkIn = $this->pricing->explain($this->product, null, null, '2026-09-15');
        $this->assertEqualsWithDelta(100.0, $walkIn['final'], 0.0001);
    }

    public function test_product_category_scope_applies_only_inside_the_category(): void
    {
        $category = ProductCategory::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PRC-CAT',
            'name' => 'Probe category',
        ]);

        $this->product->product_category_id = $category->id;
        $this->product->save();

        $this->makeRule([
            'rule_type' => PricingRule::TYPE_TIME_BASED,
            'name' => 'Category promo',
            'product_category_id' => $category->id,
            'price' => null,
            'percent_off' => 25,
        ]);

        $inside = $this->pricing->explain($this->product, null, null, '2026-09-15');
        $this->assertEqualsWithDelta(75.0, $inside['final'], 0.0001);

        $outside = $this->pricing->explain($this->other, null, null, '2026-09-15');
        $this->assertEqualsWithDelta(200.0, $outside['final'], 0.0001);
    }

    public function test_rule_crud_is_validated_company_scoped_and_audited(): void
    {
        // Exactly one value: both price + percent rejected.
        $this->actingAs($this->admin)
            ->post(route('pricing.rules.store'), $this->rulePayload([
                'price' => 90,
                'percent_off' => 10,
            ]))
            ->assertSessionHasErrors('price');

        // Type-required scope enforced.
        $this->actingAs($this->admin)
            ->post(route('pricing.rules.store'), $this->rulePayload([
                'rule_type' => PricingRule::TYPE_GEOGRAPHIC,
                'delivery_zone_id' => null,
            ]))
            ->assertSessionHasErrors('delivery_zone_id');

        // Valid store → audited create + rendered on the index.
        $response = $this->actingAs($this->admin)
            ->post(route('pricing.rules.store'), $this->rulePayload([
                'name' => 'Autumn tier',
                'percent_off' => null,
                'price' => 77,
            ]));
        $response->assertRedirect();

        $rule = PricingRule::query()->where('name', 'Autumn tier')->firstOrFail();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'record.create',
            'entity_type' => PricingRule::class,
            'entity_id' => $rule->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('pricing.rules.index'))
            ->assertOk()
            ->assertSee('Autumn tier');

        // Update → audited.
        $this->actingAs($this->admin)
            ->put(route('pricing.rules.update', $rule), $this->rulePayload([
                'name' => 'Winter tier',
                'percent_off' => null,
                'price' => 66,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'record.update',
            'entity_type' => PricingRule::class,
            'entity_id' => $rule->id,
        ]);

        // Another company's rule is invisible to the binding (404).
        $shadowCompanyId = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Rules Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignId = (int) DB::table('pricing_rules')->insertGetId([
            'company_id' => $shadowCompanyId,
            'rule_type' => 'time_based',
            'name' => 'Foreign rule',
            'priority' => 100,
            'is_active' => 1,
            'price' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('pricing.rules.edit', $foreignId))
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->delete(route('pricing.rules.destroy', $foreignId))
            ->assertNotFound();

        // Delete own rule → gone + audited.
        $this->actingAs($this->admin)
            ->delete(route('pricing.rules.destroy', $rule))
            ->assertRedirect();

        $this->assertDatabaseMissing('pricing_rules', ['id' => $rule->id]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'record.delete',
            'entity_type' => PricingRule::class,
            'entity_id' => $rule->id,
        ]);
    }
}
