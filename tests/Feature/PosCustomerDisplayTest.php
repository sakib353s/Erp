<?php

namespace Tests\Feature;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Sales\Actions\ClosePosSession;
use App\Domain\Sales\Actions\OpenPosSession;
use App\Domain\Sales\PosSession;
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
 * 02-43 Sales › POS › Customer Display: a paired second screen for the
 * till. Each open session mints a display_code; the terminal shows it
 * (gated pos.customer_display) and pushes a debounced cart snapshot to
 * POST /pos/customer-display/push; the display screen enters the code and
 * polls GET /pos/customer-display/state. The state channel is a strict
 * whitelist — item names, quantities, money totals and a timestamp only,
 * never session numbers, drawer floats, cash counters, or customer/staff
 * fields — and every money figure is recomputed server-side. Pairing is
 * company-scoped and dies with the session; all three routes, the
 * terminal panel, and the menu leaf sit behind pos.customer_display.
 */
class PosCustomerDisplayTest extends TestCase
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
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(SalesCoreSeeder::class);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__pos-display-test', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function openSession(float $float = 1000.0): PosSession
    {
        return app(OpenPosSession::class)->handle(
            ['opening_float' => $float],
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

    protected function pushPayload(string $code, array $lines): array
    {
        return ['code' => $code, 'lines' => $lines];
    }

    protected function sampleLines(): array
    {
        return [
            ['name' => 'Wireless Mouse', 'qty' => 2, 'unit_price' => 150],
            ['name' => 'USB-C Cable', 'qty' => 1, 'unit_price' => 50],
        ];
    }

    public function test_terminal_shows_pairing_code_only_with_display_permission(): void
    {
        $session = $this->openSession();

        $seller = $this->userWith(['portal.erp.access', 'pos.sell']);
        $this->actingAs($seller)
            ->get(route('pos.terminal'))
            ->assertOk()
            ->assertDontSee('Customer display pairing code')
            ->assertDontSee($session->display_code);

        $cashier = $this->userWith(['portal.erp.access', 'pos.sell', 'pos.customer_display']);
        $this->actingAs($cashier)
            ->get(route('pos.terminal'))
            ->assertOk()
            ->assertSee('Customer display pairing code')
            ->assertSee($session->display_code);
    }

    public function test_state_is_a_strict_whitelist_and_totals_are_server_computed(): void
    {
        $session = $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.customer-display.push'), $this->pushPayload($session->display_code, $this->sampleLines()))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $response = $this->actingAs($this->admin)
            ->get(route('pos.customer-display.state', ['code' => $session->display_code]))
            ->assertOk();

        $json = json_decode($response->getContent(), true);
        $topKeys = array_keys($json);
        sort($topKeys);
        $this->assertSame(['item_count', 'lines', 'paired', 'total', 'updated_at'], $topKeys);
        $this->assertTrue($json['paired']);
        $this->assertSame(['name', 'qty', 'unit_price', 'line_total'], array_keys($json['lines'][0]));
        $this->assertSame(300.0, (float) $json['lines'][0]['line_total']);
        $this->assertSame(50.0, (float) $json['lines'][1]['line_total']);
        $this->assertSame(3.0, (float) $json['item_count']);
        $this->assertSame(350.0, (float) $json['total']);
        $this->assertNotNull($json['updated_at']);

        $content = (string) $response->getContent();
        foreach (['opening_float', 'expected_cash', 'session_no', 'cash_sales', 'non_cash_sales', 'cash_in', 'cash_out', 'customer_id', 'warehouse', 'opening'] as $needle) {
            $this->assertStringNotContainsString($needle, $content);
        }
        $this->assertStringNotContainsString($session->session_no, $content);

        $page = $this->actingAs($this->admin)
            ->get(route('pos.customer-display.index', ['code' => $session->display_code]))
            ->assertOk()
            ->assertSee($session->display_code);
        $this->assertStringNotContainsString($session->session_no, (string) $page->getContent());
    }

    public function test_state_is_paired_but_empty_before_any_push(): void
    {
        $session = $this->openSession();

        $this->actingAs($this->admin)
            ->get(route('pos.customer-display.state', ['code' => $session->display_code]))
            ->assertOk()
            ->assertJson([
                'paired' => true,
                'lines' => [],
                'item_count' => 0,
                'total' => 0,
            ])
            ->assertJsonPath('updated_at', null);
    }

    public function test_unknown_code_is_honest_not_paired_on_every_endpoint(): void
    {
        $this->actingAs($this->admin)
            ->get(route('pos.customer-display.state', ['code' => 'NOSUCH99']))
            ->assertOk()
            ->assertJson(['paired' => false]);
        $state = json_decode(
            (string) $this->actingAs($this->admin)->get(route('pos.customer-display.state', ['code' => 'NOSUCH99']))->getContent(),
            true,
        );
        $this->assertStringContainsString('No open POS session', (string) ($state['reason'] ?? ''));

        $this->actingAs($this->admin)
            ->get(route('pos.customer-display.index', ['code' => 'NOSUCH99']))
            ->assertOk()
            ->assertSee('No open POS session');

        $this->actingAs($this->admin)
            ->post(route('pos.customer-display.push'), $this->pushPayload('NOSUCH99', $this->sampleLines()))
            ->assertStatus(404)
            ->assertJson(['ok' => false]);
    }

    public function test_closed_session_loses_pairing(): void
    {
        $session = $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.customer-display.push'), $this->pushPayload($session->display_code, $this->sampleLines()))
            ->assertOk();

        app(ClosePosSession::class)->handle($session->fresh(), 1000.0, $this->httpRequest());

        $this->actingAs($this->admin)
            ->get(route('pos.customer-display.state', ['code' => $session->display_code]))
            ->assertOk()
            ->assertJson(['paired' => false]);
    }

    public function test_empty_cart_push_clears_the_display_state(): void
    {
        $session = $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.customer-display.push'), $this->pushPayload($session->display_code, $this->sampleLines()))
            ->assertOk();

        $this->actingAs($this->admin)
            ->post(route('pos.customer-display.push'), $this->pushPayload($session->display_code, []))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('pos.customer-display.state', ['code' => $session->display_code]))
            ->assertOk()
            ->assertJson([
                'paired' => true,
                'lines' => [],
                'item_count' => 0,
                'total' => 0,
            ]);
    }

    public function test_display_routes_are_gated_and_need_no_other_pos_permission(): void
    {
        $session = $this->openSession();

        $limited = $this->userWith(['portal.erp.access']);
        $this->actingAs($limited)->get(route('pos.customer-display.index'))->assertForbidden();
        $this->actingAs($limited)->get(route('pos.customer-display.state', ['code' => $session->display_code]))->assertForbidden();
        $this->actingAs($limited)
            ->post(route('pos.customer-display.push'), $this->pushPayload($session->display_code, $this->sampleLines()))
            ->assertForbidden();

        // The display device holds only this key — no pos.sell required.
        $displayDevice = $this->userWith(['portal.erp.access', 'pos.customer_display']);
        $this->actingAs($displayDevice)
            ->get(route('pos.customer-display.index'))
            ->assertOk();
        $this->actingAs($displayDevice)
            ->get(route('pos.customer-display.state', ['code' => $session->display_code]))
            ->assertOk()
            ->assertJson(['paired' => true]);
        $this->actingAs($displayDevice)
            ->post(route('pos.customer-display.push'), $this->pushPayload($session->display_code, $this->sampleLines()))
            ->assertOk();
    }

    public function test_push_rejects_invalid_payload(): void
    {
        $session = $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.customer-display.push'), [
                'code' => $session->display_code,
                'lines' => 'not-an-array',
            ])
            ->assertSessionHasErrors(['lines']);
    }

    public function test_pairing_code_never_crosses_companies(): void
    {
        $shadowCompanyId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PosSession::create([
            'company_id' => $shadowCompanyId,
            'session_no' => 'POS-SHADOW-1',
            'display_code' => 'SHADOW11',
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('pos.customer-display.state', ['code' => 'SHADOW11']))
            ->assertOk()
            ->assertJson(['paired' => false]);

        $this->actingAs($this->admin)
            ->post(route('pos.customer-display.push'), $this->pushPayload('SHADOW11', $this->sampleLines()))
            ->assertStatus(404);

        $this->actingAs($this->admin)
            ->get(route('pos.customer-display.index', ['code' => 'SHADOW11']))
            ->assertOk()
            ->assertSee('not paired');
    }

    public function test_customer_display_menu_leaf_requires_permission(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'Customer Display')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/pos/customer-display', $leaf->route);
        $this->assertSame('pos.customer_display', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Customer Display');

        $without = $this->userWith(['portal.erp.access', 'dashboard.view']);
        $this->actingAs($without)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Customer Display');
    }
}
