<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\SalesOrderLine;
use App\Domain\Sales\SuspiciousOrderFlag;
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
 * 02-28 Fake / Suspicious orders: rule score with explainability
 * (every fired rule carries its real evidence), a review action that
 * records the human decision and audits it, and a hard guarantee that
 * review never mutates or deletes the order — evidence is never
 * auto-deleted, and a reviewed flag is never re-scored.
 */
class SuspiciousOrderFlagTest extends TestCase
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
            'code' => 'SUSP-1',
            'sku' => 'SUSP-SKU-1',
            'name' => 'Suspicious Product',
            'cost_method' => 'fifo',
            'standard_cost' => 60,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__suspicious-orders', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'CUST-SUSP',
            'name' => 'Fresh Buyer',
            'email' => 'buyer@susp.test',
        ], $overrides));
    }

    /** New customer + bulk qty + round-thousand total = well above threshold. */
    protected function makeRiskyOrder(array $overrides = []): SalesOrder
    {
        return app(CreateSalesOrder::class)->handle(array_merge([
            'customer_id' => $this->makeCustomer()->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 100, 'unit_price' => 100],
            ],
        ], $overrides), $this->httpRequest());
    }

    protected function makeBenignOrder(): SalesOrder
    {
        return app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 1, 'unit_price' => 250],
            ],
        ], $this->httpRequest());
    }

    public function test_scorer_fires_explainable_rules_with_real_evidence(): void
    {
        $order = $this->makeRiskyOrder();

        $flag = SuspiciousOrderFlag::query()
            ->where('sales_order_id', $order->id)
            ->firstOrFail();

        $this->assertSame(SuspiciousOrderFlag::STATUS_OPEN, $flag->status);
        $this->assertGreaterThanOrEqual(75, $flag->score);
        $this->assertSame('high', $flag->level);

        $keys = array_column($flag->rules, 'key');
        $this->assertContains('bulk_qty', $keys);
        $this->assertContains('round_total', $keys);
        $this->assertContains('new_customer_value', $keys);

        $evidence = implode(' | ', array_column($flag->rules, 'evidence'));
        $this->assertStringContainsString('qty 100', $evidence);
        $this->assertStringContainsString('10,000.00', $evidence);
        $this->assertStringContainsString('exact multiple of 1,000', $evidence);
        $this->assertStringContainsString('Fresh Buyer', $evidence);
        $this->assertStringContainsString('Suspicious Product', $evidence);

        // Every rule carries its own weight and the score is their sum
        // (clamped) — no black box.
        foreach ($flag->rules as $rule) {
            $this->assertArrayHasKey('weight', $rule);
            $this->assertGreaterThan(0, $rule['weight']);
            $this->assertNotSame('', $rule['evidence']);
        }
        $this->assertSame(
            min(100, array_sum(array_column($flag->rules, 'weight'))),
            $flag->score,
        );
    }

    public function test_benign_order_is_not_flagged_and_view_is_honest(): void
    {
        $order = $this->makeBenignOrder();

        $this->assertSame(0, SuspiciousOrderFlag::query()
            ->where('sales_order_id', $order->id)
            ->count());

        $this->actingAs($this->admin)
            ->get(route('sales.orders.index', ['flag' => 'suspicious']))
            ->assertOk()
            ->assertSee('No orders flagged as suspicious')
            ->assertDontSee($order->order_no);
    }

    public function test_suspicious_view_requires_view_and_review_permissions(): void
    {
        $this->makeRiskyOrder();

        // Admin holds everything.
        $this->actingAs($this->admin)
            ->get(route('sales.orders.index', ['flag' => 'suspicious']))
            ->assertOk();

        // sales.orders.view alone is not enough.
        $viewOnly = $this->makeUser();
        $viewOnly->roles()->attach($this->roleWith([
            'portal.erp.access', 'sales.orders.view',
        ])->id);

        $this->actingAs($viewOnly)
            ->get(route('sales.orders.index', ['flag' => 'suspicious']))
            ->assertForbidden();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'permission.denied',
            'actor_id' => $viewOnly->id,
        ]);
        $denial = AuditEvent::query()
            ->where('action', 'permission.denied')
            ->where('actor_id', $viewOnly->id)
            ->orderByDesc('id')
            ->first();
        $this->assertSame('sales.orders.review', $denial->after['permission'] ?? null);

        // The plain list still works with view alone.
        $this->actingAs($viewOnly)
            ->get(route('sales.orders.index'))
            ->assertOk();

        // sales.orders.review without sales.orders.view is also refused.
        $reviewOnly = $this->makeUser();
        $reviewOnly->roles()->attach($this->roleWith([
            'portal.erp.access', 'sales.orders.review',
        ])->id);

        $this->actingAs($reviewOnly)
            ->get(route('sales.orders.index', ['flag' => 'suspicious']))
            ->assertForbidden();

        // The chip is only offered to users who could open the view.
        $this->actingAs($viewOnly)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertDontSee('Fake / Suspicious Orders');

        $this->actingAs($this->admin)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertSee('Fake / Suspicious Orders');
    }

    public function test_review_decision_audits_and_never_touches_the_order(): void
    {
        $order = $this->makeRiskyOrder();
        $before = $order->fresh();

        $this->actingAs($this->admin)
            ->post(route('sales.orders.suspicious-review', $order), [
                'decision' => SuspiciousOrderFlag::DECISION_LEGITIMATE,
                'notes' => 'Verified with the customer by phone.',
            ])
            ->assertRedirect();

        $flag = SuspiciousOrderFlag::query()
            ->where('sales_order_id', $order->id)
            ->firstOrFail();

        $this->assertSame(SuspiciousOrderFlag::STATUS_REVIEWED, $flag->status);
        $this->assertSame(SuspiciousOrderFlag::DECISION_LEGITIMATE, $flag->decision);
        $this->assertSame('Verified with the customer by phone.', $flag->review_notes);
        $this->assertSame($this->admin->id, $flag->reviewed_by);
        $this->assertNotNull($flag->reviewed_at);

        // The order itself: never auto-delete, never mutated.
        $after = $order->fresh();
        $this->assertNotNull($after);
        $this->assertSame($before->status, $after->status);
        $this->assertSame((string) $before->grand_total, (string) $after->grand_total);
        $this->assertSame((int) $before->id, (int) $after->id);

        $audit = AuditEvent::query()
            ->where('action', 'sales.order_review_decision')
            ->where('entity_id', $order->id)
            ->firstOrFail();
        $this->assertSame('sales_order', $audit->entity_type);
        $this->assertSame(SuspiciousOrderFlag::DECISION_LEGITIMATE, $audit->after['decision'] ?? null);
        $this->assertSame('open', $audit->before['status'] ?? null);
    }

    public function test_confirmed_suspicious_stays_visible_and_reviewed_flag_is_never_rescored(): void
    {
        $order = $this->makeRiskyOrder();
        $flag = SuspiciousOrderFlag::query()->where('sales_order_id', $order->id)->firstOrFail();
        $snapshot = ['score' => $flag->score, 'rules' => $flag->rules, 'level' => $flag->level];

        $this->actingAs($this->admin)
            ->post(route('sales.orders.suspicious-review', $order), [
                'decision' => SuspiciousOrderFlag::DECISION_SUSPICIOUS,
            ])
            ->assertRedirect();

        // Evidence never disappears from the queue.
        $this->actingAs($this->admin)
            ->get(route('sales.orders.index', ['flag' => 'suspicious']))
            ->assertOk()
            ->assertSee($order->order_no)
            ->assertSee('Confirmed suspicious');

        // Re-scoring (backfill on a later visit) keeps the human-reviewed
        // snapshot intact — one row, original score, decision preserved.
        $this->actingAs($this->admin)
            ->get(route('sales.orders.index', ['flag' => 'suspicious']))
            ->assertOk();

        $this->assertSame(1, SuspiciousOrderFlag::query()->where('sales_order_id', $order->id)->count());
        $fresh = $flag->fresh();
        $this->assertSame($snapshot['score'], $fresh->score);
        $this->assertSame($snapshot['level'], $fresh->level);
        $this->assertSame($snapshot['rules'], $fresh->rules);
        $this->assertSame(SuspiciousOrderFlag::DECISION_SUSPICIOUS, $fresh->decision);
        $this->assertNotNull($order->fresh());
    }

    public function test_review_requires_permission_and_valid_input(): void
    {
        $order = $this->makeRiskyOrder();

        // A user holding sales.orders.view but NOT sales.orders.review.
        $viewOnly = $this->makeUser();
        $viewOnly->roles()->attach($this->roleWith([
            'portal.erp.access', 'sales.orders.view',
        ])->id);

        $this->actingAs($viewOnly)
            ->post(route('sales.orders.suspicious-review', $order), [
                'decision' => SuspiciousOrderFlag::DECISION_LEGITIMATE,
            ])
            ->assertForbidden();

        $this->assertSame(
            SuspiciousOrderFlag::STATUS_OPEN,
            SuspiciousOrderFlag::query()->where('sales_order_id', $order->id)->value('status'),
        );

        $this->actingAs($this->admin)
            ->post(route('sales.orders.suspicious-review', $order), [
                'decision' => 'maybe',
            ])
            ->assertSessionHasErrors(['decision']);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.suspicious-review', $order), [
                'decision' => SuspiciousOrderFlag::DECISION_LEGITIMATE,
                'notes' => str_repeat('n', 501),
            ])
            ->assertSessionHasErrors(['notes']);

        $flag = SuspiciousOrderFlag::query()->where('sales_order_id', $order->id)->firstOrFail();
        $this->assertSame(SuspiciousOrderFlag::STATUS_OPEN, $flag->status);
        $this->assertNull($flag->decision);
    }

    public function test_on_read_backfill_scores_orders_created_outside_the_action(): void
    {
        // Created directly — no CreateSalesOrder, so nothing scored it yet.
        $order = SalesOrder::create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'warehouse_id' => $this->warehouse->id,
            'order_no' => 'SO-BACKFILL-1',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'currency' => 'BDT',
            'subtotal' => 6000,
            'grand_total' => 6000,
            'created_by' => $this->admin->id,
        ]);
        SalesOrderLine::create([
            'company_id' => $this->admin->company_id,
            'sales_order_id' => $order->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'qty' => 100,
            'unit_price' => 60,
        ]);

        $this->assertSame(0, SuspiciousOrderFlag::query()
            ->where('sales_order_id', $order->id)
            ->count());

        $this->actingAs($this->admin)
            ->get(route('sales.orders.index', ['flag' => 'suspicious']))
            ->assertOk()
            ->assertSee($order->order_no);

        $flag = SuspiciousOrderFlag::query()
            ->where('sales_order_id', $order->id)
            ->firstOrFail();
        $this->assertGreaterThanOrEqual(50, $flag->score);
        $this->assertSame(SuspiciousOrderFlag::STATUS_OPEN, $flag->status);

        // Strongest score first.
        $risky = $this->makeRiskyOrder();
        $this->actingAs($this->admin)
            ->get(route('sales.orders.index', ['flag' => 'suspicious']))
            ->assertOk()
            ->assertSeeInOrder([$risky->order_no, $order->order_no]);
    }

    public function test_shadow_company_orders_never_appear_in_the_queue(): void
    {
        $shadowCompanyId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowOrder = SalesOrder::create([
            'company_id' => $shadowCompanyId,
            'order_no' => 'SO-SHADOW-9',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'currency' => 'BDT',
            'subtotal' => 9000,
            'grand_total' => 9000,
        ]);
        SuspiciousOrderFlag::create([
            'company_id' => $shadowCompanyId,
            'sales_order_id' => $shadowOrder->id,
            'score' => 95,
            'level' => 'high',
            'rules' => [['key' => 'shadow', 'label' => 'Shadow', 'weight' => 95, 'evidence' => 'other company']],
            'status' => SuspiciousOrderFlag::STATUS_OPEN,
            'scored_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('sales.orders.index', ['flag' => 'suspicious']))
            ->assertOk()
            ->assertDontSee('SO-SHADOW-9');

        // And a review attempt on a foreign order 404s.
        $this->actingAs($this->admin)
            ->post(route('sales.orders.suspicious-review', $shadowOrder), [
                'decision' => SuspiciousOrderFlag::DECISION_LEGITIMATE,
            ])
            ->assertNotFound();
    }

    public function test_menu_leaf_is_gated_by_the_review_permission(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Fake / Suspicious Orders');

        $limited = $this->makeUser();
        $limited->roles()->attach($this->roleWith([
            'portal.erp.access', 'dashboard.view', 'sales.orders.view',
        ])->id);

        $this->actingAs($limited)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Fake / Suspicious Orders');
    }
}
