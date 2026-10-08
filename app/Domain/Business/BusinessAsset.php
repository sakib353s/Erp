<?php

namespace App\Domain\Business;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §12-14 — one asset on the register.
 *
 * A register answers three questions about a thing the company owns: *where is
 * it*, *what is it worth*, and *who answers for it*. Everything on this model
 * serves one of those. Where it is: `location`, `custodian`, `status`. What it is
 * worth: the acquisition fields and the depreciation schedule — and the schedule
 * is **computed** from the cost, the life and the journal entries that have
 * actually been posted, never guessed ahead of the books. Who answers for it: the
 * custodian, and the history of where it has been.
 *
 * Two readings worth stating, because they are what make the register usable:
 *
 *  · **book value is derived** — cost minus accumulated depreciation, floored at
 *    salvage. It moves when a real journal entry moved it, and at no other time;
 *  · **a vehicle's papers are not columns here** — fitness, insurance and tax
 *    token are `business_records` rows linked to this asset, so they land on the
 *    same renewals lens and the same compliance calendar as the company's trade
 *    licence. One register watches every date the company has.
 */
class BusinessAsset extends Model
{
    use Auditable;

    public const STATUS_IN_USE = 'in_use';

    public const STATUS_STORED = 'stored';

    public const STATUS_REPAIR = 'under_repair';

    public const STATUS_DISPOSED = 'disposed';

    public const METHOD_NONE = 'none';

