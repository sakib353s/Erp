<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\PublicAccessLog;
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
 * 02-68 Viewed quotations: `?status=viewed` exists only because a real
 * share-link visit was written to public_access_logs + the audit chain.
 */
class QuotationViewedFromTokenAccessTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected Customer $customer;

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

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'VIEW-1',
            'sku' => 'VIEW-SKU-1',
            'name' => 'Shared Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        $this->customer = Customer::create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUST-VIEW',
            'name' => 'Share Recipient',
            'email' => 'viewer@example.test',
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__quotation-view', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeQuotation(): Quotation
    {
        return app(CreateQuotation::class)->handle([
            'customer_id' => $this->customer->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 200],
            ],
        ], $this->httpRequest());
    }

    protected function send(Quotation $quote): Quotation
    {
        $this->actingAs($this->admin)
            ->post(route('sales.quotations.send', $quote), ['channel' => 'email'])
            ->assertRedirect();

        // Every share-link visit below must come from a logged-out browser
        // with no leftover flash from the preparatory send.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $quote->fresh();
    }

    protected function userWith(array $permissionKeys, string $name): User
    {
        $user = $this->makeUser(['name' => $name]);
        $user->roles()->sync($this->roleWith($permissionKeys)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    public function test_opening_the_share_link_logs_the_visit_and_marks_the_quotation_viewed(): void
    {
        $quote = $this->send($this->makeQuotation());
        $this->assertNotNull($quote->share_token);

        $response = $this->get('/share/quotation/'.$quote->share_token);

        $response->assertOk()
            ->assertSee($quote->quote_no)
            ->assertSee('Shared Product')
            ->assertSee('400.00'); // 2 × 200

        $fresh = $quote->fresh();
        $this->assertSame('viewed', $fresh->status);
        $this->assertNotNull($fresh->viewed_at);

        $log = PublicAccessLog::query()
            ->where('subject_type', 'quotation')
            ->where('subject_id', $quote->id)
            ->firstOrFail();
        $this->assertSame($quote->share_token, $log->access_token);
        $this->assertSame($this->admin->company_id, $log->company_id);
        $this->assertNotNull($log->ip);

        $audit = AuditEvent::query()
            ->where('action', 'sales.quotation_viewed')
            ->where('entity_id', $quote->id)
            ->firstOrFail();
        $this->assertSame('public', $audit->actor_type);
        $this->assertSame('viewed', $audit->after['status']);
    }

    public function test_facets_reflect_only_real_access(): void
    {
        $viewed = $this->send($this->makeQuotation());
        $untouched = $this->send($this->makeQuotation());

        $this->get('/share/quotation/'.$viewed->share_token)->assertOk();

        $this->actingAs($this->admin)
            ->get(route('sales.quotations.index', ['status' => 'viewed']))
            ->assertOk()
            ->assertSee($viewed->quote_no)
            ->assertDontSee($untouched->quote_no);

        $this->actingAs($this->admin)
            ->get(route('sales.quotations.index', ['status' => 'sent']))
            ->assertOk()
            ->assertSee($untouched->quote_no)
            ->assertDontSee($viewed->quote_no);
    }

    public function test_unknown_or_short_tokens_are_not_found_and_log_nothing(): void
    {
        $quote = $this->send($this->makeQuotation());

        $this->get('/share/quotation/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/share/quotation/too-short-token')->assertNotFound();

        $this->assertSame(
            0,
            PublicAccessLog::query()->where('subject_type', 'quotation')->count(),
        );
        $this->assertSame('sent', $quote->fresh()->status);
        $this->assertNull($quote->fresh()->viewed_at);
    }

    public function test_repeated_visits_are_each_logged_without_regressing_status(): void
    {
        $quote = $this->send($this->makeQuotation());

        $this->get('/share/quotation/'.$quote->share_token)->assertOk();
        $firstViewedAt = $quote->fresh()->viewed_at;

        $this->get('/share/quotation/'.$quote->share_token)->assertOk();

        $this->assertSame(
            2,
            PublicAccessLog::query()
                ->where('subject_type', 'quotation')
                ->where('subject_id', $quote->id)
                ->count(),
        );

        $fresh = $quote->fresh();
        $this->assertSame('viewed', $fresh->status);
        $this->assertTrue($fresh->viewed_at->equalTo($firstViewedAt));
    }

    public function test_the_share_page_requires_no_login_and_leaks_no_other_documents(): void
    {
        $shown = $this->send($this->makeQuotation());
        $other = $this->send($this->makeQuotation());

        $this->get('/share/quotation/'.$shown->share_token)
            ->assertOk()
            ->assertDontSee($other->quote_no);

        $this->assertGuest();
    }

    public function test_the_viewed_facet_still_requires_the_quotation_view_permission(): void
    {
        $quote = $this->send($this->makeQuotation());
        $this->get('/share/quotation/'.$quote->share_token)->assertOk();

        $outsider = $this->userWith(['portal.erp.access'], 'No Quote Access');
        $this->actingAs($outsider)
            ->get(route('sales.quotations.index', ['status' => 'viewed']))
            ->assertForbidden();

        $viewer = $this->userWith(['portal.erp.access', 'sales.quotations.view'], 'Quote Viewer');
        $this->actingAs($viewer)
            ->get(route('sales.quotations.index', ['status' => 'viewed']))
            ->assertOk()
            ->assertSee($quote->quote_no);
    }
}
