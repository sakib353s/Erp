<?php

namespace App\Domain\Business;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §12-15 — one utility provider: DESCO, WASA, TITAS, the internet line, the
 * landlord.
 *
 * A provider is not a transaction and not a supplier: nobody buys from DESCO and
 * haggles over a price. It is a counterparty with a consumer number, a meter, a
 * premises it supplies, a day of the month its bill lands, and — the column that
 * earns its keep — the ledger account its bills belong in.
 */
class UtilityProvider extends Model
{
    public const FAMILY_ELECTRICITY = 'electricity';

    public const FAMILY_WATER = 'water';

    public const FAMILY_GAS = 'gas';

    public const FAMILY_INTERNET = 'internet';

    public const FAMILY_RENT = 'rent';

    /** The five shelves the menu names, each with the unit its bill is measured in. */
    public const FAMILIES = [
        self::FAMILY_ELECTRICITY => ['label' => 'Electricity', 'unit' => 'kWh', 'icon' => 'bi-lightning-charge'],
        self::FAMILY_WATER => ['label' => 'Water', 'unit' => 'm3', 'icon' => 'bi-droplet'],
        self::FAMILY_GAS => ['label' => 'Gas', 'unit' => 'm3', 'icon' => 'bi-fire'],
        self::FAMILY_INTERNET => ['label' => 'Internet & Mobile', 'unit' => 'MB', 'icon' => 'bi-wifi'],
        self::FAMILY_RENT => ['label' => 'Rent', 'unit' => 'month', 'icon' => 'bi-house-door'],
    ];

    /**
     * Where a family's bills belong unless somebody says otherwise. Rent is its
     * own expense line; power, water, gas and connectivity are one.
     */
    public const FAMILY_ACCOUNT = [
        self::FAMILY_ELECTRICITY => '5230',
        self::FAMILY_WATER => '5230',
        self::FAMILY_GAS => '5230',
        self::FAMILY_INTERNET => '5230',
        self::FAMILY_RENT => '5220',
    ];

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name', 'family', 'expense_account_id',
        'consumer_no', 'meter_no', 'premises', 'due_day', 'is_active', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'due_day' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Accounting\Account::class, 'expense_account_id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(UtilityBill::class, 'provider_id');
    }

    public function familyLabel(): string
    {
        return self::FAMILIES[$this->family]['label'] ?? ucfirst((string) $this->family);
    }

    public function familyIcon(): string
    {
        return self::FAMILIES[$this->family]['icon'] ?? 'bi-plug';
    }

    public function unit(): string
    {
        return self::FAMILIES[$this->family]['unit'] ?? 'unit';
    }

    public function label(): string
    {
        return $this->name.($this->consumer_no ? ' · '.$this->consumer_no : '');
    }
}
