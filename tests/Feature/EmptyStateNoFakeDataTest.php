<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-51 — an empty list shows a real empty state, never fabricated rows.
 *
 * A page that has no data must say so honestly. The invoices index, when the
 * company has raised no invoices, renders the shared empty component — not a table
 * padded with "example" rows so the screen does not look empty. This is the one
 * place a fake row is tempting (a demo helps a screenshot) and the one place it is
 * forbidden: a fabricated record is a lie a reader could act on.
 */
class EmptyStateNoFakeDataTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    public function test_an_empty_invoice_list_shows_the_empty_state_without_fabricated_rows(): void
    {
        $admin = $this->bootInstance();
        $this->bindTenantContext($admin, $this->defaultBranch());

        $this->seed(\Database\Seeders\SalesCoreSeeder::class);

        $response = $this->actingAs($admin)->get(route('sales.invoices.index'));

        $response->assertOk();

        // The honest empty state is present...
        $response->assertSee('erp-empty');
        $response->assertSee('No invoices yet');

        // ...and there is no fabricated sample masquerading as a real record. The
        // markers below are the shapes fake demo data takes; none may appear.
        $response->assertDontSee('Lorem');
        $response->assertDontSee('Example Ltd');
        $response->assertDontSee('Acme');
        $response->assertDontSee('Demo Invoice');
        $response->assertDontSee('Sample Customer');
    }
}
