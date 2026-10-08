<?php

namespace Tests\Feature;

use App\Domain\Foundation\Services\NavigationBuilder;
use App\Domain\Foundation\Services\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-50 — the navigation is rendered in the reader's language, not just the
 * chrome around it.
 *
 * The sidebar, the ⌘K palette and the breadcrumb all read their labels from the
 * seeded `translations` table through the menu rows' own `label_key`. Two things
 * are pinned here:
 *
 *  · under the default language the labels are English, and a module the reader
 *    can open shows its English name;
 *  · once the reader has chosen Bangla (their session choice wins, per the
 *    translator's rule), the same builder returns the Bangla name for that module
 *    and never the English one — the sidebar is the one place a non-English
 *    reader lives all day, so it cannot be the part that stays English.
 *
 * The builder resolves the key through `t()`, which falls back to the stored
 * English `label` when a Bangla row is missing — so a half-translated catalogue
 * degrades to English per row, it never blanks a link.
 */
class MenuTranslatedTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(\Database\Seeders\FoundationPermissionSeeder::class);
        $this->seed(\Database\Seeders\NavigationSeeder::class);
        $this->seed(\Database\Seeders\TranslationSeeder::class);

        // The translator is a singleton whose map is cached; drop it so this test
        // reads the rows it just seeded, not a map from a previous test.
        app(\App\Domain\Foundation\Services\Translator::class)->forget();
    }

    /** Flat list of every label the builder hands back for the sidebar. */
    protected function sidebarLabels(): array
    {
        $items = app(NavigationBuilder::class)->sidebar($this->admin);

        $labels = [];
        $walk = function (array $groups) use (&$labels, &$walk): void {
            foreach ($groups as $group) {
                $labels[] = $group['label'];
                if (! empty($group['items'])) {
                    $walk($group['items']);
                }
            }
        };
        $walk($items);

        return $labels;
    }

    public function test_the_default_language_renders_english_module_names(): void
    {
        app(Translator::class)->setLocale('en');

        $this->assertContains('Dashboard', $this->sidebarLabels());
        $this->assertNotContains('ড্যাশবোর্ড', $this->sidebarLabels());
    }

    public function test_a_reader_who_chooses_bangla_sees_bangla_module_names(): void
    {
        // The reader's own choice wins over the default (§15-07 / D21).
        session(['locale' => 'bn']);
        app(Translator::class)->setLocale('bn');

        $labels = $this->sidebarLabels();

        $this->assertContains('ড্যাশবোর্ড', $labels);
        $this->assertNotContains('Dashboard', $labels);
    }

    public function test_the_command_palette_is_translated_too(): void
    {
        session(['locale' => 'bn']);
        app(Translator::class)->setLocale('bn');

        $entries = app(NavigationBuilder::class)->palette($this->admin);

        $labels = array_column($entries, 'label');

        $this->assertNotEmpty($labels);
        $this->assertContains('ড্যাশবোর্ড', $labels);
        $this->assertNotContains('Dashboard', $labels);
    }

    public function test_a_missing_bangla_row_falls_back_to_the_english_label(): void
    {
        // A module that has a Bangla row normally renders in Bangla…
        session(['locale' => 'bn']);
        app(Translator::class)->setLocale('bn');
        $this->assertContains('ড্যাশবোর্ড', $this->sidebarLabels());

        // …but if that row is removed, the sidebar must keep the English label
        // rather than blank the link — the fallback is the stored English.
        \App\Domain\Foundation\Translation::query()
            ->where('locale', 'bn')
            ->where('translation_key', 'module.dashboard')
            ->delete();

        app(Translator::class)->forget();

        $this->assertContains('Dashboard', $this->sidebarLabels());
        $this->assertNotContains('ড্যাশবোর্ড', $this->sidebarLabels());
    }
}
