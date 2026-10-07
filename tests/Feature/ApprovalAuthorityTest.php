<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Workflow\ApprovalDelegation;
use App\Domain\Workflow\Services\ApprovalAuthority;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\WorkflowApprover;
use App\Domain\Workflow\WorkflowDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Approval authority (Rule 15): WHO may act — role rules, direct user
 * rules, branch scope, active delegation, and the frozen self-approval
 * prohibition. Enforced server-side per step, independent of any UI.
 */
class ApprovalAuthorityTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function authority(): ApprovalAuthority
    {
        return app(ApprovalAuthority::class);
    }

    protected function definition(array $attributes, array $approverRules): WorkflowDefinition
    {
        $definition = WorkflowDefinition::create(array_merge([
            'company_id' => Company::current()?->id,
            'entity_type' => 'sales_order',
            'action' => 'create',
            'name' => 'SO authority test',
            'is_active' => true,
            'priority' => 10,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
        ], $attributes));

        foreach ($approverRules as $rule) {
            WorkflowApprover::create(array_merge([
                'workflow_definition_id' => $definition->id,
                'is_required' => true,
                'position' => 0,
            ], $rule));
        }

        return $definition;
    }

    protected function submitRequest($submitter, int $branchId, array $definitionAttributes = [], array $rules = []): \App\Domain\Workflow\ApprovalRequest
    {
        if ($rules !== []) {
            $this->definition($definitionAttributes, $rules);
        }

        return app(WorkflowEngine::class)->submit([
            'entity_type' => 'sales_order',
            'entity_id' => 700,
            'action' => 'create',
            'subject' => 'SO-700',
            'branch_id' => $branchId,
            'submitted_by' => $submitter,
            'snapshot' => ['total' => 100],
            'amount' => 100.0,
        ]);
    }

    public function test_role_members_may_act_while_outsiders_cannot(): void
    {
        $admin = $this->bootInstance();
        $role = $this->roleWith([]);
        $member = $this->makeUser();
        $member->roles()->attach($role->id);
        $outsider = $this->makeUser();

        $request = $this->submitRequest($admin, $this->defaultBranch()->id, [], [
            ['level' => 1, 'approver_type' => 'role', 'role_id' => $role->id],
        ]);

        $step = $request->steps->first();

        $this->assertTrue($this->authority()->canAct($request, $step, $member));
        $this->assertFalse($this->authority()->canAct($request, $step, $outsider));

        $this->assertSame(
            1,
            $this->authority()->actionableSteps($request, $member, 'approve')->count(),
        );
        $this->assertSame(
            0,
            $this->authority()->actionableSteps($request, $outsider, 'approve')->count(),
        );
    }

    public function test_direct_user_rule_names_exact_approvers(): void
    {
        $admin = $this->bootInstance();
        $principal = $this->makeUser();
        $other = $this->makeUser();

        $request = $this->submitRequest($admin, $this->defaultBranch()->id, [], [
            ['level' => 1, 'approver_type' => 'user', 'user_id' => $principal->id],
        ]);

        $step = $request->steps->first();

        $this->assertTrue($this->authority()->canAct($request, $step, $principal));
        $this->assertFalse($this->authority()->canAct($request, $step, $other));
    }

    public function test_approver_must_have_access_to_the_requests_branch(): void
    {
        $admin = $this->bootInstance();
        $ctg = Branch::query()->create([
            'company_id' => $admin->company_id,
            'code' => 'CTG',
            'name' => 'Chittagong Depot',
            'is_active' => true,
        ]);

        $role = $this->roleWith([]);
        $allScopeMember = $this->makeUser(); // all branches
        $allScopeMember->roles()->attach($role->id);
        $headOfficeOnly = $this->makeUser(['branch_scope' => 'assigned']); // head office only
        $headOfficeOnly->roles()->attach($role->id);

        $request = $this->submitRequest($admin, $ctg->id, [], [
            ['level' => 1, 'approver_type' => 'role', 'role_id' => $role->id],
        ]);

        $step = $request->steps->first();

        // Branch rule (Rule 5): an approver outside the request's branch
        // may not act, even when their role says so.
        $this->assertTrue($this->authority()->canAct($request, $step, $allScopeMember));
        $this->assertFalse($this->authority()->canAct($request, $step, $headOfficeOnly));
    }

    public function test_active_delegation_transfers_step_authority(): void
    {
        $admin = $this->bootInstance();
        $principal = $this->makeUser();
        $delegate = $this->makeUser();

        $request = $this->submitRequest($admin, $this->defaultBranch()->id, [], [
            ['level' => 1, 'approver_type' => 'user', 'user_id' => $principal->id],
        ]);

        $step = $request->steps->first();

        // Without any delegation the delegate cannot act.
        $this->assertFalse($this->authority()->canAct($request, $step, $delegate));

        $delegation = ApprovalDelegation::query()->create([
            'company_id' => $admin->company_id,
            'delegator_user_id' => $principal->id,
            'delegate_user_id' => $delegate->id,
            'entity_type' => null, // covers every entity type
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $this->assertTrue($this->authority()->canAct($request, $step, $delegate));

        // Outside the active window → no authority.
        $delegation->update(['ends_at' => now()->subMinute()]);
        $this->assertFalse($this->authority()->canAct($request, $step, $delegate));

        // Re-open the window but pin it to a foreign entity type → no authority.
        $delegation->update(['ends_at' => now()->addDay(), 'entity_type' => 'expense_claim']);
        $this->assertFalse($this->authority()->canAct($request, $step, $delegate));
    }

    public function test_self_approval_prohibition_blocks_approve_only(): void
    {
        $admin = $this->bootInstance();
        $role = $this->roleWith([]);
        $submitter = $this->makeUser();
        $submitter->roles()->attach($role->id);

        $request = $this->submitRequest(
            $submitter,
            $this->defaultBranch()->id,
            ['block_self_approval' => true],
            [['level' => 1, 'approver_type' => 'role', 'role_id' => $role->id]],
        );

        $step = $request->steps->first();

        $this->assertTrue($this->authority()->selfApprovalBlocked($request, $submitter));
        $this->assertFalse($this->authority()->canAct($request, $step, $submitter, 'approve'));
        $this->assertTrue($this->authority()->canAct($request, $step, $submitter, 'reject'));
        $this->assertTrue($this->authority()->canAct($request, $step, $submitter, 'return'));
    }

    public function test_self_approval_rule_can_be_switched_off_by_the_definition(): void
    {
        $admin = $this->bootInstance();
        $role = $this->roleWith([]);
        $submitter = $this->makeUser();
        $submitter->roles()->attach($role->id);

        $request = $this->submitRequest(
            $submitter,
            $this->defaultBranch()->id,
            ['block_self_approval' => false],
            [['level' => 1, 'approver_type' => 'role', 'role_id' => $role->id]],
        );

        $this->assertFalse($this->authority()->selfApprovalBlocked($request, $submitter));
        $this->assertTrue(
            $this->authority()->canAct($request, $request->steps->first(), $submitter, 'approve'),
        );
    }

    public function test_frozen_self_approval_rule_survives_definition_edits(): void
    {
        $admin = $this->bootInstance();
        $role = $this->roleWith([]);
        $submitter = $this->makeUser();
        $submitter->roles()->attach($role->id);

        $definition = $this->submitRequest(
            $submitter,
            $this->defaultBranch()->id,
            ['block_self_approval' => true],
            [['level' => 1, 'approver_type' => 'role', 'role_id' => $role->id]],
        )->definition;

        // Someone edits the definition after submission…
        $definition->update(['block_self_approval' => false]);

        $request = $submitter
            ->company()
            ? \App\Domain\Workflow\ApprovalRequest::query()->orderByDesc('id')->first()
            : null;

        $this->assertNotNull($request);

        // The in-flight request keeps the rule it was submitted with.
        $this->assertTrue($request->frozenBlocksSelfApproval());
        $this->assertFalse($this->authority()->selfApprovalBlocked($request, $submitter) === false);
        $this->assertFalse(
            $this->authority()->canAct($request, $request->steps->first(), $submitter, 'approve'),
        );
    }

    public function test_actionable_steps_are_limited_to_the_current_level(): void
    {
        $admin = $this->bootInstance();
        $roleOne = $this->roleWith([]);
        $approverOne = $this->makeUser();
        $approverOne->roles()->attach($roleOne->id);
        $approverTwo = $this->makeUser();

        $request = $this->submitRequest($admin, $this->defaultBranch()->id, [], [
            ['level' => 1, 'approver_type' => 'role', 'role_id' => $roleOne->id],
            ['level' => 2, 'approver_type' => 'user', 'user_id' => $approverTwo->id],
        ]);

        // While level 1 is pending, the level-2 approver has nothing to act on.
        $this->assertSame(
            0,
            $this->authority()->actionableSteps($request, $approverTwo, 'approve')->count(),
        );
        $this->assertSame(
            1,
            $this->authority()->actionableSteps($request, $approverOne, 'approve')->count(),
        );

        app(WorkflowEngine::class)->approve($request->id, $approverOne);

        $request->refresh()->load('steps');
        $this->assertSame(2, (int) $request->current_level);
        $this->assertSame(
            1,
            $this->authority()->actionableSteps($request, $approverTwo, 'approve')->count(),
        );
    }

    public function test_step_recipients_are_role_members_inside_the_branch_scope(): void
    {
        $admin = $this->bootInstance();
        $ctg = Branch::query()->create([
            'company_id' => $admin->company_id,
            'code' => 'CTG',
            'name' => 'Chittagong Depot',
            'is_active' => true,
        ]);

        $role = $this->roleWith([]);
        $inScope = $this->makeUser(); // all branches
        $inScope->roles()->attach($role->id);
        $outOfScope = $this->makeUser(['branch_scope' => 'assigned']); // head office only
        $outOfScope->roles()->attach($role->id);

        $request = $this->submitRequest($admin, $ctg->id, [], [
            ['level' => 1, 'approver_type' => 'role', 'role_id' => $role->id],
        ]);

        $recipients = $this->authority()->recipientsForStep($request, $request->steps->first());

        $this->assertSame([$inScope->id], $recipients->pluck('id')->all());
    }
}
