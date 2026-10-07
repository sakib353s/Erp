<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\FiscalPeriod;
use App\Domain\Foundation\FiscalYear;
use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates OPEN fiscal periods for a fiscal year (monthly by default)
 * and provides the close operation used by period-end workflows.
 */
class FiscalPeriodService
{
    public function __construct(protected TenantContext $context) {}

    /**
     * Materialise 12 (or matching) open periods for the given fiscal year.
     * Idempotent: existing period codes are left alone.
     *
     * @return int number of periods created
     */
    public function ensurePeriods(FiscalYear $fiscalYear, string $interval = '1 month'): int
    {
        $companyId = $this->context->companyId() ?? $fiscalYear->company_id;

        return DB::transaction(function () use ($fiscalYear, $companyId, $interval) {
            $created = 0;
            $cursor = Carbon::parse($fiscalYear->starts_on)->startOfDay();
            $end = Carbon::parse($fiscalYear->ends_on)->endOfDay();
            $periodNo = 0;

            while ($cursor->lte($end)) {
                $periodStart = $cursor->copy();
                $periodEnd = $cursor->copy()->add($interval)->subDay();
                if ($periodEnd->gt($end)) {
                    $periodEnd = $end->copy();
                }

                $periodNo++;
                $code = sprintf(
                    '%s-P%02d',
                    $fiscalYear->code,
                    $periodNo,
                );

                $exists = FiscalPeriod::query()
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->exists();

                if (! $exists) {
                    FiscalPeriod::create([
                        'company_id' => $companyId,
                        'fiscal_year_id' => $fiscalYear->id,
                        'code' => $code,
                        'name' => sprintf(
                            '%s %s',
                            $fiscalYear->name,
                            $periodStart->format('M Y'),
                        ),
                        'period_no' => $periodNo,
                        'starts_on' => $periodStart->toDateString(),
                        'ends_on' => $periodEnd->toDateString(),
                        'status' => 'open',
                    ]);
                    $created++;
                }

                $cursor = $periodEnd->addDay()->startOfDay();
            }

            return $created;
        });
    }

    /**
     * Lock a period: close rejects further postings (gate for tests).
     */
    public function close(FiscalPeriod $period, ?int $userId = null): FiscalPeriod
    {
        if ($period->isClosed()) {
            return $period; // idempotent
        }

        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $userId,
        ]);

        return $period;
    }

    public function open(FiscalPeriod $period): FiscalPeriod
    {
        $period->update([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ]);

        return $period;
    }

    /**
     * Find the open period covering a date (creates fiscal-year periods
     * lazily from the current fiscal year if none exist yet).
     */
    public function periodFor(Carbon $date): FiscalPeriod
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $period = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();

        if ($period !== null) {
            return $period;
        }

        $fiscalYear = FiscalYear::query()
            ->where('company_id', $companyId)
            ->where('is_current', true)
            ->first();

        if ($fiscalYear === null) {
            throw new RuntimeException('No current fiscal year is configured.');
        }

        $this->ensurePeriods($fiscalYear);

        return FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->firstOrFail();
    }
}
