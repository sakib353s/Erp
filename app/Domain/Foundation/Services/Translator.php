<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Translation;
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

    /** Current UI locale: session override → company default → 'en'. */
    public function locale(): string
    {
        if ($this->locale !== null) {
            return $this->locale;
        }

        $session = session('locale');

        if (in_array($session, ['en', 'bn'], true)) {
            return $this->locale = $session;
        }

        $companyLocale = Company::current()->locale ?? null;

        return $this->locale = in_array($companyLocale, ['en', 'bn'], true) ? $companyLocale : 'en';
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
