<?php

namespace Tests\Feature;

use App\Domain\Masters\PricingRule;
use App\Domain\Sales\Services\PricingService;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPricingRules;
use Tests\TestCase;

/**
 * 02-113 manual override threshold → WF: an over-threshold change from
 * an analyst without pricing.override is held (the rule keeps its old
 * values, approval_status=pending, PricingService unaffected); approve
 * applies the held payload (and activates a held new rule), reject
 * keeps the originals; while pending every edit is blocked; the exact
 * threshold boundary still applies directly.
 */
class PriceOverrideApprovalTest extends TestCase
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

    public function test_over_threshold_update_is_held_with_values_untouched(): void
    {
        $approver = $this->makeOverrideWorkflow();
        $analyst = $this->makePricingAnalyst();
        $rule = $this->makeRule(['price' => null, 'percent_off' => 10, 'name' => 'Held rule']);

        $this->actingAs($analyst)
            ->put(route('pricing.rules.update', $rule), $this->rulePayload([
                'price' => null,
                'percent_off' => 50,
                'name' => 'Held rule',
            ]))
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('held for approval', $status);

        $rule->refresh();
        $this->assertEqualsWithDelta(10, (float) $rule->percent_off, 0.0001);
        $this->assertSame('pending', $rule->approval_status);

        $approval = $this->pendingApprovals()->firstOrFail();
        $this->assertSame($rule->id, (int) $approval->entity_id);

        // The engine still resolves the old price while held.
        $this->assertEqualsWithDelta(
            90.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, null, 1),
            0.0001,
        );
    }

    public function test_approval_applies_the_held_payload_and_resolution_follows(): void
    {
        $approver = $this->makeOverrideWorkflow();
        $analyst = $this->makePricingAnalyst();
        $rule = $this->makeRule(['price' => null, 'percent_off' => 10, 'name' => 'Held rule']);

        $this->actingAs($analyst)
            ->put(route('pricing.rules.update', $rule), $this->rulePayload([
                'price' => null,
                'percent_off' => 50,
                'name' => 'Held rule',
            ]))
            ->assertRedirect();

        $approval = $this->pendingApprovals()->firstOrFail();
        app(WorkflowEngine::class)->approve($approval->id, $approver, 'ok');

        $rule->refresh();
        $this->assertEqualsWithDelta(50, (float) $rule->percent_off, 0.0001);
        $this->assertSame('approved', $rule->approval_status);

        $this->assertEqualsWithDelta(
            50.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, null, 1),
            0.0001,
        );
    }

    public function test_rejection_keeps_original_values_and_marks_rejected(): void
    {
        $approver = $this->makeOverrideWorkflow();
        $analyst = $this->makePricingAnalyst();
        $rule = $this->makeRule(['price' => null, 'percent_off' => 10, 'name' => 'Held rule']);

        $this->actingAs($analyst)
            ->put(route('pricing.rules.update', $rule), $this->rulePayload([
                'price' => null,
                'percent_off' => 50,
                'name' => 'Held rule',
            ]))
            ->assertRedirect();

        $approval = $this->pendingApprovals()->firstOrFail();
        app(WorkflowEngine::class)->reject($approval->id, $approver, 'too deep');

        $rule->refresh();
        $this->assertEqualsWithDelta(10, (float) $rule->percent_off, 0.0001);
        $this->assertSame('rejected', $rule->approval_status);

        // Rejected is not pending — governance actions work again.
        $this->actingAs($analyst)
            ->patch(route('pricing.rules.priority', $rule), ['priority' => 7])
            ->assertRedirect();

        $this->assertSame(7, $rule->refresh()->priority);
    }

    public function test_pending_rule_blocks_further_edits(): void
    {
        $this->makeOverrideWorkflow();
        $analyst = $this->makePricingAnalyst();
        $rule = $this->makeRule(['price' => null, 'percent_off' => 10, 'name' => 'Held rule']);

        $this->actingAs($analyst)
            ->put(route('pricing.rules.update', $rule), $this->rulePayload([
                'price' => null,
                'percent_off' => 50,
                'name' => 'Held rule',
            ]))
            ->assertRedirect();

        $this->assertSame('pending', $rule->refresh()->approval_status);

        $this->actingAs($analyst)
            ->from(route('pricing.rules.index'))
            ->put(route('pricing.rules.update', $rule), $this->rulePayload([
                'price' => null,
                'percent_off' => 5,
                'name' => 'Held rule',
            ]))
            ->assertSessionHasErrors('rule');

        $this->actingAs($analyst)
            ->from(route('pricing.rules.index'))
            ->patch(route('pricing.rules.priority', $rule), ['priority' => 3])
            ->assertSessionHasErrors('rule');

        $this->actingAs($analyst)
            ->from(route('pricing.rules.index'))
            ->patch(route('pricing.rules.status', $rule))
            ->assertSessionHasErrors('rule');

        $rule->refresh();
        $this->assertEqualsWithDelta(10, (float) $rule->percent_off, 0.0001);
        $this->assertSame(100, $rule->priority);
        $this->assertTrue($rule->is_active);
    }

    public function test_threshold_boundary_applies_directly(): void
    {
        $this->makeOverrideWorkflow();
        $analyst = $this->makePricingAnalyst();
        $rule = $this->makeRule(['price' => null, 'percent_off' => 5, 'name' => 'Boundary rule']);

        $this->actingAs($analyst)
            ->put(route('pricing.rules.update', $rule), $this->rulePayload([
                'price' => null,
                'percent_off' => PricingRule::OVERRIDE_THRESHOLD_PCT,
                'name' => 'Boundary rule',
            ]))
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('updated', $status);
        $this->assertStringNotContainsString('held', $status);

        $rule->refresh();
        $this->assertEqualsWithDelta(25, (float) $rule->percent_off, 0.0001);
        $this->assertNull($rule->approval_status);
        $this->assertSame(0, $this->pendingApprovals()->count());
    }

    public function test_new_over_threshold_rule_is_created_inactive_and_held_then_approved(): void
    {
        $approver = $this->makeOverrideWorkflow();
        $analyst = $this->makePricingAnalyst();

        $this->actingAs($analyst)
            ->post(route('pricing.rules.store'), $this->rulePayload([
                'price' => null,
                'percent_off' => 50,
                'name' => 'Held new rule',
            ]))
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('held for approval', $status);

        $rule = PricingRule::query()->where('name', 'Held new rule')->firstOrFail();
        $this->assertFalse($rule->is_active);
        $this->assertSame('pending', $rule->approval_status);

        // Held: never resolves.
        $this->assertEqualsWithDelta(
            100.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, null, 1),
            0.0001,
        );

        $approval = $this->pendingApprovals()->firstOrFail();
        app(WorkflowEngine::class)->approve($approval->id, $approver, 'ok');

        $rule->refresh();
        $this->assertTrue($rule->is_active);
        $this->assertSame('approved', $rule->approval_status);

        $this->assertEqualsWithDelta(
            50.0,
            $this->pricing->resolveUnitPrice($this->product, $this->customer, null, null, 1),
            0.0001,
        );
    }

    protected function pendingApprovals()
    {
        return ApprovalRequest::query()
            ->where('entity_type', PricingRule::ENTITY_TYPE)
            ->where('action', PricingRule::APPROVAL_ACTION)
            ->where('status', 'pending');
    }
}
