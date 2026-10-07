<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
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
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-45 Sales › POS › Cash In / Cash Out: a drawer movement lands on the
 * open session's counters (they feed the close math) and posts one
 * balanced journal entry resolved through the pos_cash_in / pos_cash_out
 * posting rules — a Cash in Hand ↔ Bank transfer, never fabricated
 * income or expense. Every movement carries an audited reason; the route
 * and the menu leaf sit behind pos.cash_io.
 */
class PosCashInOutTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

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
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__pos-cash-io-test', 'POST', [], [], [], [
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

    public function test_cash_in_updates_drawer_and_posts_a_balanced_journal(): void
    {
        $session = $this->openSession(1000.0);

        $this->actingAs($this->admin)
            ->post(route('pos.cash-io.store'), [
                'direction' => 'in',
                'amount' => 500,
                'reason' => 'Float from safe',
            ])
            ->assertRedirect(route('pos.cash-io.index'))
            ->assertSessionHas('status');

        $session->refresh();
        $this->assertEquals(500.0, (float) $session->cash_in);
        $this->assertEquals(0.0, (float) $session->cash_out);

        $entry = JournalEntry::query()
            ->where('source_event', 'pos_cash_in')
            ->sole();
        $this->assertSame('pos_session', $entry->source_type);
        $this->assertSame($session->id, (int) $entry->source_id);
        $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);
        $this->assertEquals(500.0, (float) $entry->total_debit);
        $this->assertStringContainsString('Float from safe', $entry->description);

        $lines = $entry->lines()->with('account')->get();
        $this->assertSame(2, $lines->count());
        $this->assertSame(
            ['1110' => 'debit', '1120' => 'credit'],
            $lines->mapWithKeys(fn ($line) => [$line->account->code => $line->dc])->all(),
        );

        $audit = AuditEvent::query()
            ->where('action', 'pos.cash_moved')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('pos_session', $audit->entity_type);
        $this->assertSame($session->id, (int) $audit->entity_id);
        $this->assertSame('in', $audit->after['direction']);
        $this->assertEquals(500.0, (float) $audit->after['amount']);
        $this->assertSame('Float from safe', $audit->after['reason']);
        $this->assertArrayNotHasKey('session_no', $audit->after);

        $closed = app(ClosePosSession::class)->handle($session, 1500.0, $this->httpRequest());
        $this->assertEquals(1500.0, (float) $closed->expected_cash);
        $this->assertEquals(0.0, (float) $closed->variance);
    }

    public function test_cash_out_reduces_expected_cash_and_balances_close(): void
    {
        $session = $this->openSession(1000.0);

        $this->actingAs($this->admin)
            ->post(route('pos.cash-io.store'), [
                'direction' => 'out',
                'amount' => 300,
                'reason' => 'Safe drop',
            ])
            ->assertRedirect(route('pos.cash-io.index'));

        $session->refresh();
        $this->assertEquals(0.0, (float) $session->cash_in);
        $this->assertEquals(300.0, (float) $session->cash_out);

        $entry = JournalEntry::query()
            ->where('source_event', 'pos_cash_out')
            ->sole();
        $this->assertEquals((float) $entry->total_debit, (float) $entry->total_credit);
        $this->assertEquals(300.0, (float) $entry->total_debit);

        $lines = $entry->lines()->with('account')->get();
        $this->assertSame(
            ['1120' => 'debit', '1110' => 'credit'],
            $lines->mapWithKeys(fn ($line) => [$line->account->code => $line->dc])->all(),
        );

        $audit = AuditEvent::query()
            ->where('action', 'pos.cash_moved')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('out', $audit->after['direction']);
        $this->assertSame('Safe drop', $audit->after['reason']);

        $closed = app(ClosePosSession::class)->handle($session, 700.0, $this->httpRequest());
        $this->assertEquals(700.0, (float) $closed->expected_cash);
        $this->assertEquals(0.0, (float) $closed->variance);
    }

    public function test_cash_movement_validates_direction_amount_and_reason(): void
    {
        $session = $this->openSession();

        $this->actingAs($this->admin)
            ->post(route('pos.cash-io.store'), ['direction' => 'sideways', 'amount' => 100, 'reason' => 'x'])
            ->assertSessionHasErrors(['direction']);
        $this->actingAs($this->admin)
            ->post(route('pos.cash-io.store'), ['direction' => 'in', 'amount' => 0, 'reason' => 'x'])
            ->assertSessionHasErrors(['amount']);
        $this->actingAs($this->admin)
            ->post(route('pos.cash-io.store'), ['direction' => 'in', 'amount' => -5, 'reason' => 'x'])
            ->assertSessionHasErrors(['amount']);
        $this->actingAs($this->admin)
            ->post(route('pos.cash-io.store'), ['direction' => 'in', 'amount' => 100, 'reason' => ''])
            ->assertSessionHasErrors(['reason']);

        $this->assertSame(0, JournalEntry::query()->where('source_type', 'pos_session')->count());
        $session->refresh();
        $this->assertEquals(0.0, (float) $session->cash_in);
        $this->assertEquals(0.0, (float) $session->cash_out);
    }

    public function test_cash_movement_requires_an_open_session(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pos.cash-io.store'), [
                'direction' => 'in',
                'amount' => 500,
                'reason' => 'Float from safe',
            ])
            ->assertSessionHasErrors(['cash_io']);

        $this->assertStringContainsString(
            'No open POS session',
            session('errors')->first('cash_io'),
        );
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'pos_session')->count());
    }

    public function test_cash_movement_rejects_a_closed_session(): void
    {
        $session = $this->openSession(1000.0);
        app(ClosePosSession::class)->handle($session, 1000.0, $this->httpRequest());

        $this->actingAs($this->admin)
            ->post(route('pos.cash-io.store'), [
                'pos_session_id' => $session->id,
                'direction' => 'in',
                'amount' => 500,
                'reason' => 'Float from safe',
            ])
            ->assertSessionHasErrors(['cash_io']);

        $this->assertStringContainsString(
            'No open POS session',
            session('errors')->first('cash_io'),
        );
        $session->refresh();
        $this->assertEquals(0.0, (float) $session->cash_in);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'pos_session')->count());
    }

    public function test_cash_io_routes_require_permission(): void
    {
        $this->actingAs($this->userWith(['portal.erp.access']))
            ->get(route('pos.cash-io.index'))
            ->assertForbidden();
        $this->actingAs($this->userWith(['portal.erp.access']))
            ->post(route('pos.cash-io.store'), [])
            ->assertForbidden();

        $granted = $this->userWith(['portal.erp.access', 'pos.cash_io']);
        $this->actingAs($granted)
            ->get(route('pos.cash-io.index'))
            ->assertOk()
            ->assertSee('Cash In / Cash Out')
            ->assertSee('No open cash drawer');

        $session = $this->openSession(1000.0);
        $this->actingAs($granted)
            ->post(route('pos.cash-io.store'), [
                'direction' => 'in',
                'amount' => 250,
                'reason' => 'Float from safe',
            ])
            ->assertRedirect(route('pos.cash-io.index'));

        $this->assertEquals(250.0, (float) $session->fresh()->cash_in);
        $this->assertSame(
            1,
            JournalEntry::query()->where('source_event', 'pos_cash_in')->count(),
        );
    }

    public function test_cash_io_menu_leaf_requires_permission(): void
    {
        $leaf = MenuItem::query()
            ->where('label', 'Cash In / Cash Out')
            ->firstOrFail();
        $this->assertSame('active', $leaf->status);
        $this->assertSame('/pos/cash-in-out', $leaf->route);
        $this->assertSame('pos.cash_io', $leaf->permission?->key);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Cash In / Cash Out');

        $without = $this->userWith(['portal.erp.access', 'dashboard.view']);
        $this->actingAs($without)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Cash In / Cash Out');
    }
}
