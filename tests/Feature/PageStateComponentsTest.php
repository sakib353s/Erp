<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * §16-51 — the page-state components exist and render the contract.
 *
 * Loading, empty and notice states are shared because a state rendered one way on
 * one page and another on the next is how an interface starts to feel broken.
 * These tests pin the components themselves: the empty state explains the next
 * step and never carries a fake row; the loading state is a skeleton and never a
 * fabricated record; the notice carries its severity in the class, not in a colour
 * pretending to be something else.
 */
class PageStateComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_empty_state_explains_and_carries_no_fake_row(): void
    {
        $html = Blade::render('<x-ui.empty title="Nothing here yet" text="Create one to begin." icon="bi-inbox" />');

        $this->assertStringContainsString('erp-empty', $html);
        $this->assertStringContainsString('Nothing here yet', $html);
        $this->assertStringContainsString('Create one to begin.', $html);

        // The contract: an empty state is never dressed up as a table of samples.
        $this->assertStringNotContainsString('<tr', $html);
        $this->assertStringNotContainsString('Lorem', $html);
    }

    public function test_the_loading_state_is_a_skeleton_not_fake_data(): void
    {
        $html = Blade::render('<x-ui.loading :rows="3" />');

        $this->assertStringContainsString('erp-loading', $html);
        $this->assertStringContainsString('erp-skeleton', $html);
        // No real content is implied to have loaded.
        $this->assertStringNotContainsString('erp-status', $html);
    }

    public function test_the_notice_carries_severity_in_the_class(): void
    {
        $success = Blade::render('<x-ui.notice type="success" title="Saved">The record was saved.</x-ui.notice>');
        $danger = Blade::render('<x-ui.notice type="danger">Could not connect.</x-ui.notice>');

        $this->assertStringContainsString('erp-notice-success', $success);
        $this->assertStringContainsString('The record was saved.', $success);
        $this->assertStringNotContainsString('erp-notice-danger', $success);

        $this->assertStringContainsString('erp-notice-danger', $danger);
        $this->assertStringContainsString('Could not connect.', $danger);
        // The notice is a page-state banner, not a record status badge.
        $this->assertStringNotContainsString('erp-status', $danger);
    }
}
