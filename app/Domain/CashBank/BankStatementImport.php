<?php

namespace App\Domain\CashBank;

use App\Domain\Accounting\Account;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to read a bank's statement file (§08-09).
 *
 * Every run leaves a row — a rejected file and a preview are both facts worth
 * keeping, because "we looked at the March statement and it did not load" is a
 * thing an accountant needs to be able to find out later. `status` says what
 * became of it; `errors` carries the per-row reasons when it did not load.
 */
class BankStatementImport extends Model
{
    public const STATUS_PREVIEW = 'preview';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_id', 'branch_id', 'account_id', 'file_name', 'checksum',
        'row_count', 'imported_count', 'rejected_count', 'status', 'errors', 'imported_by',
    ];

    protected $casts = [
        'errors' => 'array',
        'row_count' => 'integer',
        'imported_count' => 'integer',
        'rejected_count' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'import_id');
    }

    public function isImport(): bool
    {
        return $this->status === self::STATUS_IMPORTED;
    }
}
