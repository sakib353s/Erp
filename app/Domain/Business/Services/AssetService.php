<?php

namespace App\Domain\Business\Services;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Business\AssetEvent;
use App\Domain\Business\AssetRegistry;
use App\Domain\Business\BusinessAsset;
use App\Domain\Business\BusinessRecord;
use App\Domain\Business\VehicleTrip;
use App\Domain\Foundation\Concerns\BranchScope;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * §12-14 — the asset register's engine.
 *
 * Four things happen to an asset, and each one has a rule the register will not
 * bend:
 *
 *  · **it is registered** — with a code that is unique per company, a category
 *    that decides which extra fields exist, where it lives and who answers for
 *    it. A vehicle without plates is refused, because a vehicle the register
 *    cannot identify is a vehicle nobody can find.
 *  · **it is capitalised** — the moment somebody says its cost is in the books.
 *    Nothing can be depreciated before that, and the refusal says so: charging a
 *    monthly expense against a cost that was never recorded is inventing a loss.
 *  · **it is depreciated** — one month at a time, straight line, and only ever by
 *    **posting a real journal entry**: Dr depreciation expense, Cr accumulated
 *    depreciation. `accumulated_depreciation` moves for no other reason, and the
 *    journal's `source_event` carries the period, so running the command twice
 *    cannot charge the same month twice.
 *  · **it is disposed of** — a decision with a date, a reason and whatever came
 *    back. The row stays on the register; the working lists let it go.
 *
 * The trip log is the other half: a vehicle's distance and its running cost, per
 * trip, with the distance derived from the odometer rather than typed, so
 * “which vehicle is costing us the most per kilometre?” is arithmetic instead of
 * an opinion.
 */
class AssetService
{
    public const CODE_PREFIX = 'AST-';

    /** How many months a single run is allowed to catch up on for one asset. */
    public const CATCH_UP_MONTHS = 120;

    public function __construct(
        protected AssetRegistry $registry,
        protected JournalPostingService $journals,
        protected AuditRecorder $audit,
        protected TenantContext $context,
    ) {}

