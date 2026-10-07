<?php

namespace Tests\Feature;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\PriceListItem;
use App\Domain\Masters\ProductPriceHistory;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-110 Price History: the append-only reader for product_price_history.
 * Every bulk/manual change is listed newest-first inside the running
 * user's company, filterable by product/list/source/date, linked back to
 * its batch, and gated by the dedicated pricing.view permission.
 */
class PriceHistoryTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected Product $other;

    protected PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->product = $this->makeProduct('HIS-1', 'History Probe One');
        $this->other = $this->makeProduct('HIS-2', 'History Probe Two');

        $this->list = PriceList::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'HIS-LIST',
            'name' => 'History list',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'is_active' => true,
            'is_default' => false,
        ]);

        PriceListItem::query()->create([
            'price_list_id' => $this->list->id,
            'product_id' => $this->product->id,
            'price' => 100,
        ]);
        PriceListItem::query()->create([
            'price_list_id' => $this->list->id,
            'product_id' => $this->other->id,
            'price' => 200,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__price-history', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeProduct(string $code, string $name): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $code,
            'sku' => $code.'-SKU',
            'name' => $name,
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());
    }

    /** @return array<string, mixed> */
    private function manualRow(Product $product, string $note, array $overrides = []): ProductPriceHistory
    {
        $row = ProductPriceHistory::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'product_id' => $product->id,
            'price_list_id' => $this->list->id,
            'old_price' => 100,
            'new_price' => 110,
            'percent_change' => 10,
            'source' => 'manual',
            'changed_by' => $this->admin->id,
            'note' => $note,
        ], $overrides));

        return $row;
    }

    private function backdate(ProductPriceHistory $row, $when): void
    {
        ProductPriceHistory::query()
            ->whereKey($row->getKey())
            ->update(['created_at' => $when]);
    }

    public function test_history_lists_bulk_applied_changes_newest_first_with_batch_reference(): void
    {
        $older = $this->manualRow($this->other, 'manual-note-older');
        $this->backdate($older, now()->subDay());

        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), [
                'mode' => 'confirm',
                'price_list_id' => $this->list->id,
                'change_type' => 'percent',
                'percent' => -10,
            ])
            ->assertRedirect(route('pricing.bulk-update'));

        $response = $this->actingAs($this->admin)
            ->get(route('pricing.history'))
            ->assertOk();

        $response
            ->assertSee('90.00')
            ->assertSee('180.00')
            ->assertSee('bulk update')
            ->assertSee('manual-note-older')
            ->assertSeeInOrder(['bulk update', 'manual'])
            ->assertSee('Batch #1')
            ->assertSee('manual');

        $this->assertSame(
            3,
            ProductPriceHistory::query()->where('company_id', $this->admin->company_id)->count(),
        );
    }

    public function test_history_never_leaks_rows_from_another_company(): void
    {
        $shadowCompanyId = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Pricing Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shadowProductId = (int) DB::table('products')->insertGetId([
            'company_id' => $shadowCompanyId,
            'code' => 'SHD-9',
            'sku' => 'SHD-9-SKU',
            'name' => 'Shadow Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_price_history')->insert([
            'company_id' => $shadowCompanyId,
            'product_id' => $shadowProductId,
            'price_list_id' => null,
            'old_price' => 1,
            'new_price' => 2,
            'percent_change' => 100,
            'source' => 'manual',
            'note' => 'SHADOW-PRICE-NOTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->manualRow($this->product, 'own-company-note');

        $this->actingAs($this->admin)
            ->get(route('pricing.history'))
            ->assertOk()
            ->assertSee('own-company-note')
            ->assertDontSee('SHADOW-PRICE-NOTE')
            ->assertDontSee('SHD-9');
    }

    public function test_history_filters_by_product_search_source_list_and_date(): void
    {
        $this->manualRow($this->product, 'note-one');
        $this->manualRow($this->other, 'note-two');

        $emptyList = PriceList::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'HIS-EMPTY',
            'name' => 'Empty list',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->actingAs($this->admin)
            ->get(route('pricing.history', ['q' => 'HIS-1']))
            ->assertOk()
            ->assertSee('note-one')
            ->assertDontSee('HIS-2');

        $this->actingAs($this->admin)
            ->get(route('pricing.history', ['source' => 'bulk_update']))
            ->assertOk()
            ->assertSee('No price changes recorded yet.');

        $this->actingAs($this->admin)
            ->get(route('pricing.history', ['source' => 'manual']))
            ->assertOk()
            ->assertSee('note-one')
            ->assertSee('note-two');

        $this->actingAs($this->admin)
            ->get(route('pricing.history', ['price_list_id' => $emptyList->id]))
            ->assertOk()
            ->assertSee('No price changes recorded yet.');

        $this->actingAs($this->admin)
            ->get(route('pricing.history', ['from' => now()->addDay()->toDateString()]))
            ->assertOk()
            ->assertSee('No price changes recorded yet.');

        $this->actingAs($this->admin)
            ->get(route('pricing.history', ['source' => 'whatever']))
            ->assertSessionHasErrors('source');
    }

    public function test_history_empty_state_is_honest(): void
    {
        $this->actingAs($this->admin)
            ->get(route('pricing.history'))
            ->assertOk()
            ->assertSee('No price changes recorded yet.');
    }

    public function test_history_requires_the_pricing_view_permission(): void
    {
        $denied = $this->makeUser(['name' => 'Price Manager Only']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.manage'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('pricing.history'))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Price Viewer']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.view'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->manualRow($this->product, 'viewer-visible');

        $this->actingAs($allowed)
            ->get(route('pricing.history'))
            ->assertOk()
            ->assertSee('viewer-visible');
    }

    public function test_price_history_menu_entry_is_active_and_permissioned(): void
    {
        $leaf = MenuItem::query()
            ->join('permissions', 'permissions.id', '=', 'menu_items.permission_id')
            ->where('menu_items.label', 'Price History')
            ->select('menu_items.*', 'permissions.key as permission_key')
            ->firstOrFail();

        $this->assertSame('active', $leaf->status);
        $this->assertTrue($leaf->is_active);
        $this->assertSame('/app/pricing/history', $leaf->route);
        $this->assertSame('pricing.view', $leaf->permission_key);
    }
}
