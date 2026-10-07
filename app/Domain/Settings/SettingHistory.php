<?php

namespace App\Domain\Settings;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingHistory extends Model
{
    /**
     * The migration creates `setting_history` (singular, timestamped via
     * changed_at only) — pin the table and drop the implicit created_at
     * so Eloquent writes match the real schema.
     */
    protected $table = 'setting_history';

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = [
        'setting_id', 'old_value', 'new_value', 'changed_by', 'correlation_id', 'changed_at',
    ];

    protected $casts = ['changed_at' => 'datetime'];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