    /* -------------------------------------------------------------- registering */

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): BusinessAsset
    {
        $category = (string) $data['category'];

        // Throws for a category nobody knows, before a row is written.
        $wearsOut = $this->registry->config($category);

        $asset = new BusinessAsset($this->payload($data, [
            'company_id' => $actor->company_id,
            'code' => $this->nextCode((int) $actor->company_id),
            'created_by' => $actor->id,
            'status' => BusinessAsset::STATUS_IN_USE,
            // The category decides what a sensible default looks like; the
            // company decides whether to keep it.
            'gl_account_code' => $data['gl_account_code'] ?? $wearsOut['gl'],
        ]));

        if ($asset->isVehicle() && ! $asset->registration_no) {
            throw ValidationException::withMessages([
                'registration_no' => 'A vehicle needs its registration number — it is how the register, the papers and the person standing at the gate all refer to the same truck.',
            ]);
        }

        if ($asset->isVehicle() && ! $asset->category) {
            throw ValidationException::withMessages(['category' => 'Pick a category.']);
        }

        $asset->save();

        $this->history($asset, 'created', $actor, sprintf(
            '%s registered%s%s.',
            $this->registry->label($category),
            $asset->acquisition_cost !== null ? ' at ৳'.number_format((float) $asset->acquisition_cost, 2) : '',
            $asset->location ? ' at '.$asset->location : '',
        ), ['code' => $asset->code, 'category' => $category]);

        $this->audit->record([
            'action' => 'business.asset_registered',
            'entity_type' => 'business_asset',
            'entity_id' => $asset->id,
            'actor_id' => $actor->id,
            'branch_id' => $asset->branch_id,
            'after' => $this->auditShape($asset),
        ]);

        return $asset;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BusinessAsset $asset, array $data, User $actor): BusinessAsset
    {
        $before = $this->auditShape($asset);
        $touched = [];

        $fields = [
            'name', 'description', 'condition', 'location', 'custodian_id', 'branch_id',
            'supplier_name', 'invoice_ref', 'warranty_expires_on', 'notes',
            'registration_no', 'engine_no', 'chassis_no', 'driver_name', 'driver_id',
            'odometer_reading', 'gl_account_code',
        ];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field] === '' ? null : $data[$field];

            if ((string) $asset->{$field} !== (string) ($value ?? '')) {
                $asset->{$field} = $value;
                $touched[] = $field;
            }
        }

        // The money side is deliberately separate: cost, life and salvage change
        // the whole schedule, so they arrive through their own call.
        foreach (['acquisition_cost', 'salvage_value'] as $field) {
            if (array_key_exists($field, $data) && (string) $asset->{$field} !== (string) ($data[$field] ?? '')) {
                $asset->{$field} = $data[$field] === '' ? null : $data[$field];
                $touched[] = $field;
            }
        }

        if (array_key_exists('status', $data) && $asset->status !== $data['status']) {
            $asset->status = (string) $data['status'];
            $touched[] = 'status';
        }

        if ($touched === []) {
            return $asset;
        }

        $asset->save();

        $action = in_array('location', $touched, true) ? 'moved'
            : (in_array('custodian_id', $touched, true) ? 'assigned' : 'updated');

        $this->history($asset, $action, $actor, sprintf(
            '%s: %s.',
            ucfirst($action === 'updated' ? 'corrected' : $action),
            implode(', ', $touched),
        ), ['fields' => $touched]);

        $this->audit->record([
            'action' => 'business.asset_updated',
            'entity_type' => 'business_asset',
            'entity_id' => $asset->id,
            'actor_id' => $actor->id,
            'branch_id' => $asset->branch_id,
            'before' => $before,
            'after' => $this->auditShape($asset),
        ]);

        return $asset;
    }

    /**
     * Set the depreciation policy: how this cost turns into expense, and over how
     * long. Separate from {@see update()} because it changes the whole schedule
     * and deserves its own line in the history.
     *
     * @param  array{method: string, useful_life_months?: mixed, salvage_value?: mixed, depreciation_starts_on?: ?string}  $data
     */
    public function setDepreciation(BusinessAsset $asset, array $data, User $actor): BusinessAsset
    {
        $method = (string) $data['method'];

        if (! array_key_exists($method, AssetRegistry::METHODS)) {
            throw ValidationException::withMessages([
                'method' => 'The method is either straight line or not depreciated. “'.$method.'” is neither.',
            ]);
        }

        $life = $data['useful_life_months'] ?? null;

        if ($method === BusinessAsset::METHOD_STRAIGHT_LINE) {
            if ((int) $life <= 0) {
                throw ValidationException::withMessages([
                    'useful_life_months' => 'Straight line needs a life in months — without it there is no monthly charge to post.',
                ]);
            }

            if ((float) $asset->acquisition_cost <= 0) {
                throw ValidationException::withMessages([
                    'method' => 'An asset with no cost cannot be depreciated. Record what it cost first.',
                ]);
            }
        }

        $before = [
            'depreciation_method' => $asset->depreciation_method,
            'useful_life_months' => $asset->useful_life_months,
            'salvage_value' => $asset->salvage_value,
        ];

        $asset->forceFill([
            'depreciation_method' => $method,
            'useful_life_months' => $method === BusinessAsset::METHOD_STRAIGHT_LINE ? (int) $life : null,
            'salvage_value' => $data['salvage_value'] ?? $asset->salvage_value ?? 0,
            'depreciation_starts_on' => $data['depreciation_starts_on'] ?? $asset->depreciation_starts_on,
        ])->save();

        $this->history($asset, 'updated', $actor, $method === BusinessAsset::METHOD_STRAIGHT_LINE
            ? 'Depreciated straight line over '.$life.' month(s), salvage ৳'.number_format((float) $asset->salvage_value, 2).'.'
            : 'No longer depreciated.');

        $this->audit->record([
            'action' => 'business.asset_depreciation_policy_set',
            'entity_type' => 'business_asset',
            'entity_id' => $asset->id,
            'actor_id' => $actor->id,
            'branch_id' => $asset->branch_id,
            'before' => $before,
            'after' => [
                'depreciation_method' => $asset->depreciation_method,
                'useful_life_months' => $asset->useful_life_months,
                'salvage_value' => $asset->salvage_value,
            ],
        ]);

        return $asset;
    }

    /**
     * Its cost is in the books. This is the gate depreciation waits behind.
     */
    public function capitalise(BusinessAsset $asset, User $actor, ?string $capitalisedOn = null): BusinessAsset
    {
        if ($asset->isCapitalised()) {
            throw ValidationException::withMessages([
                'capitalised_at' => $asset->describe().' was capitalised on '.$asset->capitalised_at->format('d M Y').'. Un-capitalising it would leave the depreciation already posted with nothing behind it.',
            ]);
        }

        if ((float) $asset->acquisition_cost <= 0) {
            throw ValidationException::withMessages([
                'acquisition_cost' => 'Record what the asset cost before capitalising it — there is nothing to put in the books yet.',
            ]);
        }

        $on = Carbon::parse($capitalisedOn ?? now()->toDateString());

        $asset->forceFill([
            'capitalised_at' => $on->toDateString(),
            'depreciation_starts_on' => $asset->depreciation_starts_on?->toDateString() ?? $on->copy()->startOfMonth()->toDateString(),
        ])->save();

        $this->history($asset, 'capitalised', $actor, sprintf(
            'Capitalised on %s for ৳%s. Depreciation can start from here.',
            $on->format('d M Y'),
            number_format((float) $asset->acquisition_cost, 2),
        ));

        $this->audit->record([
            'action' => 'business.asset_capitalised',
            'entity_type' => 'business_asset',
            'entity_id' => $asset->id,
            'actor_id' => $actor->id,
            'branch_id' => $asset->branch_id,
            'after' => ['capitalised_at' => $on->toDateString(), 'acquisition_cost' => $asset->acquisition_cost],
        ]);

        return $asset;
    }

    /**
     * Out of service: sold, scrapped, given away, stolen. The row stays.
     *
     * @param  array{disposed_on?: ?string, disposal_proceeds?: mixed, disposal_reason: string}  $data
     */
    public function dispose(BusinessAsset $asset, array $data, User $actor): BusinessAsset
    {
        if ($asset->isDisposed()) {
            throw ValidationException::withMessages([
                'disposal_reason' => $asset->describe().' was already disposed of on '.$asset->disposed_on?->format('d M Y').'.',
            ]);
        }

        $proceeds = $data['disposal_proceeds'] ?? null;
        $on = Carbon::parse($data['disposed_on'] ?? now()->toDateString());

        $asset->forceFill([
            'status' => BusinessAsset::STATUS_DISPOSED,
            'disposed_on' => $on->toDateString(),
            'disposal_proceeds' => $proceeds === '' ? null : $proceeds,
            'disposal_reason' => $data['disposal_reason'],
            'disposed_by' => $actor->id,
        ])->save();

        $bookValue = $asset->bookValue();

        $this->history($asset, 'disposed', $actor, sprintf(
            'Disposed on %s: %s%s',
            $on->format('d M Y'),
            $data['disposal_reason'],
            $proceeds !== null && $proceeds !== ''
                ? '. Proceeds ৳'.number_format((float) $proceeds, 2).', book value then ৳'.number_format((float) $bookValue, 2).'.'
                : '. Book value then ৳'.number_format((float) $bookValue, 2).'.',
        ), ['proceeds' => $proceeds, 'book_value' => $bookValue]);

        $this->audit->record([
            'action' => 'business.asset_disposed',
            'entity_type' => 'business_asset',
            'entity_id' => $asset->id,
            'actor_id' => $actor->id,
            'branch_id' => $asset->branch_id,
            'before' => ['status' => BusinessAsset::STATUS_IN_USE, 'book_value' => $bookValue],
            'after' => ['status' => BusinessAsset::STATUS_DISPOSED, 'disposal_proceeds' => $proceeds],
            'reason' => $data['disposal_reason'],
        ]);

        return $asset;
    }

    /* ------------------------------------------------------------ depreciation */

    /**
     * The assets with a month waiting to be charged, oldest first.
     *
     * The test is arithmetic rather than a flag: a charge for a month can only be
     * posted once that month is over, so what this returns is “whose cost has a
     * completed month with no journal entry behind it?”. Nothing to remember,
     * nothing to reset, and running the command twice finds the same empty list
     * the second time.
     *
     * @return Collection<int, BusinessAsset>
     */
    public function dueForDepreciation(?int $companyId = null, ?Carbon $asAt = null): Collection
    {
        $asAt ??= now();

        return $this->visible($companyId)
            ->depreciable()
            ->live()
            ->where(fn (Builder $q) => $q->whereNull('depreciation_starts_on')
                ->orWhere('depreciation_starts_on', '<=', $asAt->toDateString()))
            ->orderBy('last_depreciated_on')
            ->orderBy('id')
            ->get()
            ->filter(fn (BusinessAsset $asset) => $asset->remainingDepreciable() > 0.0001
                && $this->dueDateFor($asset, $asAt) !== null)
            ->values();
    }

    /**
     * Post every month that has fallen due, one journal entry per asset per month.
     *
     * Catch-up is deliberate: a company that last ran this in March and runs it in
     * October gets March through September in one go, each month its own entry
     * with its own date, its own `source_event` (`asset_depreciation_{period}`)
     * and its own line in the asset's history. The alternative — one entry for
     * seven months — would be a single number nobody can unpick afterwards.
     *
     * Skips are reported rather than swallowed: an asset whose ledger account is
     * missing, or whose cost was never capitalised, is a fact the desk needs to
     * see. The run itself never half-charges a month: each month is inside its own
     * transaction with its journal entry.
     *
     * @return array{posted: array<int, array{asset: BusinessAsset, amount: float, period: string, entry: ?JournalEntry}>, skipped: array<int, string>, total: float}
     */
    public function runDepreciation(?User $actor = null, ?int $companyId = null, ?Carbon $asAt = null): array
    {
        $asAt ??= now();

        $companyId ??= $actor?->company_id ?? $this->context->companyId();

        if ($companyId === null) {
            throw new RuntimeException('Depreciation needs a company: pass --company or run it in a company context.');
        }

        $expense = $this->accountByCode(AssetRegistry::DEPRECIATION_EXPENSE_CODE, (int) $companyId);
        $accumulated = $this->accountByCode(AssetRegistry::ACCUMULATED_DEPRECIATION_CODE, (int) $companyId);

        $posted = [];
        $skipped = [];
        $total = 0.0;

        foreach ($this->dueForDepreciation((int) $companyId, $asAt) as $asset) {
            $months = 0;

            // One period per turn of the loop; `dueDateFor` returns null the moment
            // the next month is not over yet.
            while ($months < self::CATCH_UP_MONTHS) {
                $on = $this->dueDateFor($asset, $asAt);

                if ($on === null) {
                    break;
                }

                $amount = round(min($asset->monthlyDepreciation(), $asset->remainingDepreciable()), 2);

                if ($amount <= 0) {
                    $skipped[] = $asset->code.': nothing left to write off.';

                    break;
                }

                $period = $on->format('Y-m');
                $sourceEvent = 'asset_depreciation_'.$period;

                // `withoutGlobalScope` on purpose: the idempotency check asks
                // "does this asset already have this period?" and must see every
                // entry, not only the ones on the branch this person happens to be
                // standing in. Narrowing it here would post a second charge for a
                // month that is already in the ledger.
                $existing = JournalEntry::withoutGlobalScope(BranchScope::class)
                    ->where('company_id', $asset->company_id)
                    ->where('source_type', 'business_asset')
                    ->where('source_id', $asset->id)
                    ->where('source_event', $sourceEvent)
                    ->first();

                if ($existing !== null) {
                    // The ledger already carries this month — a crash between the
                    // posting and the register's own update, most likely. Move the
                    // register's marker forward rather than charge the month twice.
                    $asset->forceFill([
                        'accumulated_depreciation' => round((float) $asset->accumulated_depreciation + $amount, 2),
                        'last_depreciated_on' => $on->toDateString(),
                    ])->save();

                    $skipped[] = $asset->code.': '.$period.' was already posted as '.$existing->entry_no.' — the register has been brought forward to match.';

                    $months++;

                    continue;
                }

                $entry = DB::transaction(function () use ($asset, $expense, $accumulated, $amount, $period, $on, $sourceEvent, $actor) {
                    $entry = $this->journals->post([
                        'entry_date' => $on->toDateString(),
                        'description' => sprintf('Depreciation %s (%s) for %s', $asset->code, $asset->name, $period),
                        'journal_type' => 'depreciation',
                        'source_type' => 'business_asset',
                        'source_id' => $asset->id,
                        'source_event' => $sourceEvent,
                        'branch_id' => $asset->branch_id,
                        'lines' => [
                            ['account_id' => $expense->id, 'dc' => JournalLine::DEBIT, 'amount' => (string) $amount],
                            ['account_id' => $accumulated->id, 'dc' => JournalLine::CREDIT, 'amount' => (string) $amount],
                        ],
                    ], $actor);

                    $asset->forceFill([
                        'accumulated_depreciation' => round((float) $asset->accumulated_depreciation + $amount, 2),
                        'last_depreciated_on' => $on->toDateString(),
                    ])->save();

                    $this->history($asset, 'depreciated', $actor, sprintf(
                        'Depreciated ৳%s for %s (journal %s). Book value now ৳%s.',
                        number_format($amount, 2),
                        $period,
                        $entry->entry_no,
                        number_format((float) $asset->bookValue(), 2),
                    ), ['period' => $period, 'amount' => $amount, 'journal' => $entry->entry_no]);

                    return $entry;
                });

                $this->audit->record([
                    'action' => 'business.asset_depreciated',
                    'entity_type' => 'business_asset',
                    'entity_id' => $asset->id,
                    'actor_id' => $actor?->id,
                    'branch_id' => $asset->branch_id,
                    'after' => [
                        'period' => $period,
                        'amount' => $amount,
                        'journal' => $entry->entry_no,
                        'accumulated_depreciation' => $asset->accumulated_depreciation,
                    ],
                ]);

                $posted[] = ['asset' => $asset, 'amount' => $amount, 'period' => $period, 'entry' => $entry];
                $total += $amount;
                $months++;
            }

            if ($months >= self::CATCH_UP_MONTHS) {
                $skipped[] = $asset->code.': more than '.self::CATCH_UP_MONTHS.' months were waiting — run the desk again to finish catching up.';
            }
        }

        return ['posted' => $posted, 'skipped' => $skipped, 'total' => round($total, 2)];
    }


    /* -------------------------------------------------------------- the trips */

    /**
     * Log a run. The distance is arithmetic when both odometer readings are
     * there; when they are not, the distance stays empty rather than guessed.
     *
     * @param  array<string, mixed>  $data
     */
    public function logTrip(BusinessAsset $asset, array $data, User $actor): VehicleTrip
    {
        if (! $asset->isVehicle()) {
            throw ValidationException::withMessages([
                'business_asset_id' => $asset->describe().' is not a vehicle, so it cannot have trips. Log the work as a task or the cost as an expense.',
            ]);
        }

        if ($asset->isDisposed()) {
            throw ValidationException::withMessages([
                'business_asset_id' => $asset->describe().' was disposed of on '.$asset->disposed_on?->format('d M Y').'. A trip after that is either a wrong date or a wrong vehicle.',
            ]);
        }

        $start = $data['odometer_start'] ?? null;
        $end = $data['odometer_end'] ?? null;

        if ($start !== null && $end !== null && (int) $end < (int) $start) {
            throw ValidationException::withMessages([
                'odometer_end' => 'The closing reading cannot be below the opening one ('.$start.' → '.$end.').',
            ]);
        }

        $distance = ($start !== null && $end !== null && (int) $end >= (int) $start)
            ? round((int) $end - (int) $start, 2)
            : null;

        $trip = VehicleTrip::create([
            'company_id' => $asset->company_id,
            'business_asset_id' => $asset->id,
            'branch_id' => $data['branch_id'] ?? $asset->branch_id,
            'trip_date' => Carbon::parse($data['trip_date'])->toDateString(),
            'started_at' => $data['started_at'] ?? null,
            'ended_at' => $data['ended_at'] ?? null,
            'from_location' => $data['from_location'] ?? null,
            'to_location' => $data['to_location'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'driver_id' => $data['driver_id'] ?? $asset->driver_id,
            'driver_name' => $data['driver_name'] ?? ($data['driver_id'] ?? null ? null : $asset->driver_name),
            'odometer_start' => $start,
            'odometer_end' => $end,
            'distance_km' => $distance,
            'fuel_litres' => $data['fuel_litres'] ?? null,
            'fuel_cost' => $data['fuel_cost'] ?? null,
            'other_cost' => $data['other_cost'] ?? null,
            'cost_note' => $data['cost_note'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor->id,
        ]);

        // The odometer on the asset follows the trips: it is the reading somebody
        // would have taken at the gate, not a separate number to maintain.
        if ($end !== null && (int) $end > (int) ($asset->odometer_reading ?? 0)) {
            $asset->forceFill(['odometer_reading' => (int) $end])->save();
        }

        $this->history($asset, 'trip', $actor, sprintf(
            '%s → %s on %s%s.',
            $trip->from_location ?: '—',
            $trip->to_location ?: '—',
            $trip->trip_date->format('d M Y'),
            $distance !== null ? ', '.number_format($distance, 0).' km' : '',
        ), ['trip_id' => $trip->id, 'distance_km' => $distance]);

        $this->audit->record([
            'action' => 'business.vehicle_trip_logged',
            'entity_type' => 'business_asset',
            'entity_id' => $asset->id,
            'actor_id' => $actor->id,
            'branch_id' => $asset->branch_id,
            'after' => [
                'trip_id' => $trip->id,
                'trip_date' => $trip->trip_date->toDateString(),
                'distance_km' => $distance,
                'total_cost' => $trip->totalCost(),
            ],
        ]);

        return $trip;
    }

    /**
     * An inclusive day range on a date column.
     *
     * `where('trip_date', '<=', '2027-02-20')` quietly loses the last day:
     * Eloquent writes a `date` cast as `2027-02-20 00:00:00`, and that string
     * sorts *after* `2027-02-20` as text. Comparing the day part is what
     * somebody means by "from the 1st to the 20th", and it reads the same on
     * SQLite and MySQL.
     */
    protected function withinDays(Builder $query, string $column, ?string $from, ?string $to): Builder
    {
        if ($from !== null && $from !== '') {
            $query->whereDate($column, '>=', Carbon::parse($from)->toDateString());
        }

        if ($to !== null && $to !== '') {
            $query->whereDate($column, '<=', Carbon::parse($to)->toDateString());
        }

        return $query;
    }

    /**
     * @param  array{asset_id?: mixed, from?: ?string, to?: ?string, q?: ?string, per_page?: int}  $filters
     */
    public function trips(array $filters = []): LengthAwarePaginator
    {
        $query = VehicleTrip::query()->with(['asset', 'driver', 'expense']);

        $ids = $this->context->accessibleBranchIds();

        if ($ids !== null) {
            $query->where(fn (Builder $q) => $q->whereIn('branch_id', $ids)->orWhereNull('branch_id'));
        }

        if (! empty($filters['asset_id']) && is_numeric($filters['asset_id'])) {
            $query->where('business_asset_id', (int) $filters['asset_id']);
        }

        $this->withinDays(
            $query,
            'trip_date',
            isset($filters['from']) ? (string) $filters['from'] : null,
            isset($filters['to']) ? (string) $filters['to'] : null,
        );

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('from_location', 'like', "%{$search}%")
                    ->orWhere('to_location', 'like', "%{$search}%")
                    ->orWhere('purpose', 'like', "%{$search}%")
                    ->orWhere('driver_name', 'like', "%{$search}%");
            });
        }

        return $query->orderByDesc('trip_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();
    }

    /**
     * What each vehicle has cost to run: distance, fuel and total, with the cost
     * per kilometre that only exists when the distance does.
     *
     * @return Collection<int, array{asset: BusinessAsset, trips: int, distance: float, fuel_cost: float, other_cost: float, total_cost: float, cost_per_km: ?float}>
     */
    public function vehicleCosts(?int $companyId = null, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $from ??= now()->startOfMonth();
        $to ??= now()->endOfMonth();

        return $this->visible($companyId)
            ->vehicles()
            ->live()
            ->orderBy('name')
            ->get()
            ->map(function (BusinessAsset $vehicle) use ($from, $to) {
                $trips = $this->withinDays(
                    $vehicle->trips()->getQuery(),
                    'trip_date',
                    $from->toDateString(),
                    $to->toDateString(),
                )->get();

                $distance = round((float) $trips->sum('distance_km'), 2);
                $fuel = round((float) $trips->sum('fuel_cost'), 2);
                $other = round((float) $trips->sum('other_cost'), 2);

                return [
                    'asset' => $vehicle,
                    'trips' => $trips->count(),
                    'distance' => $distance,
                    'fuel_cost' => $fuel,
                    'other_cost' => $other,
                    'total_cost' => round($fuel + $other, 2),
                    'cost_per_km' => $distance > 0 ? round(($fuel + $other) / $distance, 2) : null,
                ];
            });
    }

    /**
     * Hang a paper on an asset: a vehicle's fitness certificate, a machine's
     * insurance policy, the warranty invoice. The record stays in the certificate
     * register — where the renewals lens and the compliance calendar already
     * watch it — and the asset page can now show its own papers.
     */
    public function linkRecord(BusinessAsset $asset, BusinessRecord $record, User $actor): BusinessRecord
    {
        if ((int) $record->company_id !== (int) $asset->company_id) {
            throw ValidationException::withMessages([
                'record_id' => 'That paper belongs to another company.',
            ]);
        }

        if ($record->business_asset_id !== null && (int) $record->business_asset_id !== (int) $asset->id) {
            $holder = BusinessAsset::query()->find($record->business_asset_id);

            throw ValidationException::withMessages([
                'record_id' => 'That paper is already on '.($holder?->describe() ?? 'another asset').'. Unlink it there first.',
            ]);
        }

        if ((int) $record->business_asset_id === (int) $asset->id) {
            return $record;
        }

        $record->forceFill(['business_asset_id' => $asset->id])->save();

        $this->history($asset, 'record_linked', $actor, sprintf(
            'Paper linked: %s%s.',
            $record->title,
            $record->trackedOn() !== null ? ' ('.($record->kindLabel()).', tracked to '.$record->trackedOn()->format('d M Y').')' : '',
        ), ['record_id' => $record->id, 'kind' => $record->kind]);

        $this->audit->record([
            'action' => 'business.asset_record_linked',
            'entity_type' => 'business_asset',
            'entity_id' => $asset->id,
            'actor_id' => $actor->id,
            'branch_id' => $asset->branch_id,
            'after' => ['record_id' => $record->id, 'record' => $record->title, 'kind' => $record->kind],
        ]);

        return $record;
    }

    public function unlinkRecord(BusinessAsset $asset, BusinessRecord $record, User $actor): BusinessRecord
    {
        if ((int) $record->company_id !== (int) $asset->company_id) {
            throw ValidationException::withMessages([
                'record_id' => 'That paper belongs to another company.',
            ]);
        }

        if ((int) $record->business_asset_id !== (int) $asset->id) {
            throw ValidationException::withMessages([
                'record_id' => 'That paper is not on '.$asset->describe().'.',
            ]);
        }

        $record->forceFill(['business_asset_id' => null])->save();

        $this->history($asset, 'record_linked', $actor, 'Paper unlinked: '.$record->title.'.');

        $this->audit->record([
            'action' => 'business.asset_record_unlinked',
            'entity_type' => 'business_asset',
            'entity_id' => $asset->id,
            'actor_id' => $actor->id,
            'branch_id' => $asset->branch_id,
            'before' => ['record_id' => $record->id, 'record' => $record->title],
        ]);

        return $record;
    }

    /**
     * How many things are on each shelf, for the row of links at the top of the
     * register. Counts, not lists — the shelf pages themselves page.
     *
     * @return array{register: int, vehicles: int, equipment: int}
     */
    public function shelfCounts(): array
    {
        $counts = [];

        foreach (array_keys(AssetRegistry::SHELVES) as $shelf) {
            $counts[$shelf] = $this->visible()
                ->inCategories($this->registry->categoriesFor($shelf))
                ->live()
                ->count();
        }

        return $counts;
    }

    /* --------------------------------------------------------------- reading */

    /**
     * @param  array{shelf?: ?string, category?: ?string, status?: ?string, q?: ?string, custodian_id?: mixed, branch_id?: mixed, disposed?: mixed, per_page?: int}  $filters
     */
    public function register(array $filters = []): LengthAwarePaginator
    {
        // The branch filter is the *second* argument of visible(): passing it
        // first asked for the company whose id happened to equal the branch id,
        // which is why the branch dropdown on every shelf quietly did nothing.
        $query = $this->visible(branchId: $filters['branch_id'] ?? null)
            ->with(['branch', 'custodian', 'driver']);

        $categories = $this->registry->categoriesFor($filters['shelf'] ?? null);
        $query->inCategories($categories);

        if (! empty($filters['category']) && $this->registry->has((string) $filters['category'])) {
            $query->category((string) $filters['category']);
        }

        if (! empty($filters['custodian_id']) && is_numeric($filters['custodian_id'])) {
            $query->where('custodian_id', (int) $filters['custodian_id']);
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status === 'disposed') {
            $query->disposed();
        } elseif ($status !== '' && array_key_exists($status, AssetRegistry::STATUSES)) {
            $query->where('status', $status);
        } elseif (! filter_var($filters['disposed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->live();
        }

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('registration_no', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('category')->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();
    }

    /**
     * The strip at the top of the register.
     *
     * @return array<string, mixed>
     */
    public function summary(?string $shelf = null): array
    {
        $categories = $this->registry->categoriesFor($shelf);

        $assets = $this->visible()->inCategories($categories)->with('records')->get();
        $live = $assets->where('status', '!=', BusinessAsset::STATUS_DISPOSED);

        $cost = round((float) $live->sum(fn (BusinessAsset $asset) => (float) $asset->acquisition_cost), 2);
        $accumulated = round((float) $live->sum(fn (BusinessAsset $asset) => (float) $asset->accumulated_depreciation), 2);

        $warrantySoon = $live->filter(fn (BusinessAsset $asset) => $asset->warranty_expires_on !== null
            && $asset->warranty_expires_on->gte(now()->startOfDay())
            && $asset->warranty_expires_on->lte(now()->addDays(30)->endOfDay()))->count();

        return [
            'live' => $live->count(),
            'disposed' => $assets->count() - $live->count(),
            'vehicles' => $live->where('category', AssetRegistry::CATEGORY_VEHICLE)->count(),
            'depreciable' => $live->filter(fn (BusinessAsset $asset) => $asset->isDepreciable())->count(),
            'uncapitalised' => $live->filter(fn (BusinessAsset $asset) => ! $asset->isCapitalised() && (float) $asset->acquisition_cost > 0)->count(),
            'under_repair' => $live->where('status', BusinessAsset::STATUS_REPAIR)->count(),
            'cost' => $cost,
            'accumulated' => $accumulated,
            'book_value' => round($cost - $accumulated, 2),
            'warranty_ending' => $warrantySoon,
            'papers' => $live->sum(fn (BusinessAsset $asset) => $asset->records->count()),
        ];
    }

    /**
     * Records this actor may see: their own company, their branches, plus
     * company-wide rows.
     *
     * The company filter is not decoration. `BusinessAsset` carries no global
     * branch scope (a vehicle belongs to a branch *and* to the company), so
     * without it every all-branch user reads rows belonging to a second company
     * on the register — and an asset is referenced by its own number, so a code
     * collision across companies would be a wrong-number, not just a stray row.
     */
    public function visible(?int $companyId = null, mixed $branchId = null): Builder
    {
        $query = BusinessAsset::query();

        $companyId ??= $this->context->companyId();

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $ids = $this->context->accessibleBranchIds();

        if ($ids !== null) {
            $query->where(fn (Builder $q) => $q->whereIn('branch_id', $ids)->orWhereNull('branch_id'));
        }

        if ($branchId === 'company') {
            $query->whereNull('branch_id');
        } elseif (is_numeric($branchId)) {
            $query->where('branch_id', (int) $branchId);
        }

        return $query;
    }

    /* --------------------------------------------------------------- helpers */

    /** AST-000001, allocated per company and never reused. */
    public function nextCode(int $companyId): string
    {
        $last = BusinessAsset::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('code');

        $number = 1;

        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches) === 1) {
            $number = (int) $matches[1] + 1;
        }

        do {
            $code = self::CODE_PREFIX.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $number++;
        } while (BusinessAsset::query()->where('company_id', $companyId)->where('code', $code)->exists());

        return $code;
    }

    /**
     * The date the next uncharged month ends — the period the run would cover.
     *
     * The first period is the month depreciation starts in; after that it is the
     * month after the last one posted. Never in the future relative to the run:
     * a charge is dated the last day of the month it covers.
     */
    public function nextPeriodEndFor(BusinessAsset $asset): Carbon
    {
        $start = ($asset->depreciation_starts_on ?? $asset->capitalised_at ?? $asset->created_at)
            ->copy()->startOfMonth();

        $period = $asset->last_depreciated_on !== null
            ? $asset->last_depreciated_on->copy()->startOfMonth()->addMonthNoOverflow()
            : $start->copy();

        return $period->endOfMonth();
    }

    /**
     * The date a charge would carry — or null when the month it covers is not over
     * yet, because the register never posts an expense dated in the future.
     *
     * The last day of the month is the conventional date for depreciation, and it
     * means a scheduled run on the 1st posts the month that just ended while a run
     * on the 31st posts the month that ends today. Both are the same charge; only
     * the run date differs, and it does not change the period.
     */
    public function dueDateFor(BusinessAsset $asset, ?Carbon $asAt = null): ?Carbon
    {
        $asAt ??= now();

        $periodEnd = $this->nextPeriodEndFor($asset);

        return $periodEnd->lessThanOrEqualTo($asAt->copy()->endOfDay()) ? $periodEnd : null;
    }

    /** The period label (`2026-10`) a run would post next, for the desk's display. */
    public function nextPeriodLabelFor(BusinessAsset $asset): string
    {
        return $this->nextPeriodEndFor($asset)->format('Y-m');
    }

    private function accountByCode(string $code, int $companyId): Account
    {
        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            throw new RuntimeException(sprintf(
                'The chart of accounts has no active account %s, so depreciation cannot be posted. Seed the accounting foundation (or add the account) and run the command again.',
                $code,
            ));
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function payload(array $data, array $base): array
    {
        $category = (string) ($data['category'] ?? $base['category'] ?? 'other');
        $isVehicle = $this->registry->isVehicle($category);

        return array_merge($base, [
            'category' => $category,
            'name' => trim((string) $data['name']),
            'description' => $this->clean($data['description'] ?? null),
            'location' => $this->clean($data['location'] ?? null),
            'branch_id' => $data['branch_id'] ?? null,
            'custodian_id' => $data['custodian_id'] ?? null,
            'acquired_on' => $this->clean($data['acquired_on'] ?? null),
            'acquisition_cost' => $this->clean($data['acquisition_cost'] ?? null),
            'supplier_name' => $this->clean($data['supplier_name'] ?? null),
            'invoice_ref' => $this->clean($data['invoice_ref'] ?? null),
            'warranty_expires_on' => $this->clean($data['warranty_expires_on'] ?? null),
            'condition' => $this->clean($data['condition'] ?? null),
            'notes' => $this->clean($data['notes'] ?? null),
            // Vehicle fields are only ever stored for a vehicle: a laptop with a
            // registration number is a data-entry mistake, not a feature.
            'registration_no' => $isVehicle ? $this->clean($data['registration_no'] ?? null) : null,
            'engine_no' => $isVehicle ? $this->clean($data['engine_no'] ?? null) : null,
            'chassis_no' => $isVehicle ? $this->clean($data['chassis_no'] ?? null) : null,
            'driver_name' => $isVehicle ? $this->clean($data['driver_name'] ?? null) : null,
            'driver_id' => $isVehicle ? ($data['driver_id'] ?? null) : null,
            'odometer_reading' => $isVehicle ? ($this->clean($data['odometer_reading'] ?? null) ?? null) : null,
            'depreciation_method' => $this->clean($data['depreciation_method'] ?? null) ?? BusinessAsset::METHOD_NONE,
            'useful_life_months' => $this->clean($data['useful_life_months'] ?? null) ?? $this->registry->defaultLife($category),
            'salvage_value' => $this->clean($data['salvage_value'] ?? null) ?? 0,
        ]);
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = is_string($value) ? trim($value) : $value;

        return $value === '' ? null : (string) $value;
    }

    private function history(BusinessAsset $asset, string $action, ?User $actor, string $note, array $meta = []): AssetEvent
    {
        return AssetEvent::create([
            'company_id' => $asset->company_id,
            'business_asset_id' => $asset->id,
            'action' => $action,
            'happened_on' => now()->toDateString(),
            'note' => $note,
            'meta' => $meta ?: null,
            'actor_id' => $actor?->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function auditShape(BusinessAsset $asset): array
    {
        return [
            'code' => $asset->code,
            'category' => $asset->category,
            'name' => $asset->name,
            'location' => $asset->location,
            'custodian_id' => $asset->custodian_id,
            'status' => $asset->status,
            'acquisition_cost' => $asset->acquisition_cost,
            'accumulated_depreciation' => $asset->accumulated_depreciation,
            'book_value' => $asset->bookValue(),
            'registration_no' => $asset->registration_no,
        ];
    }
}
