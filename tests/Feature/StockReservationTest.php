<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\ReorderService;
use App\Domain\Inventory\StockBalance;
use App\Domain\Sales\Services\ReservationService;
use App\Domain\Sales\StockReservation;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §04-34 — stock reservations.
 *
 * What this pins:
 *  · a hold reduces available, never on-hand: the goods stay in stock and in the
 *    valuation, they are simply spoken for;
 *  · reserved can never be talked below zero, and a release is idempotent in the
 *    sense that the second attempt is refused rather than double-counted;
 *  · the overdue sweep gives back exactly the quantity of the holds that passed
 *    their deadline, and leaves holds without a deadline alone;
 *  · reading the desk and changing it are two permissions.
 */
class StockReservationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected ReservationService $reservations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->reservations = app(ReservationService::class);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__reservations', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function stockedProduct(string $sku, float $qty): Product
    {
        $product = app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Hold probe '.$sku,
            'cost_method' => 'wac',
            'standard_cost' => 50,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_cost' => 50]],
            'idempotency_suffix' => uniqid('hold-', true),
        ], $this->httpRequest());

        return $product;
    }

    protected function balance(Product $product): StockBalance
    {
        return StockBalance::query()
            ->where('company_id', $this->admin->company_id)
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    public function test_a_hold_reduces_available_and_never_on_hand(): void
    {
        $product = $this->stockedProduct('HOLD-A', 10);

        $this->reservations->reserve(
            $this->admin->company_id,
            $this->warehouse->id,
            'sales_order',
            5001,
            [['product_id' => $product->id, 'qty' => 4]],
        );

        $balance = $this->balance($product);

        $this->assertSame('10.0000', (string) $balance->on_hand, 'the goods are still on the shelf');
        $this->assertSame('4.0000', (string) $balance->reserved);

        $hold = StockReservation::query()->where('source_id', 5001)->firstOrFail();
        $this->assertSame(StockReservation::STATUS_ACTIVE, $hold->status);
        $this->assertSame('Sales order', $hold->sourceKind());
    }

    public function test_reserving_more_than_available_is_refused_with_the_numbers(): void
    {
        $product = $this->stockedProduct('HOLD-B', 5);

        $this->reservations->reserve(
            $this->admin->company_id,
            $this->warehouse->id,
            'sales_order',
            5002,
            [['product_id' => $product->id, 'qty' => 4]],
        );

        try {
            $this->reservations->reserve(
                $this->admin->company_id,
                $this->warehouse->id,
                'pos_hold',
                5003,
                [['product_id' => $product->id, 'qty' => 2]],
            );
            $this->fail('Expected the second hold to be refused: only 1 is available.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('available 1', $e->getMessage());
            $this->assertStringContainsString('HOLD-B', $e->getMessage());
        }

        // The refused hold left nothing behind.
        $this->assertSame(0, StockReservation::query()->where('source_id', 5003)->count());
        $this->assertSame('4.0000', (string) $this->balance($product)->reserved);
    }

    public function test_manual_release_returns_the_quantity_and_is_audited_once(): void
    {
        $product = $this->stockedProduct('HOLD-C', 8);

        $this->reservations->reserve(
            $this->admin->company_id,
            $this->warehouse->id,
            'sales_order',
            5004,
            [['product_id' => $product->id, 'qty' => 3]],
        );

        $hold = StockReservation::query()->where('source_id', 5004)->firstOrFail();
        $this->reservations->releaseOne($hold, $this->admin->id, 'Order cancelled by the customer.');

        $this->assertSame('0.0000', (string) $this->balance($product)->reserved);

        $hold->refresh();
        $this->assertSame(StockReservation::STATUS_RELEASED, $hold->status);

        $audit = \App\Domain\Audit\AuditEvent::query()
            ->where('action', 'inventory.reservation_released')
            ->where('entity_id', $hold->id)
            ->count();
        $this->assertSame(1, $audit, 'the release is on the audit chain');

        // Releasing it a second time must be refused, not silently double-counted.
        $this->expectException(\RuntimeException::class);
        $this->reservations->releaseOne($hold, $this->admin->id, 'again');
    }

    public function test_the_overdue_sweep_frees_exactly_the_expired_holds(): void
    {
        $product = $this->stockedProduct('HOLD-D', 20);

        $this->reservations->reserve(
            $this->admin->company_id,
            $this->warehouse->id,
            'sales_order',
            5005,
            [['product_id' => $product->id, 'qty' => 6]],
            now()->subHour(),
        );

        $this->reservations->reserve(
            $this->admin->company_id,
            $this->warehouse->id,
            'pos_hold',
            5006,
            [['product_id' => $product->id, 'qty' => 5]],
            now()->addDay(),
        );

        // A hold with no deadline is deliberately left alone — nobody said when
        // it should lapse, so guessing would be inventing policy.
        $this->reservations->reserve(
            $this->admin->company_id,
            $this->warehouse->id,
            'layaway',
            5007,
            [['product_id' => $product->id, 'qty' => 2]],
        );

        $this->assertSame('13.0000', (string) $this->balance($product)->reserved);

        $expired = $this->reservations->expireDue($this->admin->company_id, $this->admin->id);

        $this->assertSame(1, $expired);
        $this->assertSame('7.0000', (string) $this->balance($product)->reserved, 'only the overdue 6 came back');
        $this->assertSame(
            StockReservation::STATUS_EXPIRED,
            StockReservation::query()->where('source_id', 5005)->value('status'),
        );

        // Sweeping again is a no-op, not a second refund of the same hold.
        $this->assertSame(0, $this->reservations->expireDue($this->admin->company_id, $this->admin->id));
        $this->assertSame('7.0000', (string) $this->balance($product)->reserved);
    }

    public function test_the_screen_shows_real_holds_and_reports_the_desk_totals(): void
    {
        $product = $this->stockedProduct('HOLD-E', 9);

        $this->reservations->reserve(
            $this->admin->company_id,
            $this->warehouse->id,
            'sales_order',
            5008,
            [['product_id' => $product->id, 'qty' => 4]],
            now()->subDay(),
        );

        $counts = $this->reservations->counts($this->admin->company_id);

        $this->assertSame(1, $counts['open']);
        $this->assertSame(1, $counts['overdue']);
        $this->assertSame(4.0, $counts['qty_held']);

        $this->actingAs($this->admin)
            ->get('/app/inventory/reservations')
            ->assertOk()
            ->assertSee('HOLD-E');
    }

    public function test_reading_the_desk_and_changing_it_are_two_permissions(): void
    {
        $product = $this->stockedProduct('HOLD-F', 5);
        $this->reservations->reserve(
            $this->admin->company_id,
            $this->warehouse->id,
            'sales_order',
            5009,
            [['product_id' => $product->id, 'qty' => 1]],
        );

        $hold = StockReservation::query()->where('source_id', 5009)->firstOrFail();

        $viewer = $this->makeUser();
        $viewer->roles()->attach($this->roleWith(['portal.erp.access', 'inventory.reservations.view'])->id);

        $this->actingAs($viewer)->get('/app/inventory/reservations')->assertOk();
        $this->actingAs($viewer)
            ->post('/app/inventory/reservations/'.$hold->id.'/release', ['reason' => 'not my call'])
            ->assertForbidden();
        $this->actingAs($viewer)->post('/app/inventory/reservations/expire')->assertForbidden();

        $outsider = $this->makeUser();
        $outsider->roles()->attach($this->roleWith(['portal.erp.access'])->id);
        $this->actingAs($outsider)->get('/app/inventory/reservations')->assertForbidden();

        $keeper = $this->makeUser();
        $keeper->roles()->attach($this->roleWith([
            'portal.erp.access', 'inventory.reservations.view', 'inventory.reservations.manage',
        ])->id);

        $this->actingAs($keeper)->post('/app/inventory/reservations/'.$hold->id.'/release', [
            'reason' => 'Customer never collected the goods.',
        ])->assertRedirect();

        $this->assertSame(StockReservation::STATUS_RELEASED, $hold->refresh()->status);

        // A reason is mandatory: a release nobody can explain is refused.
        $this->actingAs($keeper)
            ->post('/app/inventory/reservations/'.$hold->id.'/release', [])
            ->assertSessionHasErrors('reason');
    }
}
