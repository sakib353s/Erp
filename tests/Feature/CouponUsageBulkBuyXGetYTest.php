<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Sales\Actions\BulkGenerateCoupons;
use App\Domain\Sales\Actions\CreateCoupon;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Coupon;
use App\Domain\Sales\CouponUsage;
use App\Domain\Sales\Queries\CouponUsageQuery;
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
 * Coupon usage + bulk generation + Buy X Get Y (02-102 tail, 02-103, 02-104).
 */
class CouponUsageBulkBuyXGetYTest extends TestCase
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
            'code' => 'BXY-1',
            'sku' => 'BXY-SKU-1',
            'name' => 'Buy X Product',
            'cost_method' => 'fifo',
            'standard_cost' => 50,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 500, 'unit_cost' => 40],
            ],
            'idempotency_suffix' => 'bxy-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__coupon-usage-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
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

    public function test_buy_x_get_y_coupon_frees_cheapest_units_on_order(): void
    {
        app(CreateCoupon::class)->handle([
            'code' => 'B2G1',
            'type' => 'buy_x_get_y',
            'value' => 100,
            'buy_qty' => 2,
            'get_qty' => 1,
        ], $this->httpRequest());

        // 10 units @100: floor(10/3)*1 = 3 free → discount 300
        $order = app(CreateSalesOrder::class)->handle(
            $this->orderPayload(['coupon_code' => 'B2G1']),
            $this->httpRequest(),
        );

        $this->assertSame('B2G1', $order->coupon_code);
        $this->assertEquals(300.0, (float) $order->coupon_discount);
        $this->assertEquals(700.0, (float) $order->grand_total);
        $this->assertSame(1, CouponUsage::query()->where('source_type', 'sales_order')->count());
    }

    public function test_buy_x_get_y_requires_buy_and_get_qty(): void
    {
        try {
            app(CreateCoupon::class)->handle([
                'code' => 'BADBXY',
                'type' => 'buy_x_get_y',
                'value' => 100,
            ], $this->httpRequest());
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('buy_qty', $e->getMessage());
        }
    }

    public function test_coupon_usage_query_reports_real_redemptions_and_attributed_revenue(): void
    {
        app(CreateCoupon::class)->handle([
            'code' => 'USE10',
            'type' => 'percent_off',
            'value' => 10,
        ], $this->httpRequest());

        $quote = app(CreateQuotation::class)->handle(
            $this->orderPayload(['coupon_code' => 'USE10']),
            $this->httpRequest(),
        );

        $report = app(CouponUsageQuery::class)->forPeriod(
            (int) $this->admin->company_id,
            'monthly',
        );

        $this->assertSame(1, $report['totals']['redemptions']);
        $this->assertEquals(100.0, $report['totals']['discount']);
        // quotation is not an invoice — no attributed revenue yet
        $this->assertEquals(0.0, $report['totals']['attributed_revenue']);
        $this->assertSame(1, $report['totals']['coupons_used']);
        $this->assertNotNull($quote->coupon_code);
        $this->assertStringContainsString('coupon_usages', $report['method']);
    }

    public function test_bulk_generate_coupons_unique_codes_with_entropy(): void
    {
        $result = app(BulkGenerateCoupons::class)->handle([
            'count' => 25,
            'prefix' => 'BD',
            'type' => 'fixed_off',
            'value' => 5,
            'max_uses' => 1,
        ], $this->httpRequest());

        $this->assertSame(25, $result['created']);
        $this->assertCount(25, $result['codes']);
        $this->assertCount(25, array_unique($result['codes']));

        foreach ($result['codes'] as $code) {
            $this->assertStringStartsWith('BD', $code);
            $this->assertGreaterThanOrEqual(14, strlen($code));
            $this->assertMatchesRegularExpression('/^BD[0-9a-f]{12}$/', $code);
        }

        $this->assertSame(25, Coupon::query()->count());
    }

    public function test_bulk_generate_rejects_bad_count_and_type(): void
    {
        try {
            app(BulkGenerateCoupons::class)->handle(['count' => 0, 'type' => 'percent_off'], $this->httpRequest());
            $this->fail('Expected count RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('between', $e->getMessage());
        }

        try {
            app(BulkGenerateCoupons::class)->handle(['count' => 2, 'type' => 'nope'], $this->httpRequest());
            $this->fail('Expected type RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unsupported', $e->getMessage());
        }
    }

    public function test_usage_and_bulk_routes_enforce_permissions(): void
    {
        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/app/sales/coupons/usage')->assertForbidden();
        $this->actingAs($user)->post('/app/sales/coupons/bulk-generate', [
            'count' => 1,
            'type' => 'percent_off',
            'value' => 5,
        ])->assertForbidden();

        $full = $this->roleWith([
            'portal.erp.access',
            'sales.coupons.view',
            'sales.coupons.create',
            'sales.coupons.bulk',
        ]);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)->get('/app/sales/coupons/usage')->assertOk();
        $this->actingAs($user)->get('/app/sales/coupons/usage?view=analytics')->assertOk();
        $this->actingAs($user)->post('/app/sales/coupons/bulk-generate', [
            'count' => 2,
            'type' => 'percent_off',
            'value' => 5,
        ])->assertRedirect(route('sales.coupons.index'));

        $this->assertSame(2, Coupon::query()->count());
    }

    public function test_structural_seeders_ship_no_fake_coupon_usages(): void
    {
        $this->assertSame(0, Coupon::query()->count());
        $this->assertSame(0, CouponUsage::query()->count());
    }
}
