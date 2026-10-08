<?php

namespace App\Domain\Business\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Business\BusinessRecord;
use App\Domain\Business\BusinessRecordEvent;
use App\Domain\Business\BusinessRecordFile;
use App\Domain\Business\RecordsRegistry;
use App\Domain\Documents\Document;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * §12-03/04/09/10 — the registers' engine.
 *
 * Four things happen to a business record, and they are the four verbs this
 * class implements:
 *
 *  · **it is recorded** — created with the number, the issuer, the dates and the
 *    value that make it checkable.
 *  · **it is renewed** — the expiry moves forward and the old date is kept as an
 *    event. A renewal that *shortened* the validity is refused: back-dating a
 *    licence to tidy a screen is how a register stops being evidence.
 *  · **it is completed** — for the half that has deadlines rather than expiry
 *    dates, finishing one sets the last-completed day and rolls the next due date
 *    forward by its cadence, so a monthly return is still a row next month
 *    instead of a row somebody has to remember to re-create.
 *  · **it is retired** — surrendered, replaced or torn up. Nothing is deleted:
 *    an expired licence that was retired is a different fact from a licence that
 *    simply fell off the register, and only one of them is safe to forget.
 *
 * Nothing in here computes a state for the database. States are read from the
 * clock (`BusinessRecord::state()`), because a register that is only true after
 * the nightly job has run is a register that lies for part of every day.
 *
 * Every mutation writes two rows: one on the record's own history
 * (`business_record_events`), which is what the record's page shows, and one in
 * the audit trail, which is what survives the record.
 */
class BusinessRecordService
{
    public function __construct(
        protected RecordsRegistry $registry,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    /* ------------------------------------------------------------- recording */

    /**
     * @param  array{kind: string, title: string, branch_id?: ?int, reference_no?: ?string, issuer?: ?string, counterparty?: ?string, value_amount?: mixed, issued_on?: ?string, starts_on?: ?string, expires_on?: ?string, due_on?: ?string, repeat_months?: mixed, notes?: ?string}  $data
     */
    public function create(array $data, User $actor): BusinessRecord
    {
        $kind = (string) $data['kind'];
        $this->registry->config($kind); // loud on a kind that does not exist

        $payload = $this->payload($kind, $data, [
            'company_id' => $actor->company_id,
            'kind' => $kind,
            'created_by' => $actor->id,
            'status' => BusinessRecord::STATUS_ACTIVE,
        ]);

        $this->assertDates(
            $payload['issued_on'] ?? null,
            $payload['starts_on'] ?? null,
            $payload['expires_on'] ?? null,
        );

        $record = new BusinessRecord($payload);
        $record->save();

        $this->history($record, 'created', $actor, sprintf(
            '%s recorded%s.',
            $this->registry->label($kind),
            $record->reference_no ? ' under '.$record->reference_no : '',
        ));

        $this->audit->record([
            'action' => 'business.record_created',
            'entity_type' => 'business_record',
            'entity_id' => $record->id,
            'actor_id' => $actor->id,
            'branch_id' => $record->branch_id,
            'after' => $this->auditShape($record),
        ]);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BusinessRecord $record, array $data, User $actor): BusinessRecord
    {
        $before = $this->auditShape($record);
        $touched = [];

        // Whatever the dates will be once this edit lands, they still have to
        // make sense: a licence that expires before it was issued is a typo, and
        // a typo on a register is worse than a missing row.
        $this->assertDates(
            array_key_exists('issued_on', $data) ? ($data['issued_on'] ?: null) : $record->issued_on?->toDateString(),
            array_key_exists('starts_on', $data) ? ($data['starts_on'] ?: null) : $record->starts_on?->toDateString(),
            array_key_exists('expires_on', $data) ? ($data['expires_on'] ?: null) : $record->expires_on?->toDateString(),
        );

        foreach (['title', 'reference_no', 'issuer', 'counterparty', 'value_amount', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field] === '' ? null : $data[$field];
                if ((string) $record->{$field} !== (string) $value) {
                    $touched[] = $field;
                    $record->{$field} = $value;
                }
            }
        }

        // A record's dates are its identity; they move through renew/complete, so
        // they are only written here when the request explicitly carries them.
        foreach (['issued_on', 'starts_on', 'expires_on', 'due_on'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field] === '' || $data[$field] === null
                ? null
                : Carbon::parse($data[$field])->toDateString();
            $current = $record->{$field}?->toDateString();

            if ($current !== $value) {
                $touched[] = $field;
                $record->{$field} = $value;
            }
        }

        foreach (['repeat_months', 'branch_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field] === '' ? null : $data[$field];

                if ((string) $record->{$field} !== (string) ($value ?? '')) {
                    $touched[] = $field;
                    $record->{$field} = $value;
                }
            }
        }

        if ($touched === []) {
            return $record; // nothing changed: no event, no audit row
        }

        $record->save();

        $this->history($record, 'updated', $actor, 'Updated '.implode(', ', $touched).'.', [
            'fields' => $touched,
        ]);

        $this->audit->record([
            'action' => 'business.record_updated',
            'entity_type' => 'business_record',
            'entity_id' => $record->id,
            'actor_id' => $actor->id,
            'branch_id' => $record->branch_id,
            'before' => $before,
            'after' => $this->auditShape($record),
        ]);

        return $record;
    }

