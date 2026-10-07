<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §04-20 product settings.
 *
 * The screen is the settings engine's, so what matters here is that each key has
 * a consumer that actually reads it — a setting nobody reads is a decoration:
 *
 *  · the cost method, stock flag and batch flag preselect the add-product form;
 *  · `sku_from_code` lets the shop label products with their code, which changes
 *    what the create and edit forms insist on and what the request derives;
 *  · a value that is not a real cost method falls back rather than preselecting
 *    something the form cannot show.
 */
class ProductSettingsTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

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
    }

    protected function setting(string $key, mixed $value): void
    {
        app(SettingService::class)->set('inventory', $key, $value);
    }

    public function test_the_settings_screen_shows_the_product_defaults(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.show', ['group' => 'inventory']))
            ->assertOk()
            ->assertSee('Default cost method for a new product')
            ->assertSee('New products track batches by default')
            ->assertSee('Leave SKU blank to use the product code');
    }

    public function test_the_create_form_prefills_the_configured_defaults(): void
    {
        $this->setting('default_cost_method', 'lifo');
        $this->setting('default_track_batch', true);
        $this->setting('default_is_stocked', false);

        $response = $this->actingAs($this->admin)->get(route('inventory.products.create'))->assertOk();

        // The select marks the configured method, and the two switches follow the
        // shop's answer rather than this file's opinion. `@selected` renders on its
        // own line, so the option and the attribute are matched with whitespace
        // between them rather than a single space.
        $this->assertMatchesRegularExpression('/value="lifo"\s+selected/', $response->getContent());

        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/name="is_stocked"[^>]*value="1"(?![^>]*checked)/',
            $html,
            'With default_is_stocked off, the stock-managed switch must not start checked.',
        );
        $this->assertMatchesRegularExpression(
            '/name="track_batch"[^>]*value="1"[^>]*checked/',
            $html,
            'With default_track_batch on, the batch switch must start checked.',
        );
    }

    public function test_a_blank_sku_takes_the_product_code_when_the_setting_is_on(): void
    {
        $this->setting('sku_from_code', true);

        $this->actingAs($this->admin)
            ->post(route('inventory.products.store'), [
                'code' => 'WITHOUT-SKU',
                'sku' => '',
                'name' => 'Labelled by its code',
                'cost_method' => 'wac',
                'is_stocked' => 1,
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('code', 'WITHOUT-SKU')->sole();

        $this->assertSame('WITHOUT-SKU', $product->sku);
    }

    public function test_a_blank_sku_is_refused_when_the_setting_is_off(): void
    {
        $this->setting('sku_from_code', false);

        $this->actingAs($this->admin)
            ->post(route('inventory.products.store'), [
                'code' => 'NEEDS-SKU',
                'sku' => '',
                'name' => 'No label',
                'cost_method' => 'wac',
            ])
            ->assertSessionHasErrors('sku');

        $this->assertSame(0, Product::query()->where('code', 'NEEDS-SKU')->count());
    }

    public function test_an_edit_can_also_leave_the_sku_to_the_code(): void
    {
        $this->setting('sku_from_code', true);

        $this->actingAs($this->admin)->post(route('inventory.products.store'), [
            'code' => 'EDIT-1',
            'sku' => 'EDIT-1-SKU',
            'name' => 'Edited later',
            'cost_method' => 'wac',
        ])->assertSessionHasNoErrors();

        $product = Product::query()->where('code', 'EDIT-1')->sole();

        $this->actingAs($this->admin)
            ->put(route('inventory.products.update', $product), [
                'code' => 'EDIT-1',
                'sku' => '',
                'name' => 'Edited later',
                'cost_method' => 'wac',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('EDIT-1', $product->refresh()->sku);
    }

    public function test_a_stored_cost_method_that_is_not_a_real_one_falls_back_to_wac(): void
    {
        $this->setting('default_cost_method', 'averageish');

        $response = $this->actingAs($this->admin)
            ->get(route('inventory.products.create'))
            ->assertOk()
            ->assertDontSee('averageish');

        $this->assertMatchesRegularExpression(
            '/value="wac"\s+selected/',
            $response->getContent(),
            'A stored value that is not a real method must fall back to WAC rather than leave the select blank.',
        );
    }

    public function test_the_form_stops_insisting_on_a_sku_only_when_the_setting_is_on(): void
    {
        $this->setting('sku_from_code', false);

        $required = $this->actingAs($this->admin)->get(route('inventory.products.create'))->getContent();

        $this->assertMatchesRegularExpression('/id="sku"[^>]*required/', $required);
        $this->assertStringNotContainsString('Left blank, the SKU becomes the product code.', $required);

        $this->setting('sku_from_code', true);

        $optional = $this->actingAs($this->admin)->get(route('inventory.products.create'))->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="sku"[^>]*required/', $optional);
        $this->assertStringContainsString('Left blank, the SKU becomes the product code.', $optional);
    }

    /**
     * The screen is the write path for these defaults, so the select has to offer
     * the method names the product form can actually show — the engine validates a
     * select with Rule::in(array_keys(options)), so a list of bare values would
     * render 0..3 and refuse every real method name on save.
     */
    public function test_the_settings_screen_saves_a_cost_method_the_product_form_can_show(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.show', ['group' => 'inventory']))
            ->assertOk()
            ->assertSee('value="fifo"', false)
            ->assertSee('>Weighted average</option>', false);

        $this->actingAs($this->admin)
            ->post(route('settings.update', ['group' => 'inventory']), [
                'settings' => ['default_cost_method' => 'lifo'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('lifo', app(SettingService::class)->get('inventory', 'default_cost_method'));

        $this->assertMatchesRegularExpression(
            '/value="lifo"\s+selected/',
            $this->actingAs($this->admin)->get(route('inventory.products.create'))->getContent(),
            'What the settings screen saved is what the add-product form preselects.',
        );
    }

    /**
     * A switch that cannot be turned back off is not a switch. An unchecked box
     * sends nothing, and the engine only writes the keys it is handed, so the
     * screen carries the "off" in a hidden twin that the checkbox overrides.
     */
    public function test_a_switch_can_be_turned_back_off(): void
    {
        $this->setting('default_track_batch', true);

        $this->actingAs($this->admin)
            ->get(route('settings.show', ['group' => 'inventory']))
            ->assertOk()
            ->assertSee('<input type="hidden" name="settings[default_track_batch]" value="0">', false);

        $this->actingAs($this->admin)
            ->post(route('settings.update', ['group' => 'inventory']), [
                'settings' => ['default_track_batch' => '0'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(
            app(SettingService::class)->getBool('inventory', 'default_track_batch', true),
            'Turning the batch switch off has to store the off.',
        );
    }
}
