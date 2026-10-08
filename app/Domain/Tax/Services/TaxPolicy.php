<?php

namespace App\Domain\Tax\Services;

use App\Domain\Masters\Services\TaxService;
use App\Domain\Settings\Services\SettingService;

/**
 * §15-14 — how this company computes tax.
 *
 * `TaxService` answers *what rate* a code carries on a date. This answers the
 * questions around it, which are policy rather than data, and it is the only
 * place they are answered — because every one of them changes money on a
 * document:
 *
 *  · **inclusive or exclusive.** A Bangladeshi shop quotes the price the
 *    customer pays. When that price already includes VAT, the tax has to be
 *    *taken out of it*, not added on top, or the customer is charged twice and
 *    the invoice disagrees with the shelf. The extraction is done on the same
 *    figure the totals came from, so an invoice can never show a taxable value
 *    plus VAT that does not add up to what is being asked for.
 *  · **where the rounding happens.** Rounding each line and adding up is not
 *    the same number as adding up and rounding once, and on an order with
 *    fractions it differs by a paisa or two. Both are legitimate; picking one
 *    silently is not, and the invoice's own tax line has to match whichever is
 *    in force.
 *  · **the nearest taka.** Counter-friendly and ledger-hostile unless the
 *    difference is written down — the invoice's `rounding` column carries it,
 *    so the grand total stays the sum of its parts.
 *  · **which rate an untagged sale uses.** A default code is a real
 *    convenience and a real liability: a typo that names nothing would make
 *    every untagged sale tax-free, so the settings guard refuses a code that is
 *    not an active rate, and this class never invents one.
 *
 * Values are read through {@see SettingService::effective()}, so a branch may
 * price inclusive while head office prices exclusive — the mechanism §15-03
 * built, applied where it changes what a customer pays.
 */
class TaxPolicy
{
    public const ROUNDING_LINE = 'line';

    public const ROUNDING_DOCUMENT = 'document';

    public function __construct(
        protected SettingService $settings,
        protected TaxService $tax,
    ) {}

    /* ------------------------------------------------------------- switches */

    /** Whether the figures being entered are what the customer pays. */
    public function pricesIncludeTax(): bool
    {
        return $this->settings->effectiveBool('tax', 'prices_include_tax', false);
    }

    /** Where the rounding happens: on each line, or once on the document. */
    public function roundingMode(): string
    {
        $mode = (string) $this->settings->effective('tax', 'rounding_mode');

        return $mode === self::ROUNDING_LINE ? self::ROUNDING_LINE : self::ROUNDING_DOCUMENT;
    }

    /** Whether the grand total lands on a whole taka. */
    public function roundsToNearestTaka(): bool
    {
        return $this->settings->effectiveBool('tax', 'round_to_nearest_taka', false);
    }

    /** The rate an untagged taxable sale uses, or null when the company chose none. */
    public function defaultCode(): ?string
    {
        $code = trim((string) $this->settings->effective('tax', 'default_code'));

        return $code === '' ? null : $code;
    }

    /** The form revision printed on the statutory tax invoice, or null when the company declared none. */
    public function mushakFormRevision(): ?string
    {
        $revision = trim((string) $this->settings->effective('tax', 'mushak_form_revision'));

        return $revision === '' ? null : $revision;
    }

    /** An explicit code always wins; the default only fills a gap. */
    public function codeFor(?string $code): ?string
    {
        $code = $code === null ? null : trim($code);

        return ($code === null || $code === '') ? $this->defaultCode() : $code;
    }

    /* --------------------------------------------------------------- money */

    /**
     * One line's money under the policy: the figure that is taxable, and the
     * tax it carries.
     *
     * Exclusive pricing adds the tax to the line; inclusive pricing takes it
     * out, so `net + tax` is the price the person entered either way.
     *
     * @return array{net: float, tax: float}
     */
    public function line(float $net, ?string $code, ?string $at = null): array
    {
        $code = $this->codeFor($code);

        if ($code === null) {
            return ['net' => $net, 'tax' => 0.0];
        }

        $rate = (float) ($this->tax->rateFor($code, $at)?->rate ?? 0.0);

        if ($rate <= 0.0) {
            return ['net' => $net, 'tax' => 0.0];
        }

        if (! $this->pricesIncludeTax()) {
            return ['net' => $net, 'tax' => round($net * $rate / 100, 4)];
        }

        $exclusive = round($net / (1 + $rate / 100), 4);

        return ['net' => $exclusive, 'tax' => round($net - $exclusive, 4)];
    }

    /**
     * A document's tax from its lines' taxes, under the chosen rounding.
     *
     * @param  array<int, float>  $lineTaxes
     */
    public function documentTax(array $lineTaxes): float
    {
        if ($this->roundingMode() === self::ROUNDING_LINE) {
            $rounded = array_map(fn (float $tax): float => round($tax, 2), $lineTaxes);

            return round(array_sum($rounded), 4);
        }

        return round(array_sum($lineTaxes), 4);
    }

    /** The per-line figure to store: rounded when the policy rounds per line. */
    public function storedLineTax(float $tax): float
    {
        return $this->roundingMode() === self::ROUNDING_LINE ? round($tax, 2) : $tax;
    }

    /**
     * The grand total, and the adjustment the rounding introduced — written to
     * the invoice's own `rounding` column so the total stays traceable.
     *
     * @return array{total: float, rounding: float}
     */
    public function roundTotal(float $raw): array
    {
        $total = $this->roundsToNearestTaka() ? round($raw) : round($raw, 2);

        return ['total' => $total, 'rounding' => round($total - $raw, 4)];
    }
}
