<?php

namespace App\Domain\Accounting;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived account balance cache (§7.4). Rebuildable from journal_lines
 * via LedgerService::rebuildRunningBalances() — never the source of truth.
 */
class RunningBalance extends Model
{
    use Auditable;

    protected $table = 'running_balances';

    protected $fillable = [
        'company_id', 'account_id', 'branch_id',
        'debit_total', 'credit_total', 'balance', 'currency', 'computed_at',
    ];

    protected $casts = [
        'debit_total' => 'decimal:4',
        'credit_total' => 'decimal:4',
        'balance' => 'decimal:4',
        'computed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