    /**
     * Renew: extend the expiry, keeping the previous one on the record's history.
     *
     * @param  array{expires_on: string, reference_no?: ?string, value_amount?: mixed, note?: ?string, renewed_on?: ?string}  $data
     */
    public function renew(BusinessRecord $record, User $actor, array $data): BusinessRecord
    {
        if (! $this->registry->isRenewable($record->kind)) {
            throw ValidationException::withMessages([
                'expires_on' => $this->registry->label($record->kind).' has no expiry date to renew — it is a register entry, not a document with a term.',
            ]);
        }

        $expiresOn = Carbon::parse($data['expires_on'])->startOfDay();

        if ($record->expires_on !== null && $expiresOn->lt($record->expires_on->startOfDay())) {
            throw ValidationException::withMessages([
                'expires_on' => 'A renewal cannot move the expiry date backwards. The record expires on '
                    .$record->expires_on->format('d M Y').'; renew it to a later date, or correct the record instead.',
            ]);
        }

        $previous = $record->expires_on?->toDateString();

        $record->forceFill([
            'expires_on' => $expiresOn->toDateString(),
            'reference_no' => $data['reference_no'] ?? $record->reference_no,
            'value_amount' => $data['value_amount'] ?? $record->value_amount,
            'status' => BusinessRecord::STATUS_ACTIVE,
            'retired_on' => null,
        ])->save();

        $this->history($record, 'renewed', $actor, sprintf(
            'Renewed to %s%s.',
            $expiresOn->format('d M Y'),
            $previous ? ' (was '.Carbon::parse($previous)->format('d M Y').')' : '',
        ), [
            'previous_expires_on' => $previous,
            'expires_on' => $expiresOn->toDateString(),
            'renewed_on' => $data['renewed_on'] ?? now()->toDateString(),
        ], $data['note'] ?? null);

        $this->audit->record([
            'action' => 'business.record_renewed',
            'entity_type' => 'business_record',
            'entity_id' => $record->id,
            'actor_id' => $actor->id,
            'branch_id' => $record->branch_id,
            'before' => ['expires_on' => $previous],
            'after' => ['expires_on' => $expiresOn->toDateString()],
            'reason' => $data['note'] ?? null,
        ]);

        return $record;
    }

