<?php

namespace Tests\Feature;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\Translator;
use App\Domain\Foundation\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-50 — the translation catalogue is complete for the surfaces the requirement
 * names, and the lookup itself is honest.
 *
 * "Full EN+BN translation (menus, buttons, validation, statuses, docs,
 * notifications, reports, templates, settings)" is only true if the dictionary
 * actually carries those surfaces and the reader's language is what comes back. So
 * this suite checks the coverage from both ends:
 *
 *  · **the dictionary** seeded for the surfaces the requirement enumerates — status
 *    vocabulary, buttons, fields, document types, notification copy, report titles,
 *    settings groups, navigation sections and modules — and that every module the
 *    navigation actually renders has a Bangla row (a module with no row would stay
 *    English in the sidebar, which is exactly the gap this row forbids);
 *  · **the lookup** returns Bangla when asked, English when that is the language,
 *    and the key itself (never a blank) when a row is missing entirely — the
 *    fallback chain a missing translation must follow.
 */
class TranslationCoverageTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInstance();
        $this->seed(\Database\Seeders\NavigationSeeder::class);
        $this->seed(\Database\Seeders\TranslationSeeder::class);

        app(\App\Domain\Foundation\Services\Translator::class)->forget();
    }

    /** The surfaces §16-50 names, each represented by a key we must have seeded. */
    public function test_the_dictionary_covers_the_named_surfaces(): void
    {
        $expected = [
            'status.paid', 'status.overdue', 'status.due_soon',
            'btn.save', 'btn.delete', 'btn.search',
            'field.customer', 'field.invoice', 'field.total',
            'doc.invoice', 'doc.quotation', 'doc.delivery_challan',
            'notification.invoice_paid', 'notification.warranty_claimed',
            'report.sales_summary', 'report.profit_loss',
            'setting.localization', 'setting.security',
            'nav.sell', 'nav.money',
            'module.sales', 'module.purchase', 'module.inventory',
        ];

        foreach ($expected as $key) {
            $this->assertNotNull(
                Translation::query()->where('locale', 'bn')->where('translation_key', $key)->first(),
                "The Bangla dictionary is missing a row for {$key}.",
            );
            $this->assertNotNull(
                Translation::query()->where('locale', 'en')->where('translation_key', $key)->first(),
                "The English dictionary is missing a row for {$key}.",
            );
        }
    }

    /** Every module the navigation renders has a Bangla label — none stay English. */
    public function test_every_rendered_module_has_a_bangla_label(): void
    {
        $moduleKeys = MenuItem::query()
            ->whereNotNull('label_key')
            ->where('label_key', 'like', 'module.%')
            ->distinct()
            ->pluck('label_key')
            ->all();

        $this->assertNotEmpty($moduleKeys, 'The navigation renders at least one module.');

        foreach ($moduleKeys as $key) {
            $this->assertNotNull(
                Translation::query()->where('locale', 'bn')->where('translation_key', $key)->first(),
                "Module {$key} would render in English — no Bangla row exists.",
            );
        }
    }

    public function test_the_lookup_returns_the_reader_language_and_falls_back_honestly(): void
    {
        $t = fn (string $key, ?string $fallback = null) => app(Translator::class)->get($key, $fallback);

        app(Translator::class)->setLocale('bn');
        $this->assertSame('পরিশোধিত', $t('status.paid', 'Paid'));

        app(Translator::class)->setLocale('en');
        $this->assertSame('Paid', $t('status.paid', 'Paid'));

        // A key with no row at all returns the caller's English fallback, never blank.
        app(Translator::class)->setLocale('bn');
        $this->assertSame('Not in the dictionary', $t('status.does_not_exist', 'Not in the dictionary'));
    }

    public function test_both_locales_are_present_for_a_core_status(): void
    {
        $this->assertSame(
            'পরিশোধিত',
            Translation::query()->where('locale', 'bn')->where('translation_key', 'status.paid')->value('value'),
        );
        $this->assertSame(
            'Paid',
            Translation::query()->where('locale', 'en')->where('translation_key', 'status.paid')->value('value'),
        );
    }
}