    public const METHOD_STRAIGHT_LINE = 'straight_line';

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'category', 'name', 'description', 'location',
        'custodian_id', 'acquired_on', 'acquisition_cost', 'supplier_name', 'invoice_ref',
        'warranty_expires_on', 'condition', 'status', 'registration_no', 'engine_no',
        'chassis_no', 'driver_name', 'driver_id', 'odometer_reading', 'depreciation_method',
        'useful_life_months', 'salvage_value', 'accumulated_depreciation', 'capitalised_at',
        'depreciation_starts_on', 'last_depreciated_on', 'gl_account_code', 'disposed_on',
        'disposal_proceeds', 'disposal_reason', 'disposed_by', 'notes', 'created_by',
    ];

    protected $casts = [
        'acquired_on' => 'date',
        'acquisition_cost' => 'decimal:2',
        'warranty_expires_on' => 'date',
        'odometer_reading' => 'integer',
        'useful_life_months' => 'integer',
        'salvage_value' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'capitalised_at' => 'date',
        'depreciation_starts_on' => 'date',
        'last_depreciated_on' => 'date',
        'disposed_on' => 'date',
        'disposal_proceeds' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** §12-14: who took the decision to write it off. */
    public function disposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disposed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class, 'business_asset_id')->orderByDesc('happened_on')->orderByDesc('id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(VehicleTrip::class, 'business_asset_id')->orderByDesc('trip_date')->orderByDesc('id');
    }

    /** The papers that run out: fitness, insurance, tax token, warranty. */
    public function records(): HasMany
    {
        return $this->hasMany(BusinessRecord::class, 'business_asset_id');
    }

    /* ----------------------------------------------------------------- scopes */

    public function scopeLive($query)
    {
        return $query->where('status', '!=', self::STATUS_DISPOSED);
    }

    public function scopeDisposed($query)
    {
        return $query->where('status', self::STATUS_DISPOSED);
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeInCategories($query, array $categories)
    {
        return $query->whereIn('category', $categories);
    }

    public function scopeVehicles($query)
    {
        return $query->where('category', AssetRegistry::CATEGORY_VEHICLE);
    }

    public function scopeDepreciable($query)
    {
        return $query->where('depreciation_method', self::METHOD_STRAIGHT_LINE)
            ->whereNotNull('capitalised_at')
            ->whereNotNull('useful_life_months');
    }

    /* ------------------------------------------------------------- the money */

    public function isVehicle(): bool
    {
        return $this->category === AssetRegistry::CATEGORY_VEHICLE;
    }

    public function isDisposed(): bool
    {
        return $this->status === self::STATUS_DISPOSED;
    }

    public function isCapitalised(): bool
    {
        return $this->capitalised_at !== null;
    }

    public function isDepreciable(): bool
    {
        return $this->depreciation_method === self::METHOD_STRAIGHT_LINE
            && (int) $this->useful_life_months > 0
            && (float) $this->acquisition_cost > 0;
    }

    /** Cost minus accumulated depreciation, never below salvage. */
    public function bookValue(): ?float
    {
        if ($this->acquisition_cost === null) {
            return null;
        }

        $value = (float) $this->acquisition_cost - (float) $this->accumulated_depreciation;

        return round(max($value, (float) $this->salvage_value), 2);
    }

    /** What is left to write off over the life: cost less salvage. */
    public function depreciableBase(): float
    {
        return max(0.0, (float) $this->acquisition_cost - (float) $this->salvage_value);
    }

    /** One month of straight-line charge, to the paisa. */
    public function monthlyDepreciation(): float
    {
        if (! $this->isDepreciable()) {
            return 0.0;
        }

        return round($this->depreciableBase() / (int) $this->useful_life_months, 4);
    }

    /** How much of the base is still to be written off. */
    public function remainingDepreciable(): float
    {
        return round(max(0.0, $this->depreciableBase() - (float) $this->accumulated_depreciation), 4);
    }

    /** The date the next month's charge falls due, or null when there is none. */
    public function nextDepreciationOn(): ?string
    {
        if (! $this->isDepreciable() || $this->isDisposed() || $this->remainingDepreciable() <= 0.0001) {
            return null;
        }

        $start = ($this->depreciation_starts_on ?? $this->capitalised_at ?? $this->acquired_on)
            ?->copy()->startOfMonth();

        if ($start === null) {
            return null;
        }

        return ($this->last_depreciated_on?->copy()->startOfMonth()->addMonthNoOverflow() ?? $start)
            ->max($start)
            ->endOfMonth()
            ->toDateString();
    }

    /**
     * The whole straight-line schedule, month by month from the start date, with
     * the months that a **real journal entry** stands behind marked posted.
     *
     * It is a projection: if the life or the salvage changes tomorrow, the future
     * rows change and the posted rows do not — which is exactly the property that
     * makes it safe to show next to the accumulated figure.
     *
     * @return array<int, array{period: string, on: string, amount: float, posted: bool, remaining_after: float}>
     */
    public function schedule(int $limit = 240): array
    {
        if (! $this->isDepreciable()) {
            return [];
        }

        // The same start the posting service would use: the month depreciation
        // starts in, then the month the cost went into the books, then the month
        // it was bought. A projection that starts somewhere else would print
        // months the run is never going to post.
        $start = ($this->depreciation_starts_on ?? $this->capitalised_at ?? $this->acquired_on)
            ?->copy()->startOfMonth();

        if ($start === null) {
            return [];
        }

        $monthly = $this->monthlyDepreciation();
        $postedThrough = $this->last_depreciated_on?->copy()->endOfMonth();
        $projected = $this->depreciableBase();

        $rows = [];
        $cursor = $start->copy();

        while (count($rows) < $limit && $projected > 0.0001) {
            $amount = round(min($monthly, $projected), 2);
            $projected = round($projected - $amount, 4);

            $rows[] = [
                'period' => $cursor->format('Y-m'),
                'on' => $cursor->copy()->endOfMonth()->toDateString(),
                'amount' => round($amount, 2),
                'posted' => $postedThrough !== null && $cursor->lessThanOrEqualTo($postedThrough),
                'remaining_after' => round(max($projected, 0.0), 2),
            ];

            $cursor->addMonthNoOverflow();
        }

        return $rows;
    }

    /* ----------------------------------------------------------------- labels */

    public function categoryLabel(): string
    {
        return app(AssetRegistry::class)->label($this->category);
    }

    public function conditionLabel(): string
    {
        return app(AssetRegistry::class)->conditionLabel($this->condition);
    }

    public function statusLabel(): string
    {
        return app(AssetRegistry::class)->statusLabel($this->status);
    }

    public function methodLabel(): string
    {
        return app(AssetRegistry::class)->methodLabel($this->depreciation_method);
    }

    /** What the register calls this row when it talks about it. */
    public function describe(): string
    {
        return $this->code.' — '.$this->name;
    }

    /** A vehicle is known by its plates; anything else by its code. */
    public function identifier(): string
    {
        return $this->registration_no ?: $this->code;
    }

    public function isWarrantyLive(): bool
    {
        return $this->warranty_expires_on !== null && $this->warranty_expires_on->isFuture();
    }
}