    /**
     * Complete a recurring record: stamp the day it was done and set the next one.
     *
     * @param  array{completed_on?: ?string, note?: ?string}  $data
     */
    public function complete(BusinessRecord $record, User $actor, array $data = []): BusinessRecord
    {
        if (! $this->registry->isRecurring($record->kind)) {
            throw ValidationException::withMessages([
                'due_on' => $this->registry->label($record->kind).' does not repeat, so there is no next due date to set. Give it a repeat cycle first if it comes round again.',
            ]);
        }

        $completedOn = isset($data['completed_on']) && $data['completed_on']
            ? Carbon::parse($data['completed_on'])->startOfDay()
            : now()->startOfDay();

        $previousDue = $record->due_on?->toDateString();
        $months = (int) ($record->repeat_months ?: 0);

        $nextDue = null;

        if ($months > 0) {
            /*
             * Roll forward from the *deadline*, never from the day the work was
             * done: a monthly return filed three days late is still due on the
             * same day next month, and a calendar that drifts because somebody
             * was late is a calendar nobody can plan against.
             *
             * If so many cycles were missed that the next deadline is still in
             * the past, keep stepping until it is not — the row's next date must
             * be a date somebody can act on. The missing is not hidden: the
             * completion event records the day it was finally done, and the
             * steps skipped are visible as the gap between the two.
             *
             * No-overflow: a 31st becomes the 30th (or 28th) rather than
             * March 3rd.
             */
            $anchor = ($record->due_on ?? $completedOn)->copy();
            $next = $anchor->addMonthsNoOverflow($months);

            $steps = 0;
            while ($next->lessThanOrEqualTo($completedOn) && $steps < 120) {
                $next = $next->addMonthsNoOverflow($months);
                $steps++;
            }

            $nextDue = $next->toDateString();
        }

        $record->forceFill([
            'last_completed_on' => $completedOn->toDateString(),
            'due_on' => $nextDue,
            'status' => BusinessRecord::STATUS_ACTIVE,
            'retired_on' => null,
        ])->save();

        $this->history($record, 'completed', $actor, sprintf(
            'Done on %s.%s',
            $completedOn->format('d M Y'),
            $nextDue ? ' Next due '.Carbon::parse($nextDue)->format('d M Y').'.' : ' It does not repeat.',
        ), [
            'previous_due_on' => $previousDue,
            'completed_on' => $completedOn->toDateString(),
            'next_due_on' => $nextDue,
        ], $data['note'] ?? null);

        $this->audit->record([
            'action' => 'business.record_completed',
            'entity_type' => 'business_record',
            'entity_id' => $record->id,
            'actor_id' => $actor->id,
            'branch_id' => $record->branch_id,
            'before' => ['due_on' => $previousDue],
            'after' => ['due_on' => $nextDue, 'last_completed_on' => $completedOn->toDateString()],
            'reason' => $data['note'] ?? null,
        ]);

        return $record;
    }

    /* -------------------------------------------------------------- evidence */

    public function attach(BusinessRecord $record, Document $document, User $actor, ?string $label = null): BusinessRecordFile
    {
        if ((int) $document->company_id !== (int) $record->company_id) {
            throw new InvalidArgumentException('A document from another company cannot be filed against this record.');
        }

        $file = BusinessRecordFile::firstOrCreate(
            [
                'business_record_id' => $record->id,
                'document_id' => $document->id,
            ],
            [
                'company_id' => $record->company_id,
                'label' => $label,
                'attached_by' => $actor->id,
            ],
        );

        if ($file->wasRecentlyCreated) {
            $this->history($record, 'attached', $actor, 'Filed “'.$file->label().'”.', [
                'document_id' => $document->id,
            ]);

            $this->audit->record([
                'action' => 'business.record_document_attached',
                'entity_type' => 'business_record',
                'entity_id' => $record->id,
                'actor_id' => $actor->id,
                'branch_id' => $record->branch_id,
                'after' => ['document_id' => $document->id, 'label' => $file->label()],
            ]);
        }

        return $file;
    }

