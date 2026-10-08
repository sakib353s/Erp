<?php

namespace App\Domain\Masters\Services;

use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Masters\TaxRate;
use Illuminate\Support\Collection;

/**
 * Effective-dated tax resolution (traceability §14-15). Controllers and
 * pricing engines never hard-code a rate — they ask this service, which
 * applies TaxRate::effective() so historical documents keep the rate that
 * was active on their date.
 *
 * Rates are company data, so they are read for the company that is asking:
 * a `tax_rates` row belonging to another company must never price this one's
 * invoice, and “VAT 15” on one company is not an authority for another. When
 * there is no company context (a console command, an early setup step) the
 * filter is skipped rather than guessed at.
 */
class TaxService
{
    public function __construct(protected TenantContext $context) {}

    public function rateFor(string $code, ?string $at = null): ?TaxRate
    {
        return $this->rates($at)
            ->where('code', $code)
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @return Collection<int, TaxRate> */
    public function effectiveRates(?string $at = null, ?string $taxType = null): Collection
    {
        $query = $this->rates($at);

        if ($taxType !== null) {
            $query->where('tax_type', $taxType);
        }

        return $query->orderBy('code')->get();
    }

    /**
     * The active, effective rates of the company asking right now.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TaxRate>
     */
    protected function rates(?string $at = null)
    {
        $query = TaxRate::query()->active()->effective($at);

        $companyId = $this->context->companyId();

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query;
    }

    public function apply(float $base, string $code, ?string $at = null): float
    {
        $rate = $this->rateFor($code, $at);

        if ($rate === null) {
            return 0.0;
        }

        return round($base * ((float) $rate->rate) / 100, 4);
    }
}
