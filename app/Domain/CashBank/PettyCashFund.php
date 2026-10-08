<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\Account;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §08-21 — a float of small money, held by a named person at a place.
 *
 * The fund is deliberately thin, because everything substantial about it lives
 * somewhere else: the money is an `accounts` row (a postable cash leaf), the
 * balance is the ledger's sum over that account, and the vouchers are payments.
 * What this row adds is the accountability that a ledger cannot express — whose
 * cash it is, where it is kept, and how much is supposed to be in the tin.
 */
class PettyCashFund extends Model
{
    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name', 'custodian_id', 'account_id',
        'imprest_amount', 'currency', 'is_active', 'opened_on', 'closed_on',
        'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'imprest_amount' => 'decimal:4',
            'is_active' => 'boolean',
            'opened_on' => 'date',
            'closed_on' => 'date',
        ];
    }

    /** The chart-of-accounts leaf the float physically sits in. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PettyCashTransaction::class, 'fund_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PettyCashRequest::class, 'fund_id');
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /** The level the fund is meant to hold, as a figure to show. */
    public function imprest(): string
    {
        return number_format((float) $this->imprest_amount, 2, '.', '');
    }

    /** "1115 — Petty cash · Head office" — how the ledger names this drawer. */
    public function accountLabel(): string
    {
        return trim(($this->account?->code ?? '').' — '.($this->account?->name ?? $this->name), ' —');
    }
}