    public function detach(BusinessRecord $record, Document $document, User $actor): void
    {
        $file = BusinessRecordFile::query()
            ->where('business_record_id', $record->id)
            ->where('document_id', $document->id)
            ->first();

        if ($file === null) {
            return;
        }

        $label = $file->label();
        $file->delete();

        $this->history($record, 'detached', $actor, 'Unfiled “'.$label.'”. The file itself stays in the document library.', [
            'document_id' => $document->id,
        ]);

        $this->audit->record([
            'action' => 'business.record_document_detached',
            'entity_type' => 'business_record',
            'entity_id' => $record->id,
            'actor_id' => $actor->id,
            'branch_id' => $record->branch_id,
            'before' => ['document_id' => $document->id],
        ]);
    }

    public function retire(BusinessRecord $record, User $actor, ?string $reason = null): BusinessRecord
    {
        if ($record->isRetired()) {
            return $record;
        }

        $record->forceFill([
            'status' => BusinessRecord::STATUS_RETIRED,
            'retired_on' => now()->toDateString(),
        ])->save();

        $this->history($record, 'retired', $actor, 'Retired. '.($reason ?: 'No reason given.'));

        $this->audit->record([
            'action' => 'business.record_retired',
            'entity_type' => 'business_record',
            'entity_id' => $record->id,
            'actor_id' => $actor->id,
            'branch_id' => $record->branch_id,
            'before' => ['status' => BusinessRecord::STATUS_ACTIVE],
            'after' => ['status' => BusinessRecord::STATUS_RETIRED],
            'reason' => $reason,
        ]);

        return $record;
    }

    /* --------------------------------------------------------------- reading */

