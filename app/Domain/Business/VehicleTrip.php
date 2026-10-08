<?php

namespace App\Domain\Business;

use App\Domain\CashBank\Expense;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12-14 — one run out and back.
 *
 * The trip log exists for two reasons and both are arithmetic: **what a vehicle
 * costs to run** (fuel and other costs per kilometre, per vehicle, per month) and
 * **where it was** (which matters the day something arrives damaged, or the day a
 * driver's hours are questioned).
 *
 * The distance is arithmetic too when both odometer readings are on the record:
 * `distance_km` is stored rather than typed, because a typed distance and a
 * derived one disagree eventually and nobody can tell which is right. A trip
 * whose odometer readings are missing keeps a null distance instead of a guess.
 *
 * Costs are recorded here and *posted* on the expense desk: `expense_id` points at
 * the real expense row when somebody records it, so the trip and the books point
 * at the same document rather than at two numbers that ought to match.
 */
class VehicleTrip extends Model
{
    protected $fillable = [
        'company_id', 'business_asset_id', 'branch_id', 'trip_date', 'started_at', 'ended_at',
        'from_location', 'to_location', 'purpose', 'driver_id', 'driver_name',
        'odometer_start', 'odometer_end', 'distance_km', 'fuel_litres', 'fuel_cost',
        'other_cost', 'cost_note', 'expense_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'trip_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'odometer_start' => 'integer',
        'odometer_end' => 'integer',
        'distance_km' => 'decimal:2',
        'fuel_litres' => 'decimal:3',
        'fuel_cost' => 'decimal:2',
        'other_cost' => 'decimal:2',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(BusinessAsset::class, 'business_asset_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** Who was driving: the account, or the name written down. */
    public function driverLabel(): string
    {
        return $this->driver?->name ?? ($this->driver_name ?: 'Driver not recorded');
    }

    /** Where it went, in one line. */
    public function routeLabel(): string
    {
        $from = $this->from_location ?: '—';
        $to = $this->to_location ?: '—';

        return $from.' → '.$to;
    }

    public function totalCost(): float
    {
        return round((float) $this->fuel_cost + (float) $this->other_cost, 2);
    }

    /** Cost per kilometre — null when the distance is not known, never zero. */
    public function costPerKm(): ?float
    {
        $distance = (float) $this->distance_km;

        if ($distance <= 0) {
            return null;
        }

        return round($this->totalCost() / $distance, 2);
    }

    public function fuelEfficiency(): ?float
    {
        $litres = (float) $this->fuel_litres;
        $distance = (float) $this->distance_km;

        if ($litres <= 0 || $distance <= 0) {
            return null;
        }

        return round($distance / $litres, 2); // kilometres per litre
    }

    public function isExpensed(): bool
    {
        return $this->expense_id !== null;
    }
}
