<?php

namespace App\Domain\Operations;

use App\Domain\Foundation\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One maintenance operation, with its figures (§15-23…§15-33).
 *
 * The desk reads this table twice: to show what was done recently and by whom,
 * and — for the two operations that must not run blind — as the state they are
 * allowed to act on. A database repair may only touch a table the last integrity
 * check named, so “the last check” has to be a row, not a memory.
 */
class MaintenanceRun extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_OK = 'ok';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'action',
        'status',
        'summary',
        'details',
        'freed_bytes',
        'actor_id',
        'actor_label',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'freed_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** A human label for the action, for the history table. */
    public function label(): string
    {
        return self::LABELS[$this->action] ?? $this->action;
    }

    public const LABELS = [
        'cache.clear' => 'Cache cleared',
        'sessions.clear' => 'Sessions cleared',
        'temp.clear' => 'Temporary files removed',
        'db.optimize' => 'Database optimised',
        'db.integrity' => 'Integrity check',
        'db.repair' => 'Database repaired',
        'settings.reset' => 'Settings reset to defaults',
        'self.heal' => 'Self-healing run',
        'search.rebuild' => 'Search index rebuilt',
    ];

    /** “1.4 MB” — sizes are shown in units a person reads, not in bytes. */
    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        foreach (['KB', 'MB', 'GB', 'TB'] as $unit) {
            $bytes /= 1024;

            if ($bytes < 1024) {
                return number_format($bytes, $bytes < 10 ? 1 : 0).' '.$unit;
            }
        }

        return number_format($bytes, 0).' PB';
    }
}
