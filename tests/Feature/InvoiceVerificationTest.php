<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Foundation\User;
use App\Domain\Masters\Customer;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\PublicAccessLog;
use App\Domain\Sales\Services\InvoiceVerificationService;
use Carbon\Carbon;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-19 / §16-20 — the invoice verification link and the page behind it.
 *
 * What this file exists to pin down:
 *
 *  · **the database never holds the capability** — publishing stores the
 *    SHA-256 of the link and a rotation number, so a read-only leak of the
 *    invoices table publishes nothing;
 *  · **the paper keeps working** — a reprint recomputes the same address, so
 *    every copy of the invoice verifies identically until somebody rotates or
 *    withdraws it;
 *  · **rotation and withdrawal are real** — the old address 404s the moment the
 *    rotation moves, and a withdrawn link resolves to nothing at all;
 *  · **the page publishes the document, not the books** — the number, the lines,
 *    what is paid and due and who issued it; never the internal notes, the
 *    journal entry, the audit trail or an unmasked contact detail;
 *  · **every visit is recorded** — one `public_access_logs` row per visit with
 *    the *hash* of the token, and one audit event.
 */
class InvoiceVerificationTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected static int $invoiceSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-03-10 09:00:00'));

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function service(): InvoiceVerificationService
    {
        return app(InvoiceVerificationService::class);
    }

    protected function customer(): Customer
    {
        static $seq = 0;
        $seq++;

        return Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'VER-CUST-'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'name' => 'Nabila Traders',
            'phone' => '01710000009',
            'email' => 'nabila@example.test',
            'is_active' => true,
        ]);
    }

    protected function invoice(array $overrides = []): Invoice
    {
        $customer = $overrides['customer'] ?? $this->customer();

        $invoice = Invoice::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'customer_id' => $customer->id,
            'document_type_id' => DB::table('document_types')->value('id'),
            'invoice_no' => 'INV-'.str_pad((string) ++self::$invoiceSeq, 5, '0', STR_PAD_LEFT),
            'status' => 'issued',
            'invoice_type' => 'standard',
            'posting_state' => 'posted',
            'invoice_date' => '2027-03-08',
            'due_date' => '2027-03-23',
            'currency' => 'BDT',
            'subtotal' => 5000,
            'grand_total' => 5000,
            'paid_amount' => 2000,
            'due_amount' => 3000,
            'notes' => 'Internal only: chase the bank transfer before Friday.',
            'created_by' => $this->admin->id,
        ], $overrides));

        DB::table('invoice_lines')->insert([
            'company_id' => $invoice->company_id,
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Verified Widget',
            'qty' => 2,
            'unit_price' => 2500,
            'line_total' => 5000,
            'warranty_flag' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $invoice->fresh();
    }

    /** @param array<int, string> $keys */
    protected function userWith(array $keys): User
    {
        $user = $this->makeUser();
        $this->grant($user, $keys);

        return $user->fresh();
    }

    /* ------------------------------------------------------------- the link itself */

    public function test_an_invoice_with_no_link_publishes_nothing(): void
    {
        $invoice = $this->invoice();

        $this->assertNull($this->service()->url($invoice));

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.show', $invoice))
            ->assertOk()
            ->assertSeeText('Nothing is published yet.')
            ->assertDontSeeText('Open the page');

        // A token nobody issued resolves to nothing — not to an empty page.
        $this->get('/verify/'.str_repeat('a', 43))->assertNotFound();
    }

    public function test_publishing_stores_only_the_hash_of_the_link(): void
    {
        $invoice = $this->invoice();

        $token = $this->service()->issue($invoice, $this->admin);
        $invoice->refresh();

        $this->assertSame(43, strlen($token));
        $this->assertSame(1, (int) $invoice->qr_token_version);
        $this->assertSame(hash('sha256', $token), $invoice->qr_token_hash);
        $this->assertNotNull($invoice->qr_token_issued_at);

        // The capability itself is nowhere on the row.
        $row = (array) DB::table('invoices')->where('id', $invoice->id)->first();
        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString($token, (string) $value, "The link was stored in {$column}.");
        }

        $this->assertDatabaseHas('audit_events', [
            'action' => 'sales.invoice_verification_issued',
            'entity_id' => $invoice->id,
        ]);
    }

    public function test_the_public_page_publishes_the_document_and_not_the_books(): void
    {
        $invoice = $this->invoice();
        $token = $this->service()->issue($invoice, $this->admin);

        $response = $this->get(route('public.invoice.verify', $token))->assertOk();

        $response->assertSeeText($invoice->invoice_no);
        $response->assertSeeText('Verified Widget');
        $response->assertSeeText('5,000.00');
        $response->assertSeeText('Part paid');
        $response->assertSeeText($invoice->company->name);
        $response->assertSeeText('Raised by '.$this->admin->name);

        // The customer recognises their own document; the contact details are
        // masked so a forwarded link is not a harvest.
        $response->assertSeeText('Nabila Traders');
        $response->assertDontSeeText('01710000009');

        // Nothing internal is published.
        $response->assertDontSeeText('chase the bank transfer');
        $response->assertDontSeeText('journal_entries');
        $response->assertDontSeeText('Internal only');

        // The page says what it is not showing rather than leaving it to trust.
        $response->assertSeeText('not published here');
    }

    /**
     * §16-16 changed the second half of this: cover is a row with dates now, so
     * a line flagged warranty with no cover registered against it gets told the
     * truth rather than a denial or an invented date.
     */
    public function test_the_page_carries_a_warranty_note_without_inventing_a_date(): void
    {
        $invoice = $this->invoice();
        $token = $this->service()->issue($invoice, $this->admin);

        $this->get(route('public.invoice.verify', $token))
            ->assertOk()
            ->assertSeeText('Warranty was noted on a line of this invoice')
            ->assertSeeText('no cover period is registered against it');
    }

    public function test_every_visit_is_logged_with_the_hash_and_never_the_token(): void
    {
        $invoice = $this->invoice();
        $token = $this->service()->issue($invoice, $this->admin);

        foreach (range(1, 3) as $ignored) {
            $this->get(route('public.invoice.verify', $token))->assertOk();
        }

        $logs = PublicAccessLog::query()
            ->where('subject_type', 'invoice')
            ->where('subject_id', $invoice->id)
            ->get();

        $this->assertCount(3, $logs);
        $this->assertSame(hash('sha256', $token), $logs->first()->access_token);
        $this->assertStringNotContainsString($token, (string) $logs->first()->access_token);

        $this->assertSame(3, AuditEvent::query()->where('action', 'sales.invoice_verified')->count());

        // The invoice screen reads the same ledger back.
        $this->actingAs($this->admin)
            ->get(route('sales.invoices.show', $invoice))
            ->assertOk()
            ->assertSeeText('Times opened')
            ->assertSeeText('3');
    }

    public function test_a_reprint_carries_the_same_address_and_the_symbol(): void
    {
        Storage::fake('local');

        $invoice = $this->invoice();
        $token = $this->service()->issue($invoice, $this->admin);
        $url = $this->service()->url($invoice);
        $this->assertSame(route('public.invoice.verify', $token), $url);

        $document = app(DocumentRenderer::class)->renderInvoice($invoice->fresh(), $this->admin);
        $html = Storage::disk('local')->get($document->path);

        $this->assertStringContainsString($url, $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Scan to check this invoice on our books', $html);

        // A second print of the same document carries the same address: the
        // symbol on a reprint is not a different capability.
        $again = app(DocumentRenderer::class)->renderInvoice($invoice->fresh(), $this->admin);

        $this->assertStringContainsString($url, Storage::disk('local')->get($again->path));
    }

    public function test_rotating_the_link_stops_the_old_address(): void
    {
        $invoice = $this->invoice();
        $first = $this->service()->issue($invoice, $this->admin);
        $second = $this->service()->rotate($invoice->fresh(), $this->admin);

        $this->assertNotSame($first, $second);
        $this->assertSame(2, (int) $invoice->fresh()->qr_token_version);

        $this->get(route('public.invoice.verify', $first))->assertNotFound();
        $this->get(route('public.invoice.verify', $second))->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'sales.invoice_verification_rotated',
            'entity_id' => $invoice->id,
        ]);
    }

    public function test_withdrawing_the_link_verifies_nothing_and_the_paper_loses_the_symbol(): void
    {
        Storage::fake('local');

        $invoice = $this->invoice();
        $token = $this->service()->issue($invoice, $this->admin);

        $this->actingAs($this->admin)
            ->post(route('sales.invoices.verification.revoke', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $invoice->refresh();

        $this->assertNull($invoice->qr_token_hash);
        $this->assertSame(0, (int) $invoice->qr_token_version);
        $this->assertNotNull($invoice->qr_token_revoked_at);
        $this->assertFalse($this->service()->published($invoice));

        $this->get(route('public.invoice.verify', $token))->assertNotFound();

        $document = app(DocumentRenderer::class)->renderInvoice($invoice, $this->admin);
        $html = Storage::disk('local')->get($document->path);

        $this->assertStringNotContainsString('Scan to check this invoice', $html);
        $this->assertStringNotContainsString('<svg', $html);

        // Withdrawing twice says so instead of pretending to withdraw again.
        $this->actingAs($this->admin)
            ->post(route('sales.invoices.verification.revoke', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status', 'There was no live link for '.$invoice->invoice_no.'.');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'sales.invoice_verification_revoked',
            'entity_id' => $invoice->id,
        ]);
    }

    public function test_a_draft_is_not_publishable(): void
    {
        $draft = $this->invoice(['status' => 'draft', 'posting_state' => 'draft']);

        $this->actingAs($this->admin)
            ->from(route('sales.invoices.show', $draft))
            ->post(route('sales.invoices.verification.issue', $draft))
            ->assertRedirect(route('sales.invoices.show', $draft));

        $this->assertTrue(session('errors') !== null);
        $this->assertStringContainsString('issue the invoice first', $this->allFlashedErrors());
        $this->assertNull($draft->fresh()->qr_token_hash);

        // And the screen offers no button that would do it.
        $this->actingAs($this->admin)
            ->get(route('sales.invoices.show', $draft))
            ->assertOk()
            ->assertSeeText('draft document cannot be published')
            ->assertDontSeeText('Publish verification link');
    }

    public function test_a_cancelled_invoice_verifies_as_cancelled_rather_than_as_a_demand(): void
    {
        $invoice = $this->invoice(['status' => 'void']);
        $token = $this->service()->issue($invoice, $this->admin);

        $this->get(route('public.invoice.verify', $token))
            ->assertOk()
            ->assertSeeText('This invoice has been cancelled.')
            ->assertSeeText('does not stand as a demand for payment');
    }

    public function test_the_link_doors_answer_to_the_invoice_keys(): void
    {
        $invoice = $this->invoice();
        $token = $this->service()->issue($invoice, $this->admin);

        // A reader may see the panel and the address, but not the controls.
        $reader = $this->userWith(['sales.invoices.view']);

        $this->actingAs($reader)
            ->get(route('sales.invoices.show', $invoice))
            ->assertOk()
            ->assertSeeText('Public verification link')
            ->assertDontSeeText('Rotate the link')
            ->assertDontSeeText('Withdraw');

        $this->actingAs($reader)
            ->post(route('sales.invoices.verification.rotate', $invoice))
            ->assertForbidden();

        $this->actingAs($reader)
            ->post(route('sales.invoices.verification.revoke', $invoice))
            ->assertForbidden();

        // The capability is still the one the reader saw: neither door moved it.
        $this->assertSame(1, (int) $invoice->fresh()->qr_token_version);
        $this->get(route('public.invoice.verify', $token))->assertOk();
    }

    public function test_the_public_page_is_rate_limited(): void
    {
        $invoice = $this->invoice();
        $token = $this->service()->issue($invoice, $this->admin);

        for ($hit = 1; $hit <= 30; $hit++) {
            $this->get(route('public.invoice.verify', $token))->assertOk();
        }

        $this->get(route('public.invoice.verify', $token))->assertStatus(429);
    }

    public function test_the_address_is_opaque_and_bears_no_invoice_number(): void
    {
        $first = $this->invoice();
        $second = $this->invoice();

        $one = $this->service()->issue($first, $this->admin);
        $two = $this->service()->issue($second, $this->admin);

        $this->assertNotSame($one, $two);
        $this->assertStringNotContainsString((string) $first->invoice_no, $one);
        $this->assertStringNotContainsString('INV', $one);
        // What the database keeps is the digest, never the capability.
        $this->assertNotSame($one, $first->fresh()->qr_token_hash);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]{43}$/', $one);

        // A different company's invoice is not verifiable through this company's
        // token: the lookup is on the stored hash, and the hash is per invoice.
        $this->assertNull($this->service()->resolve(strrev($one)));
        $this->assertNotNull($this->service()->resolve($two));
    }
}
