<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Sales\Actions\CommitPosSale;
use App\Domain\Sales\Actions\CreateCoupon;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\Coupon;
use App\Domain\Sales\CouponUsage;
use App\Domain\Sales\Quotation;
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
 * Coupons (02-101/02-102): CRUD, server-side percent/fixed/free_shipping
 * application on quotation/order/POS, validation guards, permission gates.
 */
class CouponSalesTest extends TestCase
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
            'code' => 'CPN-1',
            'sku' => 'CPN-SKU-1',
            'name' => 'Coupon Product',
            'cost_method' => 'fifo',
            'standard_cost' => 50,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 200, 'unit_cost' => 40],
            ],
            'idempotency_suffix' => 'cpn-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__coupon-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeCoupon(array $overrides = []): Coupon
    {
        return app(CreateCoupon::class)->handle(array_merge([
            'code' => 'SAVE10',
            'type' => 'percent_off',
            'value' => 10,
        ], $overrides), $this->httpRequest());
    }

    protected function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 10, 'unit_price' => 100],
            ],
        ], $overrides);
    }

    public function test_create_coupon_validates_type_and_duplicate_code(): void
    {
        $coupon = $this->makeCoupon();
        $this->assertSame('SAVE10', $coupon->code);
        $this->assertSame('percent_off', $coupon->type);
        $this->assertSame(0, (int) $coupon->used_count);

        try {
            app(CreateCoupon::class)->handle(['code' => 'SAVE10', 'type' => 'percent_off', 'value' => 5], $this->httpRequest());
            $this->fail('Expected duplicate RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        try {
            app(CreateCoupon::class)->handle(['code' => 'BAD', 'type' => 'nope', 'value' => 10], $this->httpRequest());
            $this->fail('Expected type RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('type', $e->getMessage());
        }

        try {
            app(CreateCoupon::class)->handle(['code' => 'PCT', 'type' => 'percent_off', 'value' => 150], $this->httpRequest());
            $this->fail('Expected percent range RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('between', $e->getMessage());
        }
    }

    public function test_percent_off_coupon_reduces_quotation_grand_total_server_side(): void
    {
        $this->makeCoupon(['code' => 'PCT10', 'type' => 'percent_off', 'value' => 10]);

        $quote = app(CreateQuotation::class)->handle($this->orderPayload([
            'coupon_code' => 'pct10', // lowercase accepted, normalized
        ]), $this->httpRequest());

        $this->assertSame('PCT10', $quote->coupon_code);
        // 1000 − 10% = 900
        $this->assertEquals(100.0, (float) $quote->coupon_discount);
        $this->assertEquals(900.0, (float) $quote->grand_total);
        // discount column includes coupon (line 0 + doc/coupon 100)
        $this->assertEquals(100.0, (float) $quote->discount);

        $usage = CouponUsage::query()->where('source_type', 'quotation')->where('source_id', $quote->id)->first();
        $this->assertNotNull($usage);
        $this->assertEquals(100.0, (float) $usage->discount_amount);

        $coupon = Coupon::query()->where('code', 'PCT10')->firstOrFail();
        $this->assertSame(1, (int) $coupon->used_count);
    }

    public function test_fixed_off_and_free_shipping_on_order(): void
    {
        $this->makeCoupon(['code' => 'FIX50', 'type' => 'fixed_off', 'value' => 50]);
        $this->makeCoupon(['code' => 'FREESHIP', 'type' => 'free_shipping']);

        $order = app(CreateSalesOrder::class)->handle($this->orderPayload([
            'coupon_code' => 'FIX50',
            'shipping' => 30,
        ]), $this->httpRequest());

        // 1000 − 50 + shipping 30 = 980
        $this->assertEquals(50.0, (float) $order->coupon_discount);
        $this->assertEquals(30.0, (float) $order->shipping);
        $this->assertEquals(980.0, (float) $order->grand_total);

        $order2 = app(CreateSalesOrder::class)->handle($this->orderPayload([
            'coupon_code' => 'FREESHIP',
            'shipping' => 45,
        ]), $this->httpRequest());

        $this->assertSame('FREESHIP', $order2->coupon_code);
        $this->assertEquals(0.0, (float) $order2->coupon_discount);
        $this->assertEquals(0.0, (float) $order2->shipping); // free shipping zeros shipping
        $this->assertEquals(1000.0, (float) $order2->grand_total);
    }

    public function test_coupon_validation_guards_min_window_and_limit(): void
    {
        $this->makeCoupon(['code' => 'MINBIG', 'type' => 'percent_off', 'value' => 5, 'min_subtotal' => 5000]);
        try {
            app(CreateQuotation::class)->handle($this->orderPayload(['coupon_code' => 'MINBIG']), $this->httpRequest());
            $this->fail('Expected min subtotal RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('minimum subtotal', $e->getMessage());
        }

        $this->makeCoupon([
            'code' => 'EXPIRED',
            'type' => 'percent_off',
            'value' => 10,
            'starts_at' => now()->subDays(10)->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
        ]);
        try {
            app(CreateQuotation::class)->handle($this->orderPayload(['coupon_code' => 'EXPIRED']), $this->httpRequest());
            $this->fail('Expected expired RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }

        $this->makeCoupon(['code' => 'ONCE', 'type' => 'fixed_off', 'value' => 10, 'max_uses' => 1]);
        app(CreateSalesOrder::class)->handle($this->orderPayload(['coupon_code' => 'ONCE']), $this->httpRequest());
        try {
            app(CreateSalesOrder::class)->handle($this->orderPayload(['coupon_code' => 'ONCE']), $this->httpRequest());
            $this->fail('Expected usage limit RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('usage limit', $e->getMessage());
        }

        try {
            app(CreateQuotation::class)->handle($this->orderPayload(['coupon_code' => 'NOPE']), $this->httpRequest());
            $this->fail('Expected not found RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
    }

    public function test_pos_sale_applies_coupon_and_records_usage(): void
    {
        $this->makeCoupon(['code' => 'POSPCT', 'type' => 'percent_off', 'value' => 20]);

        $session = app(OpenPosSession::class)->handle(
            ['opening_float' => 0, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );

        $tx = app(CommitPosSale::class)->handle([
            'pos_session_id' => $session->id,
            'payment_method' => 'cash',
            'tendered' => 1000,
            'client_uuid' => 'pos-coupon-1',
            'coupon_code' => 'POSPCT',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 100],
            ],
        ], $this->httpRequest());

        // 500 − 20% = 400
        $this->assertEquals(400.0, (float) $tx->invoice->grand_total);
        $this->assertSame('POSPCT', $tx->invoice->coupon_code);
        $this->assertEquals(100.0, (float) $tx->invoice->coupon_discount);
        $this->assertEquals(400.0, (float) $tx->total);

        $this->assertSame(1, CouponUsage::query()->where('source_type', 'invoice')->count());
        $coupon = Coupon::query()->where('code', 'POSPCT')->firstOrFail();
        $this->assertSame(1, (int) $coupon->used_count);
    }

    public function test_coupon_routes_enforce_permissions(): void
    {
        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/app/sales/coupons')->assertForbidden();
        $this->actingAs($user)->post('/app/sales/coupons', ['code' => 'X', 'type' => 'percent_off', 'value' => 1])
            ->assertForbidden();

        $full = $this->roleWith([
            'portal.erp.access',
            'sales.coupons.view',
            'sales.coupons.create',
        ]);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)->get('/app/sales/coupons')->assertOk();
        $this->actingAs($user)->post('/app/sales/coupons', [
            'code' => 'HTTP10',
            'type' => 'percent_off',
            'value' => 10,
        ])->assertRedirect(route('sales.coupons.index'));

        $this->assertTrue(Coupon::query()->where('code', 'HTTP10')->exists());
    }

    public function test_structural_seeders_ship_no_fake_coupons(): void
    {
        $this->assertSame(0, Coupon::query()->count());
        $this->assertSame(0, CouponUsage::query()->count());
        $this->assertSame(0, Quotation::query()->count());
    }
}
