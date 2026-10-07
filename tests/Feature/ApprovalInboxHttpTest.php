<?php

namespace Tests\Feature;

use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use Database\Seeders\FoundationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * Approval inbox over real HTTP (Rule 15): DB-driven permission gates on
 * every route, decisions routed through the engine, mandatory comments
 * for reject/return, and the self-approval prohibition holding even for
 * a super admin.
 */
class ApprovalInboxHttpTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
    }

    protected function makeApproval(): array
    {
        $admin = $this->bootInstance();

        $approverRole = $this->roleWith([
            'portal.erp.access',
            'approvals.view',
            'approvals.decide',
            'approvals.comment',
            'approvals.cancel',
        ]);
        $approver = $this->makeUser();
        $approver->roles()->attach($approverRole->id);

        $definition = \App\Domain\Workflow\WorkflowDefinition::create([
            'company_id' => $admin->company_id,
            'entity_type' => 'sales_order',
            'action' => 'create',
            'name' => 'HTTP inbox routing',
            'is_active' => true,
            'priority' => 10,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
            'due_hours' => 48,
        ]);

        \App\Domain\Workflow\WorkflowApprover::create([
            'workflow_definition_id' => $definition->id,
            'level' => 1,
            'approver_type' => 'role',
            'role_id' => $approverRole->id,
            'is_required' => true,
            'position' => 0,
        ]);

        $request = app(WorkflowEngine::class)->submit([
            'entity_type' => 'sales_order',
            'entity_id' => 900,
            'action' => 'create',
            'subject' => 'SO-900',
            'branch_id' => $this->defaultBranch()->id,
            'submitted_by' => $admin,
            'snapshot' => ['total' => 4200],
            'amount' => 4200.0,
            'currency' => 'BDT',
        ]);

        return [$admin, $approver, $request];
    }

    public function test_inbox_lists_pending_requests_for_a_permitted_viewer(): void
    {
        [$admin, , $request] = $this->makeApproval();

        $viewerRole = $this->roleWith(['portal.erp.access', 'approvals.view']);
        $viewer = $this->makeUser();
        $viewer->roles()->attach($viewerRole->id);

        $this->actingAs($viewer)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('SO-900');

        // The request detail page renders the real record (bound model).
        $this->actingAs($admin)
            ->get(route('approvals.show', $request))
            ->assertOk()
            ->assertSee('SO-900');
    }

    public function test_inbox_is_denied_without_the_view_permission(): void
    {
        $this->bootInstance();

        $role = $this->roleWith(['portal.erp.access']); // no approvals.view
        $user = $this->makeUser();
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get(route('approvals.index'))->assertForbidden();
        $this->actingAs($user)->get('/app/approvals/1')->assertForbidden();
    }

    public function test_approve_action_runs_through_the_engine(): void
    {
        [, $approver, $request] = $this->makeApproval();

        $this->from(route('approvals.show', $request))
            ->actingAs($approver)
            ->post(route('approvals.approve', $request))
            ->assertRedirect(route('approvals.show', $request))
            ->assertSessionHas('status', 'Request approved.');

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->decided_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'approval.approve']);
    }

    public function test_reject_requires_a_mandatory_comment(): void
    {
        [, $approver, $request] = $this->makeApproval();

        // Without a comment the decision is refused before any state change.
        $this->from(route('approvals.show', $request))
            ->actingAs($approver)
            ->post(route('approvals.reject', $request))
            ->assertSessionHasErrors('comment');

        $this->assertSame('pending', $request->fresh()->status);

        // With reasoning it goes through.
        $this->from(route('approvals.show', $request))
            ->actingAs($approver)
            ->post(route('approvals.reject', $request), ['comment' => 'Credit limit exceeded.'])
            ->assertRedirect(route('approvals.show', $request))
            ->assertSessionHas('status', 'Request rejected.');

        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_decide_permission_without_step_authority_is_refused(): void
    {
        [, , $request] = $this->makeApproval();

        // Holds approvals.decide, but is nobody's approver → 403 from the engine.
        $role = $this->roleWith(['portal.erp.access', 'approvals.view', 'approvals.decide']);
        $impostor = $this->makeUser();
        $impostor->roles()->attach($role->id);

        $this->actingAs($impostor)
            ->post(route('approvals.approve', $request))
            ->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_even_a_super_admin_cannot_approve_their_own_request(): void
    {
        [$admin, , $request] = $this->makeApproval();

        // Super admin passes every permission gate — the engine's frozen
        // self-approval prohibition still stops them.
        $this->actingAs($admin)
            ->post(route('approvals.approve', $request))
            ->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_comment_and_cancel_endpoints_record_real_decisions(): void
    {
        [$admin, $approver, $request] = $this->makeApproval();

        $this->from(route('approvals.show', $request))
            ->actingAs($approver)
            ->post(route('approvals.comment', $request), ['comment' => 'Awaiting updated PI.'])
            ->assertSessionHas('status', 'Comment added.');

        $this->assertSame(1, (int) \DB::table('approval_actions')
            ->where('approval_request_id', $request->id)
            ->where('action', 'comment')
            ->count());

        // The requester (super admin) cancels their own pending request.
        $this->actingAs($admin)
            ->post(route('approvals.cancel', $request))
            ->assertSessionHas('status', 'Request cancelled.');

        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertTrue(
            ApprovalRequest::query()->find($request->id)->steps()
                ->where('status', 'pending')->count() === 0,
        );
    }

    public function test_comment_endpoint_requires_the_comment_permission(): void
    {
        $this->bootInstance();

        $role = $this->roleWith(['portal.erp.access', 'approvals.view']);
        $user = $this->makeUser();
        $user->roles()->attach($role->id);

        $this->actingAs($user)
            ->post('/app/approvals/1/comment', ['comment' => 'Hello'])
            ->assertForbidden();
    }
}
