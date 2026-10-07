<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Sales\Actions\CancelOrder;
use App\Domain\Sales\Actions\ConfirmOrder;
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
 * 02-05 Bulk Cancel: per-order isolation with partial failure reporting,
 * reservation release per order, workflow routing honoured (definition →
 * pending approval keeps the reservation), bulk audit row, permission gates.
 */
class BulkCancelTest extends TestCase
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
            'code' => 'BKC-2',
            'sku' => 'BKC-SKU-2',
            'name' => 'Bulk Cancel Product',
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
            'idempotency_suffix' => 'bkc2-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-cancel', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeOrder(int $qty = 5, bool $confirmed = false): SalesOrder
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        if ($confirmed) {
            app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        }

        return $order->fresh();
    }

    protected function cancelWorkflow(): WorkflowDefinition
    {
        $role = $this->roleWith([]);
        $definition = WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => 'sales_order',
            'action' => 'cancel',
            'name' => 'Sales order cancel approval',
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

    public function test_bulk_cancel_cancels_orders_and_releases_reservations(): void
    {
        $first = $this->makeOrder(4, confirmed: true);
        $second = $this->makeOrder(6, confirmed: true);

        $this->assertSame(
            2,
            StockReservation::query()
                ->where('source_type', 'sales_order')
                ->where('status', 'active')
                ->count(),
        );

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.cancel'), [
                'order_ids' => [$first->id, $second->id],
                'reason' => 'Customer changed their mind',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        foreach ([$first, $second] as $order) {
            $fresh = $order->fresh();
            $this->assertSame('cancelled', $fresh->status);
            $this->assertFalse((bool) $fresh->stock_reserved);
            $this->assertSame('Customer changed their mind', $fresh->cancel_reason);
        }

        $this->assertSame(
            0,
            StockReservation::query()
                ->where('source_type', 'sales_order')
                ->where('status', 'active')
                ->count(),
        );
        $this->assertEquals(
            0.0,
            (float) StockBalance::query()
                ->where('product_id', $this->product->id)
                ->value('reserved'),
        );

        $status = (string) session('status');
        $this->assertStringContainsString('2 cancelled', $status);
        $this->assertStringContainsString('0 failed', $status);

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'sales.order_bulk_cancel')->count(),
        );
    }

    public function test_bulk_cancel_reports_partial_failures(): void
    {
        $good = $this->makeOrder(3);
        $alreadyCancelled = $this->makeOrder(3);
        app(CancelOrder::class)
            ->handle($alreadyCancelled, 'cancelled earlier', $this->httpRequest());

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.cancel'), [
                'order_ids' => [$good->id, $alreadyCancelled->id],
                'reason' => 'Bulk run',
            ])
            ->assertRedirect()
            ->assertSessionHas('bulk_messages');

        $firstRun = (string) session('status');
        $this->assertStringContainsString('1 cancelled', $firstRun);
        $this->assertStringContainsString('1 failed', $firstRun);
        $this->assertSame('cancelled', $good->fresh()->status);

        // Second run over the same selection: both are already cancelled
        // and a terminal order can never be cancelled again — a run where
        // every order failed surfaces in the error bag, not the status flash.
        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.cancel'), [
                'order_ids' => [$good->id, $alreadyCancelled->id],
                'reason' => 'Bulk run again',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $secondRun = $this->allFlashedErrors();
        $this->assertStringContainsString('0 cancelled', $secondRun);
        $this->assertStringContainsString('2 failed', $secondRun);
        $this->assertSame('cancelled', $good->fresh()->status);
        $this->assertSame('cancelled', $alreadyCancelled->fresh()->status);
    }

    public function test_bulk_cancel_honours_workflow_definition_until_approval(): void
    {
        $this->cancelWorkflow();

        $order = $this->makeOrder(5, confirmed: true);
        $reservationId = StockReservation::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->value('id');
        $this->assertNotNull($reservationId);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.cancel'), [
                'order_ids' => [$order->id],
                'reason' => 'Needs approval',
            ])
            ->assertRedirect();

        $fresh = $order->fresh();
        $this->assertSame('confirmed', $fresh->status);
        $this->assertTrue((bool) $fresh->stock_reserved);
        $this->assertSame(
            1,
            ApprovalRequest::query()
                ->where('entity_type', 'sales_order')
                ->where('entity_id', $order->id)
                ->where('action', 'cancel')
                ->where('status', 'pending')
                ->count(),
        );
        $this->assertNotNull(
            StockReservation::query()->whereKey($reservationId)->first(),
        );
        $this->assertStringContainsString('1 awaiting approval', (string) session('status'));

        $approver = $this->makeUser(['name' => 'Cancel Approver']);
        $approver->roles()->sync(
            ApprovalRequest::query()
                ->where('entity_type', 'sales_order')
                ->where('entity_id', $order->id)
                ->where('action', 'cancel')
                ->firstOrFail()
                ->definition->approvers->pluck('role_id')
                ->filter()
                ->all(),
        );
        $requestId = ApprovalRequest::query()
            ->where('entity_type', 'sales_order')
            ->where('entity_id', $order->id)
            ->where('action', 'cancel')
            ->firstOrFail()
            ->id;
        app(WorkflowEngine::class)->approve($requestId, $approver, 'agreed');

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.cancel'), [
                'order_ids' => [$order->id],
                'reason' => 'Needs approval',
            ])
            ->assertRedirect();

        $approved = $order->fresh();
        $this->assertSame('cancelled', $approved->status);
        $this->assertFalse((bool) $approved->stock_reserved);
        $this->assertSame(
            0,
            StockReservation::query()
                ->where('source_type', 'sales_order')
                ->where('status', 'active')
                ->count(),
        );
    }

    public function test_bulk_cancel_route_requires_permission(): void
    {
        $order = $this->makeOrder(2);

        $denied = $this->makeUser();
        $denied->roles()->attach($this->roleWith(['portal.erp.access'])->id);

        $this->actingAs($denied)
            ->post(route('sales.orders.bulk.cancel'), ['order_ids' => [$order->id]])
            ->assertForbidden();

        $this->assertSame('pending', $order->fresh()->status);

        $allowed = $this->makeUser();
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.orders.cancel',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->post(route('sales.orders.bulk.cancel'), [
                'order_ids' => [$order->id],
                'reason' => 'no longer needed',
            ])
            ->assertRedirect();

        $this->assertSame('cancelled', $order->fresh()->status);
    }
}
