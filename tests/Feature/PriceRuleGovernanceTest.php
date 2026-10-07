<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Masters\PricingRule;
use App\Domain\Workflow\ApprovalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPricingRules;
use Tests\TestCase;

/**
 * 02-113 Price Rules governance: enable/disable and priority endpoints
 * on the rules screen, and the manual-override authority — a value
 * change that goes past the override threshold (deep percent_off or a
 * fixed price undercutting the product's base by more than the
 * threshold) is held for the pricing_rule/override workflow unless the
 * editor holds pricing.override; without a workflow definition the
 * engine's bypass semantics apply (change lands directly).
 */
class PriceRuleGovernanceTest extends TestCase
{
    use BuildsPricingRules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPricing();
    }

    public function test_status_toggle_flips_the_active_flag(): void
    {
        $rule = $this->makeRule(['is_active' => true]);

        $this->actingAs($this->admin)
            ->patch(route('pricing.rules.status', $rule))
            ->assertRedirect();

        $rule->refresh();
        $this->assertFalse($rule->is_active);

        $this->actingAs($this->admin)
            ->patch(route('pricing.rules.status', $rule))
            ->assertRedirect();

        $rule->refresh();
        $this->assertTrue($rule->is_active);
    }

    public function test_priority_update_validates_bounds_and_applies(): void
    {
        $rule = $this->makeRule(['priority' => 100]);

        $this->actingAs($this->admin)
            ->patch(route('pricing.rules.priority', $rule), ['priority' => 5])
            ->assertRedirect();

        $rule->refresh();
        $this->assertSame(5, $rule->priority);

        $this->actingAs($this->admin)
            ->from(route('pricing.rules.index'))
            ->patch(route('pricing.rules.priority', $rule), ['priority' => 10000])
            ->assertSessionHasErrors('priority');

        $this->actingAs($this->admin)
            ->from(route('pricing.rules.index'))
            ->patch(route('pricing.rules.priority', $rule), ['priority' => -1])
            ->assertSessionHasErrors('priority');

        $this->actingAs($this->admin)
            ->from(route('pricing.rules.index'))
            ->patch(route('pricing.rules.priority', $rule), [])
            ->assertSessionHasErrors('priority');

        $this->assertSame(5, $rule->refresh()->priority);
    }

    public function test_under_threshold_changes_apply_directly_without_approval(): void
    {
        $analyst = $this->makePricingAnalyst();
        $rule = $this->makeRule(['price' => null, 'percent_off' => 5, 'name' => 'Direct rule']);

        $this->actingAs($analyst)
            ->put(route('pricing.rules.update', $rule), $this->rulePayload([
                'price' => null,
                'percent_off' => 10,
                'name' => 'Direct rule',
            ]))
            ->assertRedirect();

        $rule->refresh();
        $this->assertEqualsWithDelta(10, (float) $rule->percent_off, 0.0001);
        $this->assertNull($rule->approval_status);
        $this->assertSame(0, $this->overrideRequests()->count());
    }

    public function test_over_threshold_without_authority_and_without_definition_bypasses(): void
    {
        $analyst = $this->makePricingAnalyst();
        $rule = $this->makeRule(['price' => null, 'percent_off' => 5, 'name' => 'Bypass rule']);

        $this->actingAs($analyst)
            ->put(route('pricing.rules.update', $rule), $this->rulePayload([
                'price' => null,
                'percent_off' => 50,
                'name' => 'Bypass rule',
            ]))
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('updated', $status);
        $this->assertStringNotContainsString('held', $status);

        $rule->refresh();
        $this->assertEqualsWithDelta(50, (float) $rule->percent_off, 0.0001);
        $this->assertNull($rule->approval_status);
        $this->assertSame(0, $this->overrideRequests()->count());
    }

    public function test_override_authority_applies_over_threshold_changes_even_with_a_definition(): void
    {
        $this->makeOverrideWorkflow();

        $holder = $this->makeUser(['name' => 'Pricing Override Holder']);
        $holder->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.rules', 'pricing.override'])->id);
        app(PermissionCatalog::class)->invalidate($holder);

        $rule = $this->makeRule(['price' => null, 'percent_off' => 5, 'name' => 'Authority rule']);

        $this->actingAs($holder)
            ->put(route('pricing.rules.update', $rule), $this->rulePayload([
                'price' => null,
                'percent_off' => 50,
                'name' => 'Authority rule',
            ]))
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('updated', $status);
        $this->assertStringNotContainsString('held', $status);

        $rule->refresh();
        $this->assertEqualsWithDelta(50, (float) $rule->percent_off, 0.0001);
        $this->assertNull($rule->approval_status);
        $this->assertSame(0, $this->overrideRequests()->count());
    }

    public function test_governance_routes_require_the_pricing_rules_permission(): void
    {
        $rule = $this->makeRule();

        $denied = $this->makeUser(['name' => 'Price Viewer Only']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->patch(route('pricing.rules.status', $rule))
            ->assertForbidden();

        $this->actingAs($denied)
            ->patch(route('pricing.rules.priority', $rule), ['priority' => 5])
            ->assertForbidden();

        $allowed = $this->makePricingAnalyst();

        $this->actingAs($allowed)
            ->patch(route('pricing.rules.status', $rule))
            ->assertRedirect();

        $this->assertTrue($rule->refresh()->is_active === false);
    }

    protected function overrideRequests()
    {
        return ApprovalRequest::query()
            ->where('entity_type', PricingRule::ENTITY_TYPE)
            ->where('action', PricingRule::APPROVAL_ACTION);
    }
}
