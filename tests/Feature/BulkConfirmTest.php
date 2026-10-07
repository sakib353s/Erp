<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Sales\Actions\CancelOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\StockReservation;
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
 * 02-04 Bulk Confirm: per-order isolation with partial failure reporting,
 * workflow routing honoured (definition → pending approval, approved or
 * bypass → confirm + reservation), bulk audit row, permission gates.
 */
class BulkConfirmTest extends TestCase
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
            'code' => 'BKC-1',
            'sku' => 'BKC-SKU-1',
            'name' => 'Bulk Confirm Product',
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
            'idempotency_suffix' => 'bkc-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-confirm', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeOrder(int $qty = 5): SalesOrder
    {
        return app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
    }

    /** @return array<int, int> */
    protected function confirmViaHttp(array $orderIds): array
    {
        $response = $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.confirm'), ['order_ids' => $orderIds]);

        $response->assertRedirect();

        return $orderIds;
    }

    protected function confirmWorkflow(): WorkflowDefinition
    {
        $role = $this->roleWith([]);
        $definition = WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => 'sales_order',
            'action' => 'confirm',
            'name' => 'Sales order confirm approval',
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

        return $definition;
    }

    public function test_bulk_confirm_confirms_every_selected_order_and_reserves_stock(): void
    {
        $first = $this->makeOrder(5);
        $second = $this->makeOrder(3);

        $this->confirmViaHttp([$first->id, $second->id]);

        $this->assertSame('confirmed', $first->fresh()->status);
        $this->assertSame('confirmed', $second->fresh()->status);
        $this->assertTrue((bool) $first->fresh()->stock_reserved);
        $this->assertTrue((bool) $second->fresh()->stock_reserved);

        $reserved = StockReservation::query()
            ->where('source_type', 'sales_order')
            ->whereIn('source_id', [$first->id, $second->id])
            ->count();
        $this->assertSame(2, $reserved);

        $status = session('status');
        $this->assertIsString($status);
        $this->assertStringContainsString('2 confirmed', $status);
        $this->assertStringContainsString('0 failed', $status);

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'sales.order_bulk_confirm')->count(),
        );
    }

    public function test_bulk_confirm_reports_partial_failures_without_stopping_other_orders(): void
    {
        $good = $this->makeOrder(2);
        $cancelled = $this->makeOrder(2);
        app(CancelOrder::class)
            ->handle($cancelled, 'not needed anymore', $this->httpRequest());

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.confirm'), [
                'order_ids' => [$good->id, $cancelled->id, 99999999],
            ])
            ->assertRedirect()
            ->assertSessionHas('status')
            ->assertSessionHas('bulk_messages');

        $this->assertSame('confirmed', $good->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);

        $status = (string) session('status');
        $this->assertStringContainsString('3 requested', $status);
        $this->assertStringContainsString('1 confirmed', $status);
        $this->assertStringContainsString('2 failed', $status);

        $messages = implode(' | ', session('bulk_messages'));
        $this->assertStringContainsString('cannot be confirmed', $messages);
        $this->assertStringContainsString('Order not found', $messages);
    }

    public function test_bulk_confirm_honours_workflow_definition_until_approval(): void
    {
        $this->confirmWorkflow();

        $first = $this->makeOrder(4);
        $second = $this->makeOrder(4);

        $this->confirmViaHttp([$first->id, $second->id]);

        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertFalse((bool) $first->fresh()->stock_reserved);
        $this->assertSame(0, StockReservation::query()->where('source_type', 'sales_order')->count());
        $this->assertSame(
            2,
            ApprovalRequest::query()
                ->where('entity_type', 'sales_order')
                ->where('action', 'confirm')
                ->where('status', 'pending')
                ->count(),
        );

        $status = (string) session('status');
        $this->assertStringContainsString('2 awaiting approval', $status);
        $this->assertStringContainsString('0 confirmed', $status);

        // Approve one of them, then re-run bulk confirm for that order only.
        $approver = $this->makeUser(['name' => 'Bulk Approver']);
        $roleIds = ApprovalRequest::query()
            ->where('entity_type', 'sales_order')
            ->where('entity_id', $first->id)
            ->where('action', 'confirm')
            ->firstOrFail()
            ->definition->approvers->pluck('role_id')
            ->filter()
            ->all();
        $approver->roles()->sync($roleIds);

        $requestId = ApprovalRequest::query()
            ->where('entity_type', 'sales_order')
            ->where('entity_id', $first->id)
            ->where('action', 'confirm')
            ->firstOrFail()
            ->id;
        app(WorkflowEngine::class)->approve($requestId, $approver, 'ok to confirm');

        $this->confirmViaHttp([$first->id]);

        $this->assertSame('confirmed', $first->fresh()->status);
        $this->assertTrue((bool) $first->fresh()->stock_reserved);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(
            1,
            StockReservation::query()
                ->where('source_type', 'sales_order')
                ->where('source_id', $first->id)
                ->count(),
        );
    }

    public function test_bulk_confirm_route_requires_permission(): void
    {
        $order = $this->makeOrder(1);

        $denied = $this->makeUser();
        $denied->roles()->attach($this->roleWith(['portal.erp.access'])->id);

        $this->actingAs($denied)
            ->post(route('sales.orders.bulk.confirm'), ['order_ids' => [$order->id]])
            ->assertForbidden();

        $this->assertSame('pending', $order->fresh()->status);

        $allowed = $this->makeUser();
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.orders.confirm',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->post(route('sales.orders.bulk.confirm'), ['order_ids' => [$order->id]])
            ->assertRedirect();

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_bulk_confirm_requires_selection(): void
    {
        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.confirm'), [])
            ->assertRedirect()
            ->assertSessionHasErrors('order_ids');
    }
}
