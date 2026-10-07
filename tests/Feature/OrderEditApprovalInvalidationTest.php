<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\SalesOrder;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\WorkflowApprover;
use App\Domain\Workflow\WorkflowDefinition;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-03 approval invalidation: a material change to an order awaiting
 * approval cancels the frozen snapshot through the generic engine, while an
 * edit that changes nothing leaves the request pending.
 */
class OrderEditApprovalInvalidationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(SalesCoreSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'APV-1',
            'sku' => 'APV-SKU-1',
            'name' => 'Approval Invalidation Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 100, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'apv-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(?User $user = null): Request
    {
        $request = Request::create('/__order-approval-edit', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $user ?? $this->admin);

        return $request;
    }

    protected function makeOrder(array $overrides = []): SalesOrder
    {
        return app(CreateSalesOrder::class)->handle(array_merge([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $overrides), $this->httpRequest());
    }

    protected function seedConfirmWorkflow(): void
    {
        $role = $this->roleWith([]);
        $definition = WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => 'sales_order',
            'action' => 'confirm',
            'name' => 'Order confirm approval',
            'is_active' => true,
            'priority' => 10,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
        ]);
        WorkflowApprover::create([
            'workflow_definition_id' => $definition->id,
            'approver_type' => 'role',
            'role_id' => $role->id,
            'level' => 1,
            'is_required' => true,
            'position' => 0,
        ]);
    }

    protected function submitApproval(SalesOrder $order, User $submitter): ApprovalRequest
    {
        $approval = app(WorkflowEngine::class)->submit([
            'entity_type' => 'sales_order',
            'entity_id' => $order->id,
            'action' => 'confirm',
            'subject' => $order->order_no,
            'branch_id' => $order->branch_id,
            'submitted_by' => $submitter,
            'snapshot' => ['grand_total' => (float) $order->grand_total],
            'amount' => (float) $order->grand_total,
            'currency' => 'BDT',
            'reference_no' => $order->order_no,
        ]);

        $this->assertNotNull($approval);

        return $approval;
    }

    public function test_material_change_cancels_the_pending_approval_snapshot(): void
    {
        $this->seedConfirmWorkflow();
        $order = $this->makeOrder();
        $approval = $this->submitApproval($order, $this->admin);

        $response = $this->actingAs($this->admin)->put(
            route('sales.orders.update', $order),
            [
                'warehouse_id' => $this->warehouse->id,
                'order_date' => $order->order_date->toDateString(),
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 9, 'unit_price' => 150],
                ],
            ],
        );
        $response->assertRedirect(route('sales.orders.show', $order));

        $this->assertSame('cancelled', $approval->fresh()->status);
        $this->assertEquals(1350.0, (float) $order->fresh()->grand_total);

        $event = AuditEvent::query()
            ->where('action', 'sales.order_updated')
            ->where('entity_id', $order->id)
            ->firstOrFail();
        $this->assertSame('approval_invalidated', $event->result);
        $this->assertSame(1, (int) ($event->after['approvals_invalidated'] ?? 0));
    }

    public function test_identical_edit_leaves_the_approval_pending(): void
    {
        $this->seedConfirmWorkflow();
        $order = $this->makeOrder();
        $approval = $this->submitApproval($order, $this->admin);

        $this->actingAs($this->admin)->put(
            route('sales.orders.update', $order),
            [
                'warehouse_id' => $this->warehouse->id,
                'order_date' => $order->order_date->toDateString(),
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
                ],
            ],
        )->assertRedirect(route('sales.orders.show', $order));

        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertEquals(750.0, (float) $order->fresh()->grand_total);

        $event = AuditEvent::query()
            ->where('action', 'sales.order_updated')
            ->where('entity_id', $order->id)
            ->firstOrFail();
        $this->assertSame('success', $event->result);
    }

    public function test_editor_without_authority_cannot_change_an_order_awaiting_approval(): void
    {
        $this->seedConfirmWorkflow();
        $order = $this->makeOrder();

        // Submitted by the super admin; the editor below is neither the
        // requester nor an approver, so the engine refuses the cancellation
        // and the edit must not go through.
        $approval = $this->submitApproval($order, $this->admin);

        $editor = $this->makeUser(['name' => 'Order Editor']);
        $editor->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.orders.view',
            'sales.orders.edit',
        ])->id);
        app(PermissionCatalog::class)->invalidate($editor);

        $this->actingAs($editor)->put(
            route('sales.orders.update', $order),
            [
                'warehouse_id' => $this->warehouse->id,
                'order_date' => $order->order_date->toDateString(),
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 42, 'unit_price' => 150],
                ],
            ],
        )->assertRedirect()->assertSessionHasErrors('order');

        $this->assertStringContainsString(
            'awaiting approval',
            $this->allFlashedErrors(),
        );
        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertEquals(750.0, (float) $order->fresh()->grand_total);
        $this->assertEquals(5.0, (float) $order->fresh()->lines()->first()->qty);
    }

    public function test_rejection_restores_editability_without_an_open_request(): void
    {
        $this->seedConfirmWorkflow();
        $order = $this->makeOrder();
        $approval = $this->submitApproval($order, $this->admin);

        app(WorkflowEngine::class)->cancel($approval->id, $this->admin, 'withdrawn');

        $this->assertSame('cancelled', $approval->fresh()->status);

        $this->actingAs($this->admin)->put(
            route('sales.orders.update', $order),
            [
                'warehouse_id' => $this->warehouse->id,
                'order_date' => $order->order_date->toDateString(),
                'lines' => [
                    ['product_id' => $this->product->id, 'qty' => 7, 'unit_price' => 150],
                ],
            ],
        )->assertRedirect(route('sales.orders.show', $order));

        $this->assertEquals(1050.0, (float) $order->fresh()->grand_total);
    }
}
