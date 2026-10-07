<?php

namespace App\Domain\Inventory;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One catalogue import run (§04-12): what the file was called, who sent it,
 * how many rows landed, and every row that did not.
 *
 * `status` is honest about partial work — `imported_with_errors` means exactly
 * what it says: some rows are in the catalogue and some are not, and the error
 * list names which. A run is never edited after the fact; a re-import is a new
 * row, because the second file is a different statement from the first.
 */
class ProductImport extends Model
{
    public const STATUS_PREVIEW = 'preview';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_IMPORTED_WITH_ERRORS = 'imported_with_errors';

    protected $fillable = [
        'company_id', 'user_id', 'original_name', 'status', 'dry_run',
        'rows_total', 'rows_created', 'rows_updated', 'rows_skipped', 'rows_failed',
        'headers', 'errors',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'headers' => 'array',
        'errors' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @param Builder<ProductImport> $query */
    public function scopeRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function stateLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PREVIEW => 'Preview',
            self::STATUS_IMPORTED => 'Imported',
            default => 'Imported with errors',
        };
    }

    /** Status badge tone, so the list reads at a glance. */
    public function stateTone(): string
    {
        return match ($this->status) {
            self::STATUS_PREVIEW => 'undated',
            self::STATUS_IMPORTED => 'ok',
            default => 'expiring',
        };
    }

    public function wroteAnything(): bool
    {
        return ! $this->dry_run && ($this->rows_created + $this->rows_updated) > 0;
    }
}
