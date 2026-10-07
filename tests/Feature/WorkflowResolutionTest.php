<?php

namespace Tests\Feature;

use App\Domain\Foundation\Company;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\WorkflowApprover;
use App\Domain\Workflow\WorkflowCondition;
use App\Domain\Workflow\WorkflowDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Workflow DEFINITION resolution (correction G): thresholds, branch and
 * currency rules live in workflow_conditions rows — AND inside a group,
 * OR across groups, priority breaks ties, inactive rows never resolve.
 */
class WorkflowResolutionTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    protected function definition(string $name, array $attributes = []): WorkflowDefinition
    {
        return WorkflowDefinition::create(array_merge([
            'company_id' => Company::current()?->id,
            'entity_type' => 'purchase_order',
            'action' => 'create',
            'name' => $name,
            'is_active' => true,
            'priority' => 0,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
        ], $attributes));
    }

    public function test_unconditional_definition_matches_any_context(): void
    {
        $this->bootInstance();

        $base = $this->definition('Base PO approval');
        WorkflowApprover::create([
            'workflow_definition_id' => $base->id,
            'level' => 1,
            'approver_type' => 'role',
            'role_id' => $this->roleWith([])->id,
        ]);

        $resolved = $this->engine()->resolve('purchase_order', 'create', ['amount' => 10.0, 'branch_id' => 1]);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($base));
    }

    public function test_amount_threshold_selects_the_matching_definition(): void
    {
        $this->bootInstance();

        $base = $this->definition('Base PO approval', ['priority' => 0]);
        $highValue = $this->definition('High value PO', ['priority' => 10]);

        WorkflowCondition::create([
            'workflow_definition_id' => $highValue->id,
            'subject' => 'amount',
            'operator' => 'gte',
            'value_min' => 50000,
            'condition_group' => 0,
            'position' => 0,
        ]);

        $role = $this->roleWith([]);
        foreach ([$base, $highValue] as $definition) {
            WorkflowApprover::create([
                'workflow_definition_id' => $definition->id,
                'level' => 1,
                'approver_type' => 'role',
                'role_id' => $role->id,
            ]);
        }

        $big = $this->engine()->resolve('purchase_order', 'create', ['amount' => 75000.0]);
        $small = $this->engine()->resolve('purchase_order', 'create', ['amount' => 900.0]);

        $this->assertTrue($big->is($highValue));
        $this->assertTrue($small->is($base));
    }

    public function test_inactive_definitions_are_never_resolved(): void
    {
        $this->bootInstance();

        $inactive = $this->definition('Retired routing', ['is_active' => false, 'priority' => 99]);

        $this->assertNull($this->engine()->resolve('purchase_order', 'create', ['amount' => 1.0]));
        $this->assertTrue($inactive->is_active === false);
    }

    public function test_higher_priority_wins_between_two_matching_definitions(): void
    {
        $this->bootInstance();

        $low = $this->definition('Generic routing', ['priority' => 5]);
        $high = $this->definition('Preferred routing', ['priority' => 50]);

        $resolved = $this->engine()->resolve('purchase_order', 'create', []);

        $this->assertTrue($resolved->is($high));
        $this->assertFalse($resolved->is($low));
    }

    public function test_unknown_entity_resolves_nothing_and_submit_skips(): void
    {
        $admin = $this->bootInstance();

        $this->definition('PO approval'); // exists — but for purchase_order only

        $this->assertNull($this->engine()->resolve('expense_claim', 'create', []));

        $request = $this->engine()->submit([
            'entity_type' => 'expense_claim',
            'entity_id' => 1,
            'action' => 'create',
            'subject' => 'Trip claim',
            'branch_id' => $this->defaultBranch()->id,
            'submitted_by' => $admin,
            'snapshot' => ['amount' => 500],
            'amount' => 500.0,
        ]);

        $this->assertNull($request);
    }

    public function test_conditions_and_within_a_group_and_or_across_groups(): void
    {
        $this->bootInstance();

        $definition = $this->definition('Grouped routing');

        // Group 0 (AND): currency is BDT AND amount >= 1000
        WorkflowCondition::create([
            'workflow_definition_id' => $definition->id,
            'subject' => 'currency',
            'operator' => 'eq',
            'value_string' => 'BDT',
            'condition_group' => 0,
            'position' => 0,
        ]);
        WorkflowCondition::create([
            'workflow_definition_id' => $definition->id,
            'subject' => 'amount',
            'operator' => 'gte',
            'value_min' => 1000,
            'condition_group' => 0,
            'position' => 1,
        ]);
        // Group 1 (OR): amount >= 100000 regardless of currency
        WorkflowCondition::create([
            'workflow_definition_id' => $definition->id,
            'subject' => 'amount',
            'operator' => 'gte',
            'value_min' => 100000,
            'condition_group' => 1,
            'position' => 0,
        ]);

        $groupOneMatches = $this->engine()->resolve(
            'purchase_order', 'create', ['currency' => 'BDT', 'amount' => 2000.0],
        );
        $neitherGroup = $this->engine()->resolve(
            'purchase_order', 'create', ['currency' => 'USD', 'amount' => 2000.0],
        );
        $groupTwoMatches = $this->engine()->resolve(
            'purchase_order', 'create', ['currency' => 'USD', 'amount' => 150000.0],
        );

        $this->assertNotNull($groupOneMatches);
        $this->assertTrue($groupOneMatches->is($definition));

        $this->assertNull($neitherGroup); // failed both groups

        $this->assertNotNull($groupTwoMatches);
        $this->assertTrue($groupTwoMatches->is($definition));
    }
}