    /**
     * The register itself.
     *
     * @param  array{kind?: ?string, group?: ?string, state?: ?string, q?: ?string, branch_id?: mixed, retired?: mixed, per_page?: int}  $filters
     */
    public function register(array $filters = []): LengthAwarePaginator
    {
        $query = $this->visible($filters['branch_id'] ?? null);

        if (! empty($filters['kind']) && $this->registry->has((string) $filters['kind'])) {
            $query->ofKind((string) $filters['kind']);
        }

        if (! empty($filters['group']) && array_key_exists((string) $filters['group'], $this->registry->groups())) {
            $query->whereIn('kind', array_keys($this->registry->kindsFor((string) $filters['group'])));
        }

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%")
                    ->orWhere('issuer', 'like', "%{$search}%")
                    ->orWhere('counterparty', 'like', "%{$search}%");
            });
        }

        $this->applyState($query, (string) ($filters['state'] ?? ''), ! empty($filters['retired']));

        if (! empty($filters['kind']) && ! $this->registry->isRenewable((string) $filters['kind']) && ! $this->registry->isRecurring((string) $filters['kind'])) {
            $query->orderBy('title'); // a register of undated papers reads best alphabetically
        } else {
            $query->orderByRaw('coalesce(due_on, expires_on) is null')  // dated first
                ->orderByRaw('coalesce(due_on, expires_on) asc')
                ->orderBy('title');
        }

        return $query->with(['branch', 'creator'])->paginate((int) ($filters['per_page'] ?? 20))->withQueryString();
    }

    /**
     * The renewals lens: what has lapsed, what is about to, what is later — and,
     * separately, the papers that carry no date at all, because "no expiry on
     * file" is not the same as "safe".
     *
     * @return array{lapsed: Collection<int, BusinessRecord>, near: Collection<int, BusinessRecord>, later: Collection<int, BusinessRecord>, undated: Collection<int, BusinessRecord>, horizon: int}
     */
    public function renewals(int $days = RecordsRegistry::HORIZON_DAYS, ?string $kind = null): array
    {
        $query = $this->visible()->active();

        if ($kind !== null && $this->registry->has($kind)) {
            $query->ofKind($kind);
        }

        $dated = (clone $query)
            ->where(fn (Builder $q) => $q->whereNotNull('expires_on')->orWhereNotNull('due_on'))
            ->orderByRaw('coalesce(due_on, expires_on) is null')
            ->orderByRaw('coalesce(due_on, expires_on) asc')
            ->get();

        $undated = (clone $query)
            ->whereNull('expires_on')
            ->whereNull('due_on')
            ->orderBy('title')
            ->get();

        $near = RecordsRegistry::NEAR_DAYS;

        return [
            'lapsed' => $dated->filter(fn (BusinessRecord $r) => (int) $r->daysLeft() < 0)->values(),
            'near' => $dated->filter(fn (BusinessRecord $r) => ($d = (int) $r->daysLeft()) >= 0 && $d <= $near)->values(),
            'later' => $dated->filter(fn (BusinessRecord $r) => (int) $r->daysLeft() > $near && (int) $r->daysLeft() <= $days)->values(),
            'undated' => $undated,
            'horizon' => $days,
        ];
    }

    /**
     * The recurring half: every obligation and filing with a cadence, grouped by
     * how often it comes round, one-off (no cycle) last.
     *
     * @return array<int, array{months: int, label: string, rows: Collection<int, BusinessRecord>}>
     */
    public function obligations(): array
    {
        $kinds = array_keys(array_filter(
            $this->registry->all(),
            fn (array $config) => (bool) $config['due'],
        ));

        $records = $this->visible()->active()->whereIn('kind', $kinds)
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->orderBy('title')
            ->get()
            ->groupBy(fn (BusinessRecord $record) => (int) ($record->repeat_months ?: 0))
            ->sortKeys();

        $groups = [];

        foreach ($records as $months => $rows) {
            $groups[] = [
                'months' => (int) $months,
                'label' => $this->registry->cadenceLabel((int) $months ?: null),
                'rows' => $rows->values(),
            ];
        }

        return $groups;
    }

    /**
     * One month laid out as weeks, with every expiry and every deadline on it.
     *
     * @return array{month: string, label: string, previous: string, next: string, is_current: bool, weeks: array<int, array<int, array<string, mixed>>>, total: int}
     */
    public function calendar(?string $month = null): array
    {
        $anchor = $this->parseMonth($month);
        $start = $anchor->copy()->startOfMonth();
        $end = $anchor->copy()->endOfMonth();

        // Bangladeshi weeks start on Sunday; the grid shows the whole weeks that
        // overlap the month so the first and last days are never orphaned.
        $gridStart = $start->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $end->copy()->endOfWeek(Carbon::SATURDAY);

        $rows = $this->visible()->active()
            ->where(function (Builder $q) use ($gridStart, $gridEnd) {
                $q->where(fn ($inner) => $inner
                    ->whereDate('expires_on', '>=', $gridStart->toDateString())
                    ->whereDate('expires_on', '<=', $gridEnd->toDateString()))
                    ->orWhere(fn ($inner) => $inner
                        ->whereDate('due_on', '>=', $gridStart->toDateString())
                        ->whereDate('due_on', '<=', $gridEnd->toDateString()));
            })
            ->with('branch')
            ->get();

        $entries = [];

        foreach ($rows as $record) {
            foreach (array_filter([$record->expires_on, $record->due_on]) as $date) {
                $entries[$date->toDateString()][] = $record;
            }
        }

        $weeks = [];
        $cursor = $gridStart->copy();

        while ($cursor->lessThanOrEqualTo($gridEnd)) {
            $week = [];

            for ($day = 0; $day < 7; $day++) {
                $key = $cursor->toDateString();
                $inMonth = $cursor->month === $anchor->month && $cursor->year === $anchor->year;

                $today = $cursor->isSameDay(now());

                $week[] = [
                    'date' => $cursor->copy(),
                    'in_month' => $inMonth,
                    'is_today' => $today,
                    'is_past' => $cursor->copy()->endOfDay()->isPast(),
                    'entries' => collect($entries[$key] ?? []),
                ];

                $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return [
            'month' => $anchor->format('Y-m'),
            'label' => $anchor->format('F Y'),
            'previous' => $anchor->copy()->subMonthNoOverflow()->format('Y-m'),
            'next' => $anchor->copy()->addMonthNoOverflow()->format('Y-m'),
            'is_current' => $anchor->isSameMonth(now()),
            'weeks' => $weeks,
            'total' => count($entries),
        ];
    }

    /**
     * Headline counts for the strip at the top of the desk. Counted in PHP from
     * one query rather than in five, because the "state" is a read-time idea and
     * two of those counts would otherwise need date arithmetic in SQL.
     *
     * @return array<string, int>
     */
    public function summary(?string $kind = null): array
    {
        $query = $this->visible();

        if ($kind !== null && $this->registry->has($kind)) {
            $query->ofKind($kind);
        }

        $all = $query->get();

        $counts = [
            'active' => 0,
            'in_force' => 0,
            'expiring' => 0,
            'expired' => 0,
            'due_soon' => 0,
            'overdue' => 0,
            'undated' => 0,
            'retired' => 0,
        ];

        foreach ($all as $record) {
            if ($record->isRetired()) {
                $counts['retired']++;

                continue;
            }

            $counts['active']++;

            match ($record->state()) {
                'valid' => $counts['in_force']++,
                'expiring' => $counts['expiring']++,
                'expired' => $counts['expired']++,
                'due_soon' => $counts['due_soon']++,
                'overdue' => $counts['overdue']++,
                default => $counts['undated']++,
            };
        }

        return $counts;
    }

    /**
     * How many live records each shelf holds — one query for the whole desk.
     *
     * @return array<string, int> kind => count (every kind present, zeros included)
     */
    public function kindCounts(): array
    {
        $counts = $this->visible()->active()
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind')
            ->all();

        $shaped = [];

        foreach (array_keys($this->registry->all()) as $kind) {
            $shaped[$kind] = (int) ($counts[$kind] ?? 0);
        }

        return $shaped;
    }

    /* --------------------------------------------------------------- helpers */

    /** Records this actor may see: their branches, plus the company-wide ones. */
    public function visible(mixed $branchId = null): Builder
    {
        $query = BusinessRecord::query();

        $ids = $this->context->accessibleBranchIds();

        if ($ids !== null) {
            $query->where(function (Builder $q) use ($ids) {
                $q->whereIn('branch_id', $ids)->orWhereNull('branch_id');
            });
        }

        if ($branchId === 'company') {
            $query->whereNull('branch_id');
        } elseif (is_numeric($branchId)) {
            $query->where('branch_id', (int) $branchId);
        }

        return $query;
    }

    private function applyState(Builder $query, string $state, bool $includeRetired): void
    {
        $today = now()->startOfDay();
        $near = $today->copy()->addDays(RecordsRegistry::NEAR_DAYS)->toDateString();
        $now = $today->toDateString();

        if ($state === 'retired') {
            $query->where('status', BusinessRecord::STATUS_RETIRED);

            return;
        }

        if ($state === '') {
            if (! $includeRetired) {
                $query->active();
            }

            return;
        }

        $query->active();

        match ($state) {
            'expired' => $query->whereNotNull('expires_on')->where('expires_on', '<', $now),
            'overdue' => $query->whereNotNull('due_on')->where('due_on', '<', $now),
            'expiring' => $query->whereNotNull('expires_on')
                ->whereDate('expires_on', '>=', $now)->whereDate('expires_on', '<=', $near)
                ->where(fn (Builder $q) => $q->whereNull('due_on')->orWhere('due_on', '>=', $now)),
            'due_soon' => $query->whereNotNull('due_on')->whereDate('due_on', '>=', $now)->whereDate('due_on', '<=', $near),
            'undated' => $query->whereNull('expires_on')->whereNull('due_on'),
            'valid' => $query->where(fn (Builder $q) => $q->where('expires_on', '>', $near)->orWhere('due_on', '>', $near)),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $data */
    private function payload(string $kind, array $data, array $base): array
    {
        $config = $this->registry->config($kind);

        $payload = array_merge($base, [
            'title' => trim((string) $data['title']),
            'branch_id' => $data['branch_id'] ?? null,
            'reference_no' => $this->clean($data['reference_no'] ?? null),
            'issuer' => $config['issuer'] ? $this->clean($data['issuer'] ?? null) : null,
            'counterparty' => $config['party'] ? $this->clean($data['counterparty'] ?? null) : null,
            'value_amount' => $config['value'] ? ($this->clean($data['value_amount'] ?? null) ?? null) : null,
            'issued_on' => $config['issued'] ? ($this->clean($data['issued_on'] ?? null) ?? null) : null,
            'starts_on' => $config['validity'] ? ($this->clean($data['starts_on'] ?? null) ?? null) : null,
            'expires_on' => $config['validity'] ? ($this->clean($data['expires_on'] ?? null) ?? null) : null,
            'due_on' => $config['due'] ? ($this->clean($data['due_on'] ?? null) ?? null) : null,
            'repeat_months' => $config['due'] ? ($this->clean($data['repeat_months'] ?? null) ?? null) : null,
            'notes' => $this->clean($data['notes'] ?? null),
        ]);

        return $payload;
    }

    /**
     * The sanity a register needs but a form cannot express: dates may be
     * recorded in any order (a licence is often entered after it was issued and
     * sometimes even after it lapsed), but the *sequence* has to be possible.
     */
    private function assertDates(?string $issuedOn, ?string $startsOn, ?string $expiresOn): void
    {
        $issued = $issuedOn ? Carbon::parse($issuedOn) : null;
        $starts = $startsOn ? Carbon::parse($startsOn) : null;
        $expires = $expiresOn ? Carbon::parse($expiresOn) : null;

        if ($expires !== null && $issued !== null && $expires->lt($issued)) {
            throw ValidationException::withMessages([
                'expires_on' => 'The expiry date cannot be before the issue date ('.$issued->format('d M Y').').',
            ]);
        }

        if ($expires !== null && $starts !== null && $expires->lt($starts)) {
            throw ValidationException::withMessages([
                'expires_on' => 'The expiry date cannot be before the start date ('.$starts->format('d M Y').').',
            ]);
        }
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = is_string($value) ? trim($value) : $value;

        return $value === '' ? null : (string) $value;
    }

    /**
     * One line of the record's own history. The description the service writes is
     * the note — what a person typed alongside it rides in `meta`, because "what
     * happened" and "what somebody said about it" are two different sentences and
     * the first one must not be lost when the second one exists.
     */
    private function history(BusinessRecord $record, string $action, User $actor, string $note, array $meta = [], ?string $spokenNote = null): BusinessRecordEvent
    {
        if ($spokenNote !== null && trim($spokenNote) !== '') {
            $meta['note'] = trim($spokenNote);
        }

        return BusinessRecordEvent::create([
            'company_id' => $record->company_id,
            'business_record_id' => $record->id,
            'action' => $action,
            'happened_on' => now()->toDateString(),
            'note' => $note,
            'meta' => $meta ?: null,
            'actor_id' => $actor->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function auditShape(BusinessRecord $record): array
    {
        return [
            'kind' => $record->kind,
            'title' => $record->title,
            'reference_no' => $record->reference_no,
            'issuer' => $record->issuer,
            'counterparty' => $record->counterparty,
            'value_amount' => $record->value_amount,
            'expires_on' => $record->expires_on?->toDateString(),
            'due_on' => $record->due_on?->toDateString(),
            'status' => $record->status,
        ];
    }

    private function parseMonth(?string $month): Carbon
    {
        if (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            return Carbon::parse($month.'-01')->startOfMonth();
        }

        return now()->startOfMonth();
    }
}
