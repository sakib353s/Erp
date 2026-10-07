<?php

namespace Tests\Feature;

use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Courier;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\SalesOrder;
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
 * 02-06 CourierNotConfiguredTruthfulTest: the courier adapter never
 * fabricates a dispatch. An unconfigured courier (or one whose
 * integration is switched off) yields a local `pending_dispatch`
 * shipment with no external reference and no dispatched_at — the
 * operator is told exactly that. Only configured + enabled couriers
 * produce a dispatch ref. 02-91: the courier-partner config screen
 * reports the same truth — status badges, integration state and a
 * write-only webhook secret — with no fake connection claim.
 */
class CourierNotConfiguredTruthfulTest extends TestCase
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
            'code' => 'TRC-1',
            'sku' => 'TRC-SKU-1',
            'name' => 'Courier Truth Product',
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
            'idempotency_suffix' => 'cnt-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__courier-truth', 'POST', [], [], [], [
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

    protected function makeCourier(array $overrides = []): Courier
    {
        return Courier::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'CNT-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Truth Courier',
            'configuration_status' => 'not_configured',
            'integration_enabled' => false,
            'is_active' => true,
        ], $overrides));
    }

    /** @return array{status: string, message: string} */
    protected function assign(Courier $courier, array $orderIds, array $extra = []): array
    {
        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.assign-courier'), array_merge([
                'order_ids' => $orderIds,
                'courier_id' => $courier->id,
            ], $extra))
            ->assertRedirect()
            ->assertSessionHas('status');

        return [
            'status' => (string) session('status'),
            'message' => implode(' ', (array) session('bulk_messages')),
        ];
    }

    public function test_unconfigured_courier_stays_local_without_any_reference(): void
    {
        $courier = $this->makeCourier();
        $order = $this->makeOrder(3);

        $this->assign($courier, [$order->id]);

        $shipment = Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(Shipment::STATUS_PENDING_DISPATCH, $shipment->status);
        $this->assertNull($shipment->external_ref);
        $this->assertNull($shipment->dispatched_at);

        // No shipment anywhere may claim a fabricated dispatch.
        $this->assertSame(
            0,
            Shipment::query()->whereNotNull('external_ref')->count(),
        );
        $this->assertSame(
            0,
            Shipment::query()->whereNotNull('dispatched_at')->count(),
        );
    }

    public function test_the_operator_is_told_the_courier_is_not_configured(): void
    {
        $courier = $this->makeCourier();
        $first = $this->makeOrder(3);
        $second = $this->makeOrder(3);

        $outcome = $this->assign($courier, [$first->id, $second->id]);

        // Local assignment still counts as assigned (not failed) — and no
        // shipment may carry a dispatch reference.
        $this->assertStringContainsString('2 assigned', $outcome['status']);
        $this->assertStringContainsString('0 failed', $outcome['status']);
        $this->assertSame(2, Shipment::query()->count());
        $this->assertSame(0, Shipment::query()->whereNotNull('external_ref')->count());
    }

    public function test_configured_status_with_integration_disabled_is_still_truthful(): void
    {
        $courier = $this->makeCourier([
            'configuration_status' => 'configured',
            'integration_enabled' => false,
        ]);
        $order = $this->makeOrder(3);

        $this->assign($courier, [$order->id]);

        $shipment = Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(Shipment::STATUS_PENDING_DISPATCH, $shipment->status);
        $this->assertNull($shipment->external_ref);
        $this->assertNull($shipment->dispatched_at);
        $this->assertSame(0, Shipment::query()->whereNotNull('external_ref')->count());
    }

    public function test_only_configured_and_enabled_couriers_get_a_dispatch_ref(): void
    {
        $courier = $this->makeCourier([
            'code' => 'PKDX',
            'configuration_status' => 'configured',
            'integration_enabled' => true,
        ]);
        $order = $this->makeOrder(3);

        $this->assign($courier, [$order->id]);

        $shipment = Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(Shipment::STATUS_ASSIGNED, $shipment->status);
        $this->assertSame('PKDX-'.strtoupper($order->order_no), $shipment->external_ref);
        $this->assertNotNull($shipment->dispatched_at);
    }

    public function test_rider_is_optional_and_the_screen_labels_the_trust_state(): void
    {
        $this->makeCourier(['code' => 'STEAD', 'name' => 'Steadfast']);
        $courier = $this->makeCourier();
        $order = $this->makeOrder(3);

        $this->assign($courier, [$order->id]);

        $shipment = Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(0, $shipment->riderAssignments()->count());

        $this->actingAs($this->admin)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertSee('(not configured)')
            ->assertSee('STEAD — Steadfast');
    }

    public function test_truth_state_is_gated_by_the_assign_permission(): void
    {
        $courier = $this->makeCourier();
        $order = $this->makeOrder(3);

        $denied = $this->makeUser(['name' => 'No Dispatch Rights']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->post(route('sales.orders.bulk.assign-courier'), [
                'order_ids' => [$order->id],
                'courier_id' => $courier->id,
            ])
            ->assertForbidden();

        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_config_screen_reports_configuration_state_truthfully(): void
    {
        $this->makeCourier([
            'code' => 'ALFA',
            'name' => 'Alpha Unconfigured',
            'configuration_status' => 'not_configured',
            'integration_enabled' => false,
        ]);
        $this->makeCourier([
            'code' => 'BETA',
            'name' => 'Beta Configured',
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'webhook_secret' => 'screen-secret-99',
        ]);

        $this->actingAs($this->admin)
            ->get(route('couriers.index'))
            ->assertOk()
            ->assertSee('Alpha Unconfigured')
            ->assertSee('Beta Configured')
            ->assertSee('not configured')
            ->assertSee('set</span>', false)
            ->assertDontSee('screen-secret-99')
            ->assertDontSee('connected');
    }

    public function test_config_screen_is_gated_by_sales_delivery_configure(): void
    {
        $denied = $this->makeUser(['name' => 'No Configure Rights']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)->get(route('couriers.index'))->assertForbidden();
        $this->actingAs($denied)->post(route('couriers.store'), [
            'code' => 'NOGO',
            'name' => 'Nope',
            'configuration_status' => 'not_configured',
        ])->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Configure Rights']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.delivery.configure'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)->get(route('couriers.index'))->assertOk();
    }
}
