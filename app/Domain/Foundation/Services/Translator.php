<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\Translation;
use App\Domain\Settings\Services\LocalizationService;
use Illuminate\Support\Facades\Cache;

/**
 * DB-driven translations (decision D21): values live in the
 * `translations` table (EN + BN, editable at runtime), loaded once per
 * request through a cache-backed map and falling back:
 *   requested locale → English → caller-provided default.
 *
 * Views call `app(Translator::class)->get($key, $fallback)` so a missing
 * row can never blank the UI.
 */
class Translator
{
    protected ?array $map = null;

    protected ?string $locale = null;

    public function __construct(protected ?LocalizationService $localization = null) {}

    /**
     * Current UI locale: the reader's own choice → **the localization
     * settings group** (§15-07) → the company's stored locale → 'en'.
     *
     * The settings group is the authority because it is the one a person can
     * change without a developer: “Default language” on the localization
     * screen is what the *next* request is rendered in, not a wish. The
     * company column stays as the fallback for an installation that has never
     * opened that screen.
     */
    public function locale(): string
    {
        if ($this->locale !== null) {
            return $this->locale;
        }

        $session = session('locale');

        if (in_array($session, ['en', 'bn'], true)) {
            return $this->locale = $session;
        }

        $configured = ($this->localization ?? app(LocalizationService::class))->locale();

        return $this->locale = in_array($configured, ['en', 'bn'], true) ? $configured : 'en';
    }

    /**
     * Forget the memoised locale. A settings write calls this so that changing
     * the default language takes effect on the next render rather than on the
     * next deploy.
     */
    public function flushLocale(): void
    {
        $this->locale = null;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = in_array($locale, ['en', 'bn'], true) ? $locale : 'en';
        $this->map = null;
    }

    public function get(string $key, ?string $fallback = null): string
    {
        $map = $this->load();
        $locale = $this->locale();

        $value = $map[$locale][$key] ?? $map['en'][$key] ?? null;

        if ($value !== null && $value !== '') {
            return $value;
        }

        return $fallback ?? $key;
    }

    /** @return array<string, array<string, string>> locale => key => value */
    protected function load(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = Cache::remember(
            'erp.translations.'.app()->environment(),
            now()->addMinutes(5),
            fn () => Translation::query()
                ->get(['locale', 'translation_key', 'value'])
                ->reduce(function (array $carry, $row) {
                    $carry[$row->locale][$row->translation_key] = (string) $row->value;

                    return $carry;
                }, ['en' => [], 'bn' => []]),
        );

        return $this->map;
    }

    public function forget(): void
    {
        $this->map = null;
        Cache::forget('erp.translations.'.app()->environment());
    }
}
