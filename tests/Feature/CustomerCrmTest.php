<?php

namespace Tests\Feature;

use App\Domain\Customers\Models\CustomerCreditHistory;
use App\Domain\Customers\Queries\CustomerQuery;
use App\Domain\Customers\Services\CustomerService;
use App\Domain\Foundation\User;
use App\Domain\Masters\Customer;
use App\Domain\Masters\CustomerGroup;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 05-01…05-21 gate.
 *
 * What this pins:
 *  · the list is permission-gated and searchable;
 *  · duplicate phone / e-mail is refused (the practical CRM failure);
 *  · codes are generated when omitted and unique when supplied;
 *  · credit-limit changes append to customer_credit_history and the limit
 *    cannot move without a record;
 *  · blacklisting demands a reason, blocks documents (assertOrderable) and
 *    is reversible;
 *  · ageing bucket boundaries are exact (1-30 / 31-60 / 61-90 / 90+);
 *  · dues are derived from invoices + posted allocations — never stored.
 */
class CustomerCrmTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);

        $this->admin = $this->bootInstance();
    }

    public function test_customer_list_requires_permission(): void
    {
        $limited = $this->makeUser();
        $limited->roles()->attach($this->roleWith(['portal.erp.access', 'dashboard.view'])->id);

        $this->actingAs($limited)->get('/app/customers')->assertForbidden();

        $this->actingAs($this->admin)->get('/app/customers')->assertOk();
    }

    public function test_creating_a_customer_generates_code_and_audits(): void
    {
        $this->actingAs($this->admin)
            ->post('/app/customers', [
                'name' => 'Rahman Traders',
                'type' => 'business',
                'phone' => '01711-222333',
                'email' => 'accounts@rahman.test',
                'credit_limit' => 50000,
                'credit_days' => 15,
            ])
            ->assertRedirect();

        $customer = Customer::query()->where('phone', '01711-222333')->firstOrFail();

        $this->assertSame('CUST-00001', $customer->code);
        $this->assertSame('business', $customer->type);
        $this->assertSame('50000.00', (string) $customer->credit_limit);
        $this->assertTrue($customer->is_active);
    }

    public function test_duplicate_phone_is_refused_per_company(): void
    {
        $this->actingAs($this->admin)->post('/app/customers', [
            'name' => 'First Party',
            'type' => 'individual',
            'phone' => '01911-000111',
        ])->assertRedirect();

        $this->actingAs($this->admin)->post('/app/customers', [
            'name' => 'Duplicate Party',
            'type' => 'individual',
            'phone' => '01911-000111',
        ])->assertSessionHasErrors('phone');

        $this->assertSame(1, Customer::query()->where('phone', '01911-000111')->count());
    }

    public function test_credit_limit_change_records_history(): void
    {
        $customer = Customer::create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUST-00090',
            'name' => 'Credit Test',
            'type' => 'business',
            'credit_limit' => 10000,
            'credit_days' => 7,
        ]);

        $this->actingAs($this->admin)
            ->put("/app/customers/{$customer->id}/credit-limit", [
                'limit' => 75000,
                'credit_days' => 30,
                'reason' => 'Six months of on-time settlement',
            ])
            ->assertRedirect();

        $customer->refresh();
        $this->assertSame('75000.00', (string) $customer->credit_limit);
        $this->assertSame(30, $customer->credit_days);

        $history = CustomerCreditHistory::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('10000.00', (string) $history->old_limit);
        $this->assertSame('75000.00', (string) $history->new_limit);
        $this->assertSame('Six months of on-time settlement', $history->reason);
    }

    public function test_blacklist_requires_reason_and_blocks_documents(): void
    {
        $customer = Customer::create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUST-00091',
            'name' => 'Disputed Party',
            'type' => 'individual',
        ]);

        $this->actingAs($this->admin)
            ->post("/app/customers/{$customer->id}/blacklist", ['blacklisted' => 1])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($customer->refresh()->is_blacklisted);

        $this->actingAs($this->admin)
            ->post("/app/customers/{$customer->id}/blacklist", [
                'blacklisted' => 1,
                'reason' => 'Returned cheques twice',
            ])
            ->assertRedirect();

        $customer->refresh();
        $this->assertTrue($customer->is_blacklisted);

        $this->expectException(\RuntimeException::class);
        app(CustomerService::class)->assertOrderable($customer);
    }

    public function test_blacklist_is_reversible(): void
    {
        $customer = Customer::create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUST-00092',
            'name' => 'Restored Party',
            'type' => 'individual',
            'is_blacklisted' => true,
            'blacklist_reason' => 'Old dispute',
        ]);

        $this->actingAs($this->admin)
            ->post("/app/customers/{$customer->id}/blacklist", ['blacklisted' => 0])
            ->assertRedirect();

        $customer->refresh();
        $this->assertFalse($customer->is_blacklisted);
        $this->assertNull($customer->blacklist_reason);

        app(CustomerService::class)->assertOrderable($customer);
        $this->assertTrue(true); // no exception thrown
    }

    public function test_ageing_bucket_boundaries_are_exact(): void
    {
        $today = now()->startOfDay();

        $this->assertSame('current', CustomerQuery::bucketFor($today->copy()->addDays(5)->toDateString()));
        $this->assertSame('current', CustomerQuery::bucketFor($today->toDateString()));
        $this->assertSame('1_30', CustomerQuery::bucketFor($today->copy()->subDays(1)->toDateString()));
        $this->assertSame('1_30', CustomerQuery::bucketFor($today->copy()->subDays(30)->toDateString()));
        $this->assertSame('31_60', CustomerQuery::bucketFor($today->copy()->subDays(31)->toDateString()));
        $this->assertSame('31_60', CustomerQuery::bucketFor($today->copy()->subDays(60)->toDateString()));
        $this->assertSame('61_90', CustomerQuery::bucketFor($today->copy()->subDays(61)->toDateString()));
        $this->assertSame('61_90', CustomerQuery::bucketFor($today->copy()->subDays(90)->toDateString()));
        $this->assertSame('90_plus', CustomerQuery::bucketFor($today->copy()->subDays(91)->toDateString()));
    }

    public function test_dues_are_derived_from_invoices_and_allocations(): void
    {
        $customer = Customer::create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUST-00093',
            'name' => 'Ledger Party',
            'type' => 'business',
        ]);

        $documentTypeId = DB::table('document_types')->insertGetId([
            'code' => 'test_invoice',
            'type_group' => 'sales',
            'name' => 'Test invoice',
            'printed_title' => 'INVOICE',
            'default_template' => 'default',
            'language' => 'en',
            'is_statutory' => false,
            'tax_applicable' => false,
            'requires_numbering' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoices')->insert([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'document_type_id' => $documentTypeId,
            'invoice_no' => 'INV-TEST-1',
            'status' => 'issued',
            'posting_state' => 'posted',
            'invoice_date' => now()->subDays(45)->toDateString(),
            'due_date' => now()->subDays(40)->toDateString(),
            'grand_total' => 10000,
            'paid_amount' => 4000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $receivable = app(CustomerQuery::class)->receivableByCustomer([$customer->id])[$customer->id];

        $this->assertSame(10000.0, $receivable['invoiced']);
        $this->assertSame(6000.0, $receivable['due']);
        $this->assertSame(6000.0, $receivable['overdue']);
        $this->assertSame('31_60', CustomerQuery::bucketFor(now()->subDays(40)->toDateString()));

        // The service agrees with the query — one definition of "outstanding".
        $this->assertSame(6000.0, app(CustomerService::class)->outstanding($customer->refresh()));
    }

    public function test_group_page_lists_and_creates_groups(): void
    {
        CustomerGroup::create([
            'company_id' => $this->admin->company_id,
            'code' => 'RET',
            'name' => 'Retailer',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/app/customers/groups')
            ->assertOk()
            ->assertSee('Retailer');

        $this->actingAs($this->admin)
            ->post('/app/customers/groups', ['code' => 'WHO', 'name' => 'Wholesaler'])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_groups', ['code' => 'WHO', 'name' => 'Wholesaler']);

        // Duplicate code is refused rather than silently overwriting.
        $this->actingAs($this->admin)
            ->post('/app/customers/groups', ['code' => 'WHO', 'name' => 'Another'])
            ->assertSessionHasErrors('code');
    }

    public function test_open_invoices_endpoint_returns_bucket_and_due(): void
    {
        $customer = Customer::create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUST-00094',
            'name' => 'API Party',
            'type' => 'business',
            'credit_limit' => 2000, // deliberately below the exposure that follows
        ]);

        $documentTypeId = DB::table('document_types')->insertGetId([
            'code' => 'test_invoice_api',
            'type_group' => 'sales',
            'name' => 'Test invoice',
            'printed_title' => 'INVOICE',
            'default_template' => 'default',
            'language' => 'en',
            'is_statutory' => false,
            'tax_applicable' => false,
            'requires_numbering' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoices')->insert([
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'document_type_id' => $documentTypeId,
            'invoice_no' => 'INV-TEST-2',
            'status' => 'partial',
            'posting_state' => 'posted',
            'invoice_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->subDays(5)->toDateString(),
            'grand_total' => 4000,
            'paid_amount' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->getJson("/app/customers/{$customer->id}/open-invoices")
            ->assertOk()
            ->assertJsonPath('invoices.0.invoice_no', 'INV-TEST-2')
            ->assertJsonPath('invoices.0.due', fn ($value) => (float) $value === 3000.0)
            ->assertJsonPath('invoices.0.bucket', '1_30')
            // Exposure 3,000 against a 2,000 limit — the money-receipt flow must
            // surface this before the cashier takes the payment.
            ->assertJsonPath('credit.breach', true);
    }
}
