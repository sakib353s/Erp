<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\PosSession;
use App\Domain\Sales\PosTransaction;
use App\Domain\Settings\Setting;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-47 Sales › POS › POS Settings: the engine-driven `pos` group
 * (paper width, receipt footer, cash rounding increment, offline toggle)
 * on dedicated GET|POST /app/settings/{group=…} routes behind the new
 * `pos.settings.configure` key — registered before the generic
 * settings catch-all so the generic settings.view/update keys never
 * open it. Every field is wired to a real consumer: the increment
 * rounds POS grand totals half-up into the invoice rounding column
 * (0.01 default = exact-paisa identity), the toggle gates POST /pos/sync
 * with an honest 403, and paper width + footer drive a printable
 * company-scoped receipt screen linked from the terminal flash. Saves
 * flow through SettingService → setting_history + `config.update` audit.
 */
class PosSettingsTest extends TestCase
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
            'code' => 'POSSET-1',
            'sku' => 'POSSET-SKU-1',
            'name' => 'Settings Rounding Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 50, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'posset-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__pos-settings-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function openSession(float $float = 1000.0): PosSession
    {
        return app(OpenPosSession::class)->handle(
            ['opening_float' => $float, 'warehouse_id' => $this->warehouse->id],
            $this->httpRequest(),
        );
    }

    protected function userWith(array $permissionKeys): User
    {
        $user = $this->makeUser();
        $user->roles()->sync($this->roleWith($permissionKeys)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    protected function salePayload(float $unitPrice, ?float $tendered = null): array
    {
        $payload = [
            'payment_method' => 'cash',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => $unitPrice],
            ],
        ];
        if ($tendered !== null) {
            $payload['tendered'] = $tendered;
        }

        return $payload;
    }

    public function test_pos_settings_screen_renders_defaults_from_the_engine(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.pos.show', 'pos'))
            ->assertOk()
            ->assertSee('POS Settings')
            ->assertSee('Receipt paper width')
            ->assertSee('80 mm (standard)')
            ->assertSee('Receipt footer text')
            ->assertSee('Cash rounding increment (BDT)')
            ->assertSee('0.01 — off (exact paisa)')
            ->assertSee('Allow offline POS sync');
    }

    public function test_pos_settings_update_persists_values_with_config_audit(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.pos.update', 'pos'), [
                'settings' => [
                    'paper_width' => '58',
                    'receipt_footer' => 'Thank you for shopping with us!',
                    'rounding_increment' => '0.50',
                    'offline_enabled' => false,
                ],
            ])
            ->assertRedirect();

        $rows = Setting::query()
            ->where('setting_group', 'pos')
            ->where('company_id', $this->admin->company_id)
            ->get()
            ->keyBy('setting_key');
        $this->assertSame(4, $rows->count());
        $this->assertSame('58', $rows['paper_width']->value);
        $this->assertSame('Thank you for shopping with us!', $rows['receipt_footer']->value);
        $this->assertSame('0.50', $rows['rounding_increment']->value);
        $this->assertSame('0', $rows['offline_enabled']->value);

        $audits = AuditEvent::query()
            ->where('action', 'config.update')
            ->where('entity_type', 'setting')
            ->where('after->group', 'pos')
            ->get();
        $this->assertCount(4, $audits);
        $keys = $audits->pluck('after.key')->sort()->values()->all();
        $this->assertSame(
            ['offline_enabled', 'paper_width', 'receipt_footer', 'rounding_increment'],
            $keys,
        );

        // The stored values come back on the screen (engine round-trip).
        $this->actingAs($this->admin)
            ->get(route('settings.pos.show', 'pos'))
            ->assertOk()
            ->assertSee('Thank you for shopping with us!');
    }

    public function test_pos_settings_validation_rejects_out_of_range_values(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.pos.update', 'pos'), ['settings' => ['paper_width' => '60']])
            ->assertSessionHasErrors(['settings.paper_width']);

        $this->actingAs($this->admin)
            ->post(route('settings.pos.update', 'pos'), ['settings' => ['rounding_increment' => '0.03']])
            ->assertSessionHasErrors(['settings.rounding_increment']);

        $this->actingAs($this->admin)
            ->post(route('settings.pos.update', 'pos'), ['settings' => ['receipt_footer' => str_repeat('x', 501)]])
            ->assertSessionHasErrors(['settings.receipt_footer']);

        $this->actingAs($this->admin)
            ->post(route('settings.pos.update', 'pos'), ['settings' => ['offline_enabled' => 'maybe']])
            ->assertSessionHasErrors(['settings.offline_enabled']);

        $this->assertSame(0, Setting::query()->where('setting_group', 'pos')->count());
    }

    public function test_pos_settings_routes_require_the_dedicated_key(): void
    {
        $limited = $this->userWith(['portal.erp.access']);
        $this->actingAs($limited)->get(route('settings.pos.show', 'pos'))->assertForbidden();
        $this->actingAs($limited)
            ->post(route('settings.pos.update', 'pos'), ['settings' => ['paper_width' => '58']])
            ->assertForbidden();

        // The dedicated key alone (no settings.view / settings.update) is enough.
        $configurator = $this->userWith(['portal.erp.access', 'pos.settings.configure']);
        $this->actingAs($configurator)
            ->get(route('settings.pos.show', 'pos'))
            ->assertOk();
        $this->actingAs($configurator)
            ->post(route('settings.pos.update', 'pos'), ['settings' => ['paper_width' => '58']])
            ->assertRedirect();
        $this->assertSame(
            '58',
            Setting::query()->where('setting_group', 'pos')->where('setting_key', 'paper_width')->value('value'),
        );
    }

    public function test_default_rounding_keeps_exact_paisa(): void
    {
        $session = $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.sales.store'), $this->salePayload(447.50))
            ->assertRedirect(route('pos.terminal'));

        $invoice = Invoice::query()->sole();
        $this->assertEquals(447.5, (float) $invoice->grand_total);
        $this->assertEquals(0.0, (float) $invoice->rounding);

        $txn = PosTransaction::query()->sole();
        $this->assertEquals(447.5, (float) $txn->total);
        $this->assertEquals(447.5, (float) $session->fresh()->cash_sales);
    }

    public function test_cash_rounding_increment_rounds_pos_grand_total_half_up(): void
    {
        $session = $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('settings.pos.update', 'pos'), [
                'settings' => ['rounding_increment' => '1.00'],
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('pos.sales.store'), $this->salePayload(447.50, 500))
            ->assertRedirect(route('pos.terminal'));

        $invoice = Invoice::query()->sole();
        $this->assertEquals(448.0, (float) $invoice->grand_total);
        $this->assertEquals(0.5, (float) $invoice->rounding);

        $txn = PosTransaction::query()->sole();
        $this->assertEquals(448.0, (float) $txn->total);
        $this->assertEquals(52.0, (float) $txn->change_due);
        $this->assertEquals(448.0, (float) $session->fresh()->cash_sales);
    }

    public function test_offline_sync_toggle_gates_batches(): void
    {
        // Default (enabled): the request reaches normal validation, not the gate.
        $this->actingAs($this->admin)
            ->postJson(route('pos.sync'), ['sales' => []])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->post(route('settings.pos.update', 'pos'), [
                'settings' => ['offline_enabled' => false],
            ])
            ->assertRedirect();

        $before = PosTransaction::query()->count();
        $this->actingAs($this->admin)
            ->postJson(route('pos.sync'), [
                'sales' => [['client_uuid' => 'offline-blocked-1', 'lines' => []]],
            ])
            ->assertStatus(403)
            ->assertJson(['message' => 'Offline POS sync is disabled in POS settings.']);
        $this->assertSame($before, PosTransaction::query()->count());
    }

    public function test_receipt_renders_paper_width_and_footer_and_links_from_terminal(): void
    {
        $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.sales.store'), $this->salePayload(447.50))
            ->assertRedirect(route('pos.terminal'))
            ->assertSessionHas('last_receipt');

        $txn = PosTransaction::query()->sole();

        // The flash links the printed receipt on the very next page.
        $this->actingAs($this->admin)
            ->get(route('pos.terminal'))
            ->assertOk()
            ->assertSee('Print receipt');

        // Unconfigured: honest engine default 80 mm, no footer line.
        $this->actingAs($this->admin)
            ->get(route('pos.receipt', $txn))
            ->assertOk()
            ->assertSee('80mm')
            ->assertSee('Settings Rounding Product')
            ->assertDontSee('Thank you for shopping with us!');

        $this->actingAs($this->admin)
            ->post(route('settings.pos.update', 'pos'), [
                'settings' => [
                    'paper_width' => '58',
                    'receipt_footer' => 'Thank you for shopping with us!',
                ],
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('pos.receipt', $txn))
            ->assertOk()
            ->assertSee('58mm')
            ->assertSee('Thank you for shopping with us!');
    }

    public function test_receipt_is_permission_gated_and_company_scoped(): void
    {
        $this->openSession();
        $this->actingAs($this->admin)
            ->post(route('pos.sales.store'), $this->salePayload(100.0))
            ->assertRedirect(route('pos.terminal'));
        $mine = PosTransaction::query()->sole();

        $noSell = $this->userWith(['portal.erp.access', 'pos.settings.configure']);
        $this->actingAs($noSell)->get(route('pos.receipt', $mine))->assertForbidden();

        $shadowCompanyId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowSession = PosSession::create([
            'company_id' => $shadowCompanyId,
            'session_no' => 'POS-SHADOW-1',
            'status' => 'open',
            'opened_at' => now(),
        ]);
        $foreign = PosTransaction::create([
            'company_id' => $shadowCompanyId,
            'pos_session_id' => $shadowSession->id,
            'total' => 100,
            'payment_method' => 'cash',
            'tendered' => 100,
            'change_due' => 0,
            'sold_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('pos.receipt', $foreign))->assertNotFound();
    }

    public function test_pos_settings_menu_leaf_requires_permission(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'POS Settings')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/app/settings/pos', $leaf->route);
        $this->assertSame('pos.settings.configure', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('POS Settings');

        $without = $this->userWith(['portal.erp.access', 'dashboard.view']);
        $this->actingAs($without)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('POS Settings');
    }
}
