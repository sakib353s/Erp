<?php

namespace App\Domain\Business;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §12-13 — a piece of work, who owns it, and where it has got to.
 *
 * The states are a small closed set with a legal-transition map, because “done”
 * can be reopened and “cancelled” cannot, and a board that lets anybody drag any
 * card anywhere is a board nobody trusts. The map is data here and the gate is
 * {@see Services\TaskService::transition()} — one place decides.
 */
class Task extends Model
{
    use Auditable;

    public const STATUS_TODO = 'todo';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_REVIEW = 'review';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_TODO => 'To do',
        self::STATUS_IN_PROGRESS => 'In progress',
        self::STATUS_BLOCKED => 'Blocked',
        self::STATUS_REVIEW => 'In review',
        self::STATUS_DONE => 'Done',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    /** The order a kanban board shows them in, left to right. */
    public const BOARD_ORDER = [
        self::STATUS_TODO,
        self::STATUS_IN_PROGRESS,
        self::STATUS_BLOCKED,
        self::STATUS_REVIEW,
        self::STATUS_DONE,
    ];

    public const TERMINAL = [self::STATUS_DONE, self::STATUS_CANCELLED];

    /** status => the statuses it may move to. */
    public const TRANSITIONS = [
        self::STATUS_TODO => [self::STATUS_IN_PROGRESS, self::STATUS_BLOCKED, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_REVIEW, self::STATUS_BLOCKED, self::STATUS_DONE, self::STATUS_CANCELLED],
        self::STATUS_BLOCKED => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_REVIEW => [self::STATUS_IN_PROGRESS, self::STATUS_DONE, self::STATUS_CANCELLED],
        self::STATUS_DONE => [self::STATUS_IN_PROGRESS],
        self::STATUS_CANCELLED => [],
    ];

    public const PRIORITIES = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    protected $fillable = [
        'company_id', 'project_id', 'title', 'description', 'status', 'priority',
        'assigned_to', 'created_by', 'branch_id', 'due_at', 'completed_at', 'position',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'position' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->orderByDesc('created_at');
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', self::TERMINAL);
    }

    public function scopeOverdue($query)
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, self::TERMINAL, true);
    }

    /** Overdue is a question about the clock, not a stored flag that goes stale. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? ucfirst((string) $this->priority);
    }

    /** @return array<int, string> */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }
}
