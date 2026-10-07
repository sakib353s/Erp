<?php

namespace Tests\Feature;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\PriceListItem;
use App\Domain\Masters\PricingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPricingRules;
use Tests\TestCase;

/**
 * 02-112 Price Comparison: one product, every scope side by side —
 * price-list item prices (or an honest "Not listed"), every active rule
 * whose product/category scope covers the product with the price that
 * rule alone would produce, and the fully resolved price for the
 * selected customer/qty/date context. Company-scoped on both inputs and
 * rows, gated by pricing.view, with the menu leaf pointing at the screen.
 */
class PriceComparisonTest extends TestCase
{
    use BuildsPricingRules;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPricing();
    }

    public function test_compare_shows_item_price_or_not_listed_for_every_price_list(): void
    {
        $second = PriceList::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PRC-LIST-2',
            'name' => 'Secondary list',
            'valid_from' => now()->subYear()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'is_active' => true,
            'is_default' => false,
        ]);
        PriceListItem::query()->create([
            'price_list_id' => $second->id,
            'product_id' => $this->product->id,
            'price' => 120,
        ]);
        PriceList::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PRC-LIST-3',
            'name' => 'Empty list',
            'valid_from' => now()->subYear()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->actingAs($this->admin)
            ->get(route('pricing.compare', ['product_id' => $this->product->id]))
            ->assertOk()
            ->assertSee('100.00')
            ->assertSee('120.00')
            ->assertSee('Not listed')
            ->assertSee('default');
    }

    public function test_compare_lists_rules_covering_the_product_with_if_applied_price(): void
    {
        $this->makeRule([
            'name' => 'Compare time rule',
            'price' => null,
            'percent_off' => 20,
        ]);
        $this->makeRule([
            'name' => 'Other product rule',
            'product_id' => $this->other->id,
            'price' => 55,
        ]);

        $this->actingAs($this->admin)
            ->get(route('pricing.compare', ['product_id' => $this->product->id]))
            ->assertOk()
            ->assertSee('Compare time rule')
            ->assertSee('80.00')
            ->assertDontSee('Other product rule');
    }

    public function test_compare_resolves_final_price_for_the_selected_context(): void
    {
        $group = $this->makeGroup();
        $this->makeRule([
            'rule_type' => PricingRule::TYPE_CUSTOMER_GROUP,
            'name' => 'Group compare rule',
            'customer_group_id' => $group->id,
            'price' => 85,
        ]);
        $this->customer->customer_group_id = $group->id;
        $this->customer->save();

        $this->actingAs($this->admin)
            ->get(route('pricing.compare', [
                'product_id' => $this->product->id,
                'customer_id' => $this->customer->id,
                'qty' => 3,
            ]))
            ->assertOk()
            ->assertSee('Group compare rule')
            ->assertSee('applied')
            ->assertSee('85.00')
            ->assertSee('Final unit price');
    }

    public function test_compare_rejects_products_and_customers_outside_the_company(): void
    {
        $shadow = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow compare co',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignProduct = DB::table('products')->insertGetId([
            'company_id' => $shadow,
            'code' => 'CMP-FOREIGN',
            'sku' => 'CMP-FOREIGN-SKU',
            'name' => 'Foreign compare product',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->from(route('pricing.compare'))
            ->get(route('pricing.compare', ['product_id' => $foreignProduct]))
            ->assertRedirect(route('pricing.compare'))
            ->assertSessionHasErrors('product_id');
    }

    public function test_compare_never_leaks_rows_from_another_company(): void
    {
        $shadow = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow compare co',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('price_lists')->insert([
            'company_id' => $shadow,
            'code' => 'SHD-LIST',
            'name' => 'Shadow list',
            'is_active' => 1,
            'is_default' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PricingRule::query()->create([
            'company_id' => $shadow,
            'rule_type' => PricingRule::TYPE_SPECIAL,
            'name' => 'Shadow compare rule',
            'priority' => 10,
            'price' => 1,
        ]);

        $this->actingAs($this->admin)
            ->get(route('pricing.compare', ['product_id' => $this->product->id]))
            ->assertOk()
            ->assertDontSee('SHD-LIST')
            ->assertDontSee('Shadow compare rule');
    }

    public function test_compare_requires_pricing_view_and_is_honest_when_empty(): void
    {
        $denied = $this->makeUser(['name' => 'Price Manager Only']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.manage'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('pricing.compare'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Price Viewer']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.view'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('pricing.compare'))
            ->assertOk()
            ->assertSee('Choose a product to compare its prices across every price list and rule.');
    }

    public function test_price_comparison_menu_entry_is_active_and_permissioned(): void
    {
        $leaf = MenuItem::query()
            ->join('permissions', 'permissions.id', '=', 'menu_items.permission_id')
            ->where('menu_items.label', 'Price Comparison')
            ->select('menu_items.*', 'permissions.key as permission_key')
            ->firstOrFail();

        $this->assertSame('active', $leaf->status);
        $this->assertTrue($leaf->is_active);
        $this->assertSame('/app/pricing/compare', $leaf->route);
        $this->assertSame('pricing.view', $leaf->permission_key);
    }
}
