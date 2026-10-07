<?php

namespace App\Domain\Masters\Services;

use App\Domain\Masters\TaxRate;
use Illuminate\Support\Collection;

/**
 * Effective-dated tax resolution (traceability §14-15). Controllers and
 * pricing engines never hard-code a rate — they ask this service, which
 * applies TaxRate::effective() so historical documents keep the rate that
 * was active on their date.
 */
class TaxService
{
    public function rateFor(string $code, ?string $at = null): ?TaxRate
    {
        return TaxRate::query()
            ->where('code', $code)
            ->active()
            ->effective($at)
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @return Collection<int, TaxRate> */
    public function effectiveRates(?string $at = null, ?string $taxType = null): Collection
    {
        $query = TaxRate::query()->active()->effective($at);

        if ($taxType !== null) {
            $query->where('tax_type', $taxType);
        }

        return $query->orderBy('code')->get();
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
