<?php

namespace App\Domain\Business;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * §12-16 — one person who has ever walked through the gate.
 *
 * The row is the *person*, not the visit: it collects the history (how often,
 * who do they come to see, when were they last here) and the one flag the gate
 * acts on. A visit is closed and never rewritten; a person's row is the thing
 * that gets edited, because addresses and phone numbers change and a blacklist
 * can be lifted as well as imposed.
 *
 * Nothing about the person's papers is displayed as a matter of course — an ID
 * number is personal data, so the model offers `maskedIdNumber()` and the
 * screens show the last four digits unless somebody opens the visit itself.
 */
class Visitor extends Model
{
    public const ID_TYPES = [
        'nid' => 'National ID',
        'passport' => 'Passport',
        'driving' => 'Driving licence',
        'other' => 'Other paper',
    ];

    protected $fillable = [
        'company_id', 'name', 'phone', 'email', 'organisation', 'id_type', 'id_number',
        'is_blacklisted', 'blacklist_reason', 'notes',
    ];

    protected $casts = [
        'is_blacklisted' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(VisitorVisit::class);
    }

    public function isBlacklisted(): bool
    {
        return (bool) $this->is_blacklisted;
    }

    /** The most recent completed visit, for the "last seen" column. */
    public function lastVisit(): ?VisitorVisit
    {
        return $this->visits()->orderByDesc('created_at')->first();
    }

    public function idTypeLabel(): ?string
    {
        return $this->id_type !== null ? (self::ID_TYPES[$this->id_type] ?? ucfirst((string) $this->id_type)) : null;
    }

    /** ••••1234 — enough to match against a paper, not enough to leak it. */
    public function maskedIdNumber(): ?string
    {
        $number = trim((string) $this->id_number);

        if ($number === '') {
            return null;
        }

        $tail = substr($number, -4);

        return str_repeat('•', max(0, min(6, strlen($number) - 4))).$tail;
    }

    public function label(): string
    {
        return $this->name.($this->organisation ? ' · '.$this->organisation : '');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
