<?php

namespace App\Domain\Settings\Services;

use App\Domain\CashBank\Support\AmountInWords;
use App\Domain\Foundation\Company;
use DateTimeInterface;

/**
 * §15-07 — what the Bengali/localization switches actually mean.
 *
 * Four switches on a settings screen are worth nothing unless the documents
 * obey them, and this class is the single place where they are read and
 * applied. It is deliberately the *only* caller of the group for print work:
 * a receipt that formats its own numbers is how an installation ends up with
 * ৳12,34,567.00 on the invoice and 12,345,67.00 on the delivery challan, and
 * nobody can tell which one the customer will query.
 *
 * The rules it implements are the country's, not a preference:
 *
 *  · **grouping** — lakh and crore, so twelve lakh thirty-four thousand five
 *    hundred and sixty-seven is 12,34,567, not 1,234,567. The subcontinent
 *    reads its own grouping; the western one is only used when the company
 *    says it trades that way.
 *  · **digits** — বাংলা numerals for print, when asked. The figures stay the
 *    same figures: this is a transliteration, not a conversion, and it is
 *    applied after grouping so the separators land in the right places.
 *  · **words** — an amount in words is the figure a bank and a court read, so
 *    it is produced from the same number the totals were computed from, never
 *    from a formatted string that could already have lost a paisa.
 *
 * Values are read through {@see SettingService::effective()}, which means a
 * branch may print Bangla numerals while the head office prints western ones —
 * the branch scope built in §15-03 is exactly what makes that honest.
 *
 * Reads are memoised per instance: a printed page asks for the same four
 * switches dozens of times, and a document must not disagree with itself
 * because a setting was read twice at different moments.
 */
class LocalizationService
{
    /** The locales this application can genuinely render. */
    public const LOCALES = ['en', 'bn'];

    protected ?string $locale = null;

    protected ?bool $bengaliFigures = null;

    protected ?bool $lakhCrore = null;

    protected ?bool $amountWords = null;

    public function __construct(protected SettingService $settings) {}

    /* ------------------------------------------------------------- switches */

    /** The language documents and the interface should be in. */
    public function locale(): string
    {
        if ($this->locale !== null) {
            return $this->locale;
        }

        // Read defensively: this is asked for while the interface is being
        // built, including during setup, when there may be no company row and
        // no settings table yet. A missing answer is not an error — it is the
        // company column's turn.
        try {
            $configured = (string) $this->settings->effective('localization', 'default_locale');

            if (in_array($configured, self::LOCALES, true)) {
                return $this->locale = $configured;
            }
        } catch (\Throwable) {
            // fall through to the stored company locale
        }

        $company = Company::current()?->locale;

        return $this->locale = in_array($company, self::LOCALES, true) ? $company : 'en';
    }

    /** Whether print uses বাংলা numerals (০–৯) or western ones. */
    public function bengaliFigures(): bool
    {
        return $this->bengaliFigures ??= $this->settings->effectiveBool('localization', 'bengali_numerals', false);
    }

    /** Whether money is grouped lakh-wise (12,34,567) or western (1,234,567). */
    public function lakhCrore(): bool
    {
        return $this->lakhCrore ??= $this->settings->effectiveBool('localization', 'lakh_crore_format', true);
    }

    /** Whether documents print the amount in words beside the figures. */
    public function amountWordsEnabled(): bool
    {
        return $this->amountWords ??= $this->settings->effectiveBool('localization', 'amount_words_bn', true);
    }

    /* ------------------------------------------------------------ formatting */

    /**
     * A figure as the document should print it: grouped the way the company
     * counts, and in the numerals it asked for.
     */
    public function number(float|int|string $value, int $decimals = 2): string
    {
        $rounded = number_format((float) $value, max(0, $decimals), '.', '');

        $negative = str_starts_with($rounded, '-');
        $parts = explode('.', ltrim($rounded, '-'));
        $whole = $parts[0] === '' ? '0' : $parts[0];
        $fraction = $parts[1] ?? '';

        // Both groupings are done on the digits themselves: a figure is never
        // sent through a float to be punctuated, because a ledger that holds
        // 9999999999999999.99 would come back with somebody else's number.
        $formatted = $this->lakhCrore()
            ? AmountInWords::groupSouthAsian($whole)
            : preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        if ($fraction !== '') {
            $formatted .= '.'.$fraction;
        }

        if ($this->bengaliFigures()) {
            $formatted = AmountInWords::bnDigits($formatted);
        }

        return ($negative ? '-' : '').$formatted;
    }

    /**
     * The amount in words, in the language asked for (the document's language,
     * and its own words for taka and paisa).
     */
    public function words(float|int|string $value, ?string $locale = null): string
    {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : $this->locale();

        return $locale === 'bn'
            ? AmountInWords::bn((string) $value)
            : AmountInWords::en((string) $value);
    }

    /**
     * A quantity: no grouping (nobody writes 1,20 items), trailing zeros
     * trimmed, and the same numerals as everything else on the page — so a
     * Bangla slip does not print “৩ × ৳১২৫.০০”.
     */
    public function qty(float|int|string $value, int $decimals = 4): string
    {
        $formatted = number_format((float) $value, max(0, $decimals), '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        $formatted = $formatted === '' ? '0' : $formatted;

        return $this->bengaliFigures() ? AmountInWords::bnDigits($formatted) : $formatted;
    }

    /** A date as the document should print it — no locale surprises in a filename. */
    public function date(?DateTimeInterface $date, string $format = 'd M Y'): string
    {
        return $date === null ? '' : $date->format($format);
    }

    /**
     * Everything a print view needs, in one array — so a template asks for
     * `$localization['money']` instead of re-deriving the rules.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale(),
            'bengali_figures' => $this->bengaliFigures(),
            'lakh_crore' => $this->lakhCrore(),
            'amount_words' => $this->amountWordsEnabled(),
        ];
    }
}
