<?php

namespace App\Domain\Sales\Queries;

use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Invoice;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side queries over the invoice ledger (02-63 overdue drill-down).
 *
 * "Today" is evaluated in the tenant company timezone (branches share the
 * company calendar, decision D-Ref), never in the server default zone.
 */
class InvoiceQuery
{
    public function __construct(protected TenantContext $context) {}

    /**
     * Open invoices whose due date has already passed: issued or partially
     * settled documents that still carry a balance.
     */
    public function overdue(Builder $query, ?CarbonImmutable $asOf = null): Builder
    {
        $asOf = $asOf ?? $this->today();

        return $query
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $asOf->toDateString())
            ->whereIn('status', ['issued', 'partial'])
            ->where('due_amount', '>', 0);
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::today($this->timezone());
    }

    public function timezone(): string
    {
        return (string) ($this->context->company()?->timezone ?: config('app.timezone'));
    }

    /** Whole days between the due date and "today"; 0 when not overdue. */
    public function daysOverdue(Invoice $invoice, ?CarbonImmutable $asOf = null): int
    {
        if ($invoice->due_date === null) {
            return 0;
        }

        $asOf = $asOf ?? $this->today();
        $days = Carbon::parse($invoice->due_date->toDateString())
            ->diffInDays($asOf->toDateString(), false);

        return max(0, (int) round($days));
    }
}
