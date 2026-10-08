<?php

namespace App\Domain\Business;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** §12-13 — a container for tasks, with an owner and a due date. */
class Project extends Model
{
    use Auditable;

    public const STATUSES = ['active' => 'Active', 'on_hold' => 'On hold', 'completed' => 'Completed'];

    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'status',
        'starts_on', 'due_on', 'owner_id',
    ];

    protected $casts = ['starts_on' => 'date', 'due_on' => 'date'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }
}
