<?php

namespace App\Domain\Business;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §12-12 — one notice, and who it was for.
 *
 * The audience is *stored*, not recomputed: “the Dhaka outlet, 12 October” is a
 * fact about what was published, and it must not change because somebody joined
 * in November. `audience_type` says what the ids in `audience` mean, so the
 * acknowledgement ledger can always answer the only question that matters after
 * a notice goes out — who has not seen it yet — with the same names it was sent
 * to.
 */
class Notice extends Model
{
    use Auditable;

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_ROLES = 'roles';

    public const AUDIENCE_BRANCHES = 'branches';

    public const AUDIENCE_USERS = 'users';

    public const CATEGORIES = [
        'general' => 'General',
        'policy' => 'Policy',
        'urgent' => 'Urgent',
        'hr' => 'People & HR',
        'finance' => 'Finance',
    ];

    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];

    protected $fillable = [
        'company_id', 'title', 'body', 'category', 'audience_type', 'audience',
        'status', 'published_at', 'expires_at', 'requires_acknowledgement', 'created_by',
    ];

    protected $casts = [
        'audience' => 'array',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'requires_acknowledgement' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(NoticeAcknowledgement::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /** Still in force: published, and either open-ended or not yet expired. */
    public function scopeLive($query)
    {
        return $query->published()
            ->whereNotNull('published_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isLive(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }
}
