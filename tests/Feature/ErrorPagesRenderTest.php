<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-51 — error pages are professional and leak nothing.
 *
 * A 404, a 403 and a 500 are all states a reader lands in, and each must read like
 * the product, not like a stack trace. The contract has two halves: the right HTTP
 * status, and a body that explains without exposing internals — no file paths, no
 * SQL, no framework stack. A leaked path or query is how a screenshot becomes a
 * vulnerability.
 */
class ErrorPagesRenderTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    public function test_a_missing_route_renders_a_professional_404(): void
    {
        $response = $this->get('/app/this-route-does-not-exist-'.uniqid());

        $response->assertNotFound();
        $response->assertSee('Page not found');
        $response->assertDontSee('Stack trace');
        $response->assertDontSee('/home/user');
        $response->assertDontSee('SQLSTATE');
    }

    public function test_a_permission_denial_renders_a_professional_403(): void
    {
        $admin = $this->bootInstance();
        $this->bindTenantContext($admin, $this->defaultBranch());

        // A reader with no invoice permission — the gate returns 403, never a 404
        // that would leak whether the id exists.
        $reader = $this->makeUser(['branch_scope' => 'all']);

        $response = $this->actingAs($reader)->get(route('sales.invoices.index'));

        $response->assertForbidden();
        $response->assertSee('403');
        $response->assertDontSee('Stack trace');
        $response->assertDontSee('/home/user');
    }

    public function test_the_500_page_explains_without_leaking_internals(): void
    {
        $html = View::make('errors.500')->render();

        $this->assertStringContainsString('Server error', $html);
        $this->assertStringNotContainsString('Stack trace', $html);
        $this->assertStringNotContainsString('/home/user', $html);
        $this->assertStringNotContainsString('SQLSTATE', $html);
        $this->assertStringNotContainsString('Illuminate\\', $html);
    }
}
