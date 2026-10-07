<?php

namespace Tests\Feature;

use App\Domain\Foundation\Company;
use App\Domain\Workflow\ApprovalStep;
use App\Domain\Workflow\Events\WorkflowApproved;
use App\Domain\Workflow\Events\WorkflowCancelled;
use App\Domain\Workflow\Events\WorkflowRejected;
use App\Domain\Workflow\Events\WorkflowReturned;
use App\Domain\Workflow\Events\WorkflowSubmitted;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\WorkflowApprover;
use App\Domain\Workflow\WorkflowDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Generic DB-driven workflow engine (Rule 15): definition resolution,
 * snapshot freezing, step materialisation, sequential/parallel advance,
 * self-approval prohibition, reject/return/cancel and the hard guards
 * (duplicate pending → 409, no approvers → 500, non-pending → 409).
 * Every state change runs in the engine's real transactional path.
 */
class WorkflowEngineTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    /** @return array<int, array<string, mixed>> */
    protected function approverRules(array $rules): array
    {
        return $rules;
    }

    protected function definition(array $attributes = [], array $approverRules = []): WorkflowDefinition
    {
        $definition = WorkflowDefinition::create(array_merge([
            'company_id' => Company::current()?->id,
            'entity_type' => 'sales_order',
            'action' => 'create',
            'name' => 'Sales order approval',
            'description' => 'Standard order review',
            'is_active' => true,
            'priority' => 10,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
            'due_hours' => 48,
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

    /** @return array<string, mixed> */
    protected function submitInput($user, array $overrides = []): array
    {
        return array_merge([
            'entity_type' => 'sales_order',
            'entity_id' => 501,
            'action' => 'create',
            'subject' => 'SO-1001',
            'branch_id' => $this->defaultBranch()->id,
            'submitted_by' => $user,
            'snapshot' => ['total' => 15000],
            'amount' => 15000.0,
            'currency' => 'BDT',
        ], $overrides);
    }

    protected function assertAborted(\Closure $fn, int $status, ?string $needle = null): void
    {
        try {
            $fn();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());

            if ($needle !== null) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }

            return;
        }

        $this->fail("Expected an abort with status {$status}, but nothing was thrown.");
    }

    public function test_submit_freezes_the_workflow_and_materialises_levels(): void
    {
        $admin = $this->bootInstance();
        $secondApprover = $this->makeUser();
        $approverRole = $this->roleWith([]);

        $definition = $this->definition([], [
            ['level' => 1, 'approver_type' => 'role', 'role_id' => $approverRole->id],
            ['level' => 2, 'approver_type' => 'user', 'user_id' => $secondApprover->id],
        ]);

        $request = $this->engine()->submit($this->submitInput($admin));

        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertSame(1, (int) $request->current_level);
        $this->assertSame(1, (int) $request->revision);
        $this->assertSame($definition->id, (int) $request->workflow_definition_id);

        // Frozen routing config + integrity hash: definition edits later
        // can never change how this request behaves (versioning rule).
        $this->assertSame($definition->id, (int) $request->snapshot['_workflow']['definition_id']);
        $this->assertSame('sequential', $request->snapshot['_workflow']['approval_mode']);
        $this->assertTrue($request->snapshot['_workflow']['block_self_approval']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $request->snapshot_hash);
        $this->assertNotNull($request->due_at);

        $steps = ApprovalStep::query()
            ->where('approval_request_id', $request->id)
            ->orderBy('level')
            ->get();

        $this->assertSame(2, $steps->count());
        $this->assertTrue((int) $steps[0]->level === 1 && (int) $steps[0]->approver_role_id === (int) $approverRole->id);
        $this->assertTrue((int) $steps[1]->level === 2 && (int) $steps[1]->approver_user_id === (int) $secondApprover->id);
        $this->assertTrue($steps->every(fn (ApprovalStep $s) => $s->status === 'pending'));

        $this->assertDatabaseHas('audit_events', ['action' => 'approval.submit']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => WorkflowSubmitted::class]);
        $this->assertSame(1, (int) \DB::table('approval_actions')->where('action', 'submit')->count());
    }

    public function test_second_submit_of_a_pending_record_is_refused(): void
    {
        $admin = $this->bootInstance();
        $this->definition([], [['level' => 1, 'approver_type' => 'user', 'user_id' => $admin->id]]);

        $this->engine()->submit($this->submitInput($admin));

        $this->assertAborted(
            fn () => $this->engine()->submit($this->submitInput($admin)),
            409,
            'already pending',
        );

        $this->assertSame(1, (int) \DB::table('approval_requests')->count());
    }

    public function test_submit_without_any_workflow_returns_null_and_writes_nothing(): void
    {
        $admin = $this->bootInstance();

        // No definition exists for this entity/action — the caller may
        // proceed without approval (documented contract).
        $request = $this->engine()->submit($this->submitInput($admin));

        $this->assertNull($request);
        $this->assertSame(0, (int) \DB::table('approval_requests')->count());
        $this->assertSame(0, (int) \DB::table('approval_steps')->count());
    }

    public function test_definition_without_approvers_fails_loudly(): void
    {
        $admin = $this->bootInstance();
        $this->definition(); // active, but zero approver rules

        $this->assertAborted(
            fn () => $this->engine()->submit($this->submitInput($admin)),
            500,
            'no approver levels',
        );

        $this->assertSame(0, (int) \DB::table('approval_requests')->count());
    }

    public function test_sequential_approval_advances_levels_then_approves(): void
    {
        $admin = $this->bootInstance();
        $levelOneRole = $this->roleWith([]);
        $approverOne = $this->makeUser();
        $approverOne->roles()->attach($levelOneRole->id);
        $approverTwo = $this->makeUser();

        $this->definition([], [
            ['level' => 1, 'approver_type' => 'role', 'role_id' => $levelOneRole->id],
            ['level' => 2, 'approver_type' => 'user', 'user_id' => $approverTwo->id],
        ]);

        $request = $this->engine()->submit($this->submitInput($admin));

        // Level 1 approved by the role member → advances to level 2.
        $afterFirst = $this->engine()->approve($request->id, $approverOne);

        $this->assertSame('pending', $afterFirst->status);
        $this->assertSame(2, (int) $afterFirst->current_level);
        $stepOne = $afterFirst->steps->firstWhere('level', 1);
        $this->assertSame('approved', $stepOne->status);
        $this->assertSame($approverOne->id, (int) $stepOne->acted_by);

        // Level 2 approved by the named user → request approved.
        $afterSecond = $this->engine()->approve($request->id, $approverTwo);

        $this->assertSame('approved', $afterSecond->status);
        $this->assertNotNull($afterSecond->decided_at);
        $this->assertTrue($afterSecond->steps->every(fn (ApprovalStep $s) => $s->status === 'approved'));

        // Deciding an already decided request is refused (409).
        $this->assertAborted(
            fn () => $this->engine()->approve($request->id, $approverTwo),
            409,
            'no longer pending',
        );

        $this->assertSame(2, (int) \DB::table('audit_events')->where('action', 'approval.approve')->count());
        $this->assertDatabaseHas('outbox_events', ['event_type' => WorkflowApproved::class]);
    }

    public function test_parallel_mode_requires_every_step_before_final_approval(): void
    {
        $admin = $this->bootInstance();
        $approverA = $this->makeUser();
        $approverB = $this->makeUser();

        $this->definition(['approval_mode' => 'parallel'], [
            ['level' => 1, 'approver_type' => 'user', 'user_id' => $approverA->id],
            ['level' => 1, 'approver_type' => 'user', 'user_id' => $approverB->id],
        ]);

        $request = $this->engine()->submit($this->submitInput($admin));

        $afterA = $this->engine()->approve($request->id, $approverA);
        $this->assertSame('pending', $afterA->status); // one step outstanding

        $afterB = $this->engine()->approve($request->id, $approverB);
        $this->assertSame('approved', $afterB->status);
    }

    public function test_self_approval_is_prohibited_but_self_rejection_is_allowed(): void
    {
        $admin = $this->bootInstance();
        $approverRole = $this->roleWith([]);
        $boss = $this->makeUser();
        $boss->roles()->attach($approverRole->id);

        $this->definition([], [['level' => 1, 'approver_type' => 'role', 'role_id' => $approverRole->id]]);

        $request = $this->engine()->submit($this->submitInput($boss));

        // Frozen rule: the submitter may not approve their own request…
        $this->assertAborted(
            fn () => $this->engine()->approve($request->id, $boss),
            403,
            'You cannot approve your own request.',
        );

        // …but a rejection (with mandatory reasoning) from the same actor
        // is an allowed, auditable decision.
        $rejected = $this->engine()->reject($request->id, $boss, 'Outside this quarter’s budget.');

        $this->assertSame('rejected', $rejected->status);
        $this->assertNotNull($rejected->decided_at);
        $this->assertTrue($rejected->steps->every(fn (ApprovalStep $s) => $s->status === 'rejected'));

        $this->assertDatabaseHas('audit_events', ['action' => 'approval.reject']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => WorkflowRejected::class]);

        $action = \DB::table('approval_actions')->where('action', 'reject')->first();
        $this->assertNotNull($action);
        $this->assertStringContainsString('budget', (string) $action->comment);
    }

    public function test_non_approvers_are_refused_at_every_decision_entry_point(): void
    {
        $admin = $this->bootInstance();
        $approverRole = $this->roleWith([]);
        $approver = $this->makeUser();
        $approver->roles()->attach($approverRole->id);
        $outsider = $this->makeUser();

        $this->definition([], [['level' => 1, 'approver_type' => 'role', 'role_id' => $approverRole->id]]);

        $request = $this->engine()->submit($this->submitInput($admin));

        $this->assertAborted(
            fn () => $this->engine()->approve($request->id, $outsider),
            403,
            'You are not an approver for this step.',
        );
        $this->assertAborted(
            fn () => $this->engine()->reject($request->id, $outsider, 'Trying anyway.'),
            403,
            'You are not an approver for this step.',
        );
        $this->assertAborted(
            fn () => $this->engine()->returnForCorrection($request->id, $outsider, 'Trying anyway.'),
            403,
            'You are not an approver for this step.',
        );

        // The request is untouched by the denied attempts.
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertTrue($request->steps()->where('status', 'pending')->count() > 0);
    }

    public function test_return_for_correction_increments_returns_and_allows_resubmission(): void
    {
        $admin = $this->bootInstance();
        $approver = $this->makeUser();

        $this->definition([], [['level' => 1, 'approver_type' => 'user', 'user_id' => $approver->id]]);

        $first = $this->engine()->submit($this->submitInput($admin));

        $returned = $this->engine()->returnForCorrection($first->id, $approver, 'Missing proforma invoice.');

        $this->assertSame('returned', $returned->status);
        $this->assertSame(1, (int) $returned->return_count);
        $this->assertSame('returned', $returned->steps->first()->status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => WorkflowReturned::class]);

        // The record may now be resubmitted — as revision 2.
        $second = $this->engine()->submit($this->submitInput($admin));

        $this->assertNotNull($second);
        $this->assertSame(2, (int) $second->revision);
        $this->assertSame('pending', $second->status);
        $this->assertSame(2, (int) \DB::table('approval_requests')->count());
    }

    public function test_only_requester_approver_or_super_admin_may_cancel(): void
    {
        $admin = $this->bootInstance();
        $approver = $this->makeUser();
        $outsider = $this->makeUser();

        $this->definition([], [['level' => 1, 'approver_type' => 'user', 'user_id' => $approver->id]]);

        $request = $this->engine()->submit($this->submitInput($admin));

        $this->assertAborted(
            fn () => $this->engine()->cancel($request->id, $outsider),
            403,
            'Only the requester, an approver or a super admin may cancel this request.',
        );

        $cancelled = $this->engine()->cancel($request->id, $admin, 'Order withdrawn by customer.');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertTrue($cancelled->steps->every(fn (ApprovalStep $s) => $s->status === 'cancelled'));
        $this->assertDatabaseHas('audit_events', ['action' => 'approval.cancel']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => WorkflowCancelled::class]);
    }

    public function test_comments_are_recorded_in_the_full_action_history(): void
    {
        $admin = $this->bootInstance();
        $approver = $this->makeUser();

        $this->definition([], [['level' => 1, 'approver_type' => 'user', 'user_id' => $approver->id]]);

        $request = $this->engine()->submit($this->submitInput($admin));

        $this->engine()->addComment($request->id, $approver, 'Checking the credit terms with finance.');

        $comment = \DB::table('approval_actions')
            ->where('approval_request_id', $request->id)
            ->where('action', 'comment')
            ->first();

        $this->assertNotNull($comment);
        $this->assertStringContainsString('credit terms', (string) $comment->comment);
        $this->assertDatabaseHas('audit_events', ['action' => 'approval.comment']);

        // A comment never changes the decision state.
        $this->assertSame('pending', $request->fresh()->status);
    }
}
