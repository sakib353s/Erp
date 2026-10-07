<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Reporting\PromotionReport;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateCoupon;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreatePromotion;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Coupon;
use App\Domain\Sales\CouponUsage;
use App\Domain\Sales\Promotion;
use App\Domain\Sales\PromotionUsage;
use App\Domain\Sales\Queries\FlashSaleStatus;
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
 * Promotions (02-105…02-107): CRUD, window/branch/priority engine,
 * flash countdown from DB timestamps, promotion report attribution.
 */
class PromotionSalesTest extends TestCase
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
            'code' => 'PRM-1',
            'sku' => 'PRM-SKU-1',
            'name' => 'Promo Product',
            'cost_method' => 'fifo',
            'standard_cost' => 50,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 300, 'unit_cost' => 40],
            ],
            'idempotency_suffix' => 'prm-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__promotion-test', 'POST', [], [], [], [
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

    protected function makePromotion(array $overrides = []): Promotion
    {
        return app(CreatePromotion::class)->handle(array_merge([
            'name' => 'Season 10',
            'type' => 'percent_off',
            'kind' => 'standard',
            'value' => 10,
        ], $overrides), $this->httpRequest());
    }

    public function test_create_promotion_validates_type_window_and_duplicate_code(): void
    {
        $promotion = $this->makePromotion(['code' => 'PRM10']);
        $this->assertSame('PRM10', $promotion->code);
        $this->assertSame('percent_off', $promotion->type);

        try {
            $this->makePromotion(['code' => 'PRM10', 'name' => 'Dup']);
            $this->fail('Expected duplicate RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        try {
            $this->makePromotion(['type' => 'nope', 'name' => 'Bad']);
            $this->fail('Expected type RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('type', $e->getMessage());
        }

        try {
            $this->makePromotion([
                'name' => 'Bad window',
                'starts_at' => now()->addDay()->toDateString(),
                'ends_at' => now()->toDateString(),
            ]);
            $this->fail('Expected window RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('after start', $e->getMessage());
        }
    }

    public function test_promotion_engine_applies_percent_off_with_priority_and_window(): void
    {
        $low = $this->makePromotion(['name' => 'Low', 'value' => 5, 'priority' => 10]);
        $high = $this->makePromotion(['name' => 'High', 'value' => 20, 'priority' => 999]);

        // high priority wins: 1000 − 20% = 800
        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        $this->assertEquals(800.0, (float) $order->grand_total);
        $this->assertEquals(200.0, (float) $order->discount);

        $usage = PromotionUsage::query()
            ->where('source_type', 'sales_order')
            ->where('source_id', $order->id)
            ->firstOrFail();
        $this->assertSame($high->id, (int) $usage->promotion_id);
        $this->assertEquals(200.0, (float) $usage->discount_amount);

        // expired window: disable high, leave low active but outside window
        $low->update([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);
        $high->update(['is_active' => false]);

        $order2 = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        $this->assertEquals(1000.0, (float) $order2->grand_total);
        $this->assertSame(0, PromotionUsage::query()->where('source_id', $order2->id)->count());
    }

    public function test_promotion_min_subtotal_and_product_scope(): void
    {
        $this->makePromotion([
            'name' => 'Min big',
            'value' => 50,
            'min_subtotal' => 5000,
            'priority' => 500,
        ]);

        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        // min not met → no promo
        $this->assertEquals(1000.0, (float) $order->grand_total);
        $this->assertSame(0, PromotionUsage::query()->count());

        // product-scoped promo on matching product still applies when no min
        $this->makePromotion([
            'name' => 'Product only',
            'type' => 'fixed_off',
            'value' => 25,
            'priority' => 400,
            'product_ids' => [$this->product->id],
        ]);
        // force min-big inactive so product promo can win
        Promotion::query()->where('name', 'Min big')->update(['is_active' => false]);

        $order2 = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        $this->assertEquals(975.0, (float) $order2->grand_total);
    }

    public function test_flash_sale_requires_window_and_countdown_from_db(): void
    {
        try {
            $this->makePromotion([
                'name' => 'Flash no window',
                'kind' => 'flash',
                'value' => 30,
            ]);
            $this->fail('Expected flash window RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('starts_at', $e->getMessage());
        }

        $flash = $this->makePromotion([
            'name' => 'Night flash',
            'kind' => 'flash',
            'type' => 'percent_off',
            'value' => 30,
            'starts_at' => now()->subHour()->toDateTimeString(),
            'ends_at' => now()->addHours(2)->toDateTimeString(),
        ]);

        $upcoming = $this->makePromotion([
            'name' => 'Tomorrow flash',
            'kind' => 'flash',
            'type' => 'percent_off',
            'value' => 15,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
        ]);

        $status = app(FlashSaleStatus::class)->forCompany((int) $this->admin->company_id);

        $this->assertSame(2, $status['rows']->count());
        $this->assertSame(1, $status['active_count']);
        $this->assertSame(1, $status['upcoming_count']);
        $this->assertStringContainsString('ends_at(server DB)', $status['method']);

        $activeRow = $status['rows']->first(fn (array $r) => (int) $r['promotion']->id === $flash->id);
        $upcomingRow = $status['rows']->first(fn (array $r) => (int) $r['promotion']->id === $upcoming->id);

        $this->assertSame('active', $activeRow['state']);
        $this->assertGreaterThan(0, $activeRow['remaining_seconds']);
        $this->assertLessThanOrEqual(7200, $activeRow['remaining_seconds']);
        // remaining derived from DB ends_at, not a hardcoded client timer
        $this->assertSame($flash->ends_at->toDateTimeString(), $activeRow['ends_at_db']);
        $this->assertSame('upcoming', $upcomingRow['state']);
    }

    public function test_promotion_report_attributes_real_invoice_revenue(): void
    {
        $this->makePromotion([
            'name' => 'Report promo',
            'value' => 10,
            'priority' => 900,
        ]);

        $order = app(CreateSalesOrder::class)->handle($this->orderPayload(), $this->httpRequest());
        $order->refresh();
        $this->assertEquals(900.0, (float) $order->grand_total);

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        $order = SalesOrder::query()->findOrFail($order->id);
        $invoice = app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice->fresh(), $this->httpRequest());

        $report = app(PromotionReport::class)->forPeriod(
            (int) $this->admin->company_id,
            'monthly',
        );

        $this->assertSame(1, $report['totals']['promotions']);
        $this->assertSame(1, $report['totals']['redemptions']);
        $this->assertEquals(100.0, $report['totals']['discount']);
        $this->assertEquals(900.0, $report['totals']['attributed_revenue']);
        $this->assertStringContainsString('promotion_usages', $report['method']);
        $this->assertSame(1, $report['sample_size']);
    }

    public function test_promotion_routes_enforce_permissions(): void
    {
        $user = $this->makeUser();
        $role = $this->roleWith(['portal.erp.access']);
        $user->roles()->attach($role->id);

        $this->actingAs($user)->get('/app/sales/promotions')->assertForbidden();
        $this->actingAs($user)->post('/app/sales/promotions', [
            'name' => 'X',
            'type' => 'percent_off',
            'value' => 5,
        ])->assertForbidden();
        $this->actingAs($user)->get('/app/sales/promotions/flash')->assertForbidden();
        $this->actingAs($user)->get('/app/reports/sales/promotions')->assertForbidden();

        $full = $this->roleWith([
            'portal.erp.access',
            'sales.promotions.view',
            'sales.promotions.create',
            'sales.reports.view',
        ]);
        $user->roles()->sync([$full->id]);
        app(PermissionCatalog::class)->invalidate($user);

        $this->actingAs($user)->get('/app/sales/promotions')->assertOk();
        $this->actingAs($user)->get('/app/sales/promotions/flash')->assertOk();
        $this->actingAs($user)->get('/app/reports/sales/promotions')->assertOk();
        $this->actingAs($user)->post('/app/sales/promotions', [
            'name' => 'HTTP Promo',
            'type' => 'percent_off',
            'value' => 5,
        ])->assertRedirect(route('sales.promotions.index'));

        $this->assertTrue(Promotion::query()->where('name', 'HTTP Promo')->exists());
    }

    public function test_structural_seeders_ship_no_fake_promotions(): void
    {
        $this->assertSame(0, Promotion::query()->count());
        $this->assertSame(0, PromotionUsage::query()->count());
        $this->assertSame(0, Coupon::query()->count());
    }

    public function test_promotion_stacks_under_coupon_on_quotation(): void
    {
        $this->makePromotion(['name' => 'Stack', 'value' => 10, 'priority' => 100]);
        app(CreateCoupon::class)->handle([
            'code' => 'STACK5',
            'type' => 'percent_off',
            'value' => 10,
        ], $this->httpRequest());

        // 1000 − promo 100 = 900; coupon 10% of 900 = 90; total discount 190 → 810
        $quote = app(CreateQuotation::class)->handle(
            $this->orderPayload(['coupon_code' => 'STACK5']),
            $this->httpRequest(),
        );

        // discount column = line + doc (promo + coupon)
        $this->assertEquals(190.0, (float) $quote->discount);
        $this->assertEquals(90.0, (float) $quote->coupon_discount);
        $this->assertEquals(810.0, (float) $quote->grand_total);
        $this->assertSame(1, PromotionUsage::query()->where('source_type', 'quotation')->count());
        $this->assertSame(1, CouponUsage::query()->where('source_type', 'quotation')->count());
    }
}
