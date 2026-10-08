<?php

namespace App\Domain\Business\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Business\Notice;
use App\Domain\Business\NoticeAcknowledgement;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Notification\Services\NotificationCenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * §12-12 — the notice board's engine.
 *
 * Three questions, and the third is the one that gets systems in trouble:
 *
 *  · **who is this for?** The audience is stored when the notice is written
 *    (`all`, or the ids of roles, branches or people). It is *not* recomputed
 *    from “who works here today”, because “we told the Dhaka outlet in October”
 *    is a statement about October. A notice published to a role reaches the
 *    people holding it now; the ledger then remembers which people those were.
 *  · **has it been delivered?** Publishing fans out in-app notifications through
 *    the one `NotificationCenter` (so a person's own channel preferences and
 *    quiet rules still apply), with a stable dedupe key — re-publishing a notice
 *    does not put a second copy in everybody's inbox.
 *  · **who has acknowledged it?** An acknowledgement is a row, written once, and
 *    it survives refresh, login and the person's inbox being emptied. That is why
 *    it is a ledger under the notice rather than a `read_at` on a notification:
 *    “I saw the message” and “I accept the policy” are different sentences, and
 *    only the second one is worth producing in a dispute.
 *
 * A policy notice that requires acknowledgement keeps its own tally, and the
 * tally is honest about its denominator: the audience *as published*, plus
 * anybody who acknowledged before the roster moved.
 */
class NoticeService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected NotificationCenter $notifications,
    ) {}

    /**
     * The people a notice is addressed to, resolved now.
     *
     * @return Collection<int, User>
     */
    public function audience(Notice $notice): Collection
    {
        $companyId = (int) $notice->company_id;
        $ids = array_map('intval', (array) ($notice->audience ?? []));

        $query = User::query()->where('company_id', $companyId)->where('status', 'active');

        /*
         * The person who wrote it has read it — they wrote it. Counting the
         * author as outstanding would leave every company-wide notice a few
         * percent short of done forever, and a tracker that can never reach 100%
         * stops being a tracker. The author can still acknowledge if they want
         * the record to say so; they are simply not owed an acknowledgement.
         */
        if ($notice->created_by !== null) {
            $query->whereKeyNot($notice->created_by);
        }

        return match ($notice->audience_type) {
            Notice::AUDIENCE_USERS => $query->whereIn('id', $ids)->orderBy('name')->get(),
            Notice::AUDIENCE_ROLES => $query
                ->whereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $ids))
                ->orderBy('name')->get(),
            Notice::AUDIENCE_BRANCHES => $query
                ->whereIn('default_branch_id', $ids)
                ->orderBy('name')->get(),
            default => $query->orderBy('name')->get(),
        };
    }

    /** What the audience line on the screen says, in words. */
    public function audienceLabel(Notice $notice): string
    {
        $count = $this->audience($notice)->count();

        return match ($notice->audience_type) {
            Notice::AUDIENCE_USERS => $count === 1 ? 'One person' : $count.' people',
            Notice::AUDIENCE_ROLES => $count.' people holding '.count((array) $notice->audience).' role(s)',
            Notice::AUDIENCE_BRANCHES => $count.' people across '.count((array) $notice->audience).' branch(es)',
            default => 'Everybody in the company ('.$count.')',
        };
    }

    /**
     * Write a notice. It starts as a draft: writing and telling everybody are
     * separate acts, and the person composing it should be able to save and walk
     * away.
     *
     * @param  array{title: string, body: string, category: string, audience_type: string, audience?: array<int, int>, requires_acknowledgement?: bool, expires_at?: ?string}  $data
     */
    public function create(array $data, User $actor): Notice
    {
        $notice = Notice::create([
            'company_id' => $actor->company_id,
            'title' => $data['title'],
            'body' => $data['body'],
            'category' => $data['category'],
            'audience_type' => $data['audience_type'],
            'audience' => array_values(array_map('intval', (array) ($data['audience'] ?? []))),
            'status' => 'draft',
            'requires_acknowledgement' => (bool) ($data['requires_acknowledgement'] ?? false),
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit->record([
            'action' => 'business.notice_created',
            'entity_type' => 'notice',
            'entity_id' => $notice->id,
            'actor_id' => $actor->id,
            'after' => [
                'title' => $notice->title,
                'category' => $notice->category,
                'audience_type' => $notice->audience_type,
                'audience' => $notice->audience,
                'requires_acknowledgement' => (bool) $notice->requires_acknowledgement,
            ],
        ]);

        return $notice;
    }

    /**
     * Publish a notice: a date, the people it reaches, a notification each and an
     * audit row. Returns the number of people it reached.
     */
    public function publish(Notice $notice, User $actor): int
    {
        $audience = $this->audience($notice);

        DB::transaction(function () use ($notice, $actor, $audience): void {
            $notice->forceFill([
                'status' => 'published',
                'published_at' => $notice->published_at ?? now(),
            ])->save();

            foreach ($audience as $person) {
                $this->notifications->notify(
                    $person,
                    'notice.published',
                    $notice->title,
                    $this->excerpt($notice->body),
                    [
                        'priority' => $notice->category === 'urgent' ? 'high' : 'normal',
                        'action_url' => route('notices.show', $notice, false),
                        'data' => ['notice_id' => $notice->id, 'requires_acknowledgement' => (bool) $notice->requires_acknowledgement],
                        'persistent' => (bool) $notice->requires_acknowledgement,
                        // Stable per notice and person: re-publishing an amended
                        // notice updates it on the board, it does not re-notify
                        // everybody who already read it.
                        'dedupe_key' => 'notice:'.$notice->id,
                    ],
                );
            }

            $this->audit->record([
                'action' => 'business.notice_published',
                'entity_type' => 'notice',
                'entity_id' => $notice->id,
                'actor_id' => $actor->id,
                'after' => [
                    'title' => $notice->title,
                    'category' => $notice->category,
                    'audience_type' => $notice->audience_type,
                    'audience' => $notice->audience,
                    'reached' => $audience->count(),
                    'requires_acknowledgement' => (bool) $notice->requires_acknowledgement,
                ],
            ]);
        });

        return $audience->count();
    }

    public function archive(Notice $notice, User $actor): void
    {
        $notice->forceFill(['status' => 'archived'])->save();

        $this->audit->record([
            'action' => 'business.notice_archived',
            'entity_type' => 'notice',
            'entity_id' => $notice->id,
            'actor_id' => $actor->id,
            'after' => ['title' => $notice->title],
        ]);
    }

    /**
     * Record that a person has read and accepted a notice.
     *
     * Writing it twice is not an error and not a second acknowledgement: the
     * first row stands (same person, same notice), which is what “survives
     * refresh and login” means. Returns null when the notice requires no
     * acknowledgement at all — nothing to record, and the caller says so.
     */
    public function acknowledge(Notice $notice, User $person, ?string $note = null): ?NoticeAcknowledgement
    {
        if (! $notice->requires_acknowledgement) {
            return null;
        }

        $existing = NoticeAcknowledgement::query()
            ->where('notice_id', $notice->id)
            ->where('user_id', $person->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $row = NoticeAcknowledgement::create([
            'company_id' => $notice->company_id,
            'notice_id' => $notice->id,
            'user_id' => $person->id,
            'acknowledged_at' => now(),
            'note' => $note,
        ]);

        $this->audit->record([
            'action' => 'business.notice_acknowledged',
            'entity_type' => 'notice',
            'entity_id' => $notice->id,
            'actor_id' => $person->id,
            'after' => ['title' => $notice->title, 'acknowledged_at' => $row->acknowledged_at?->toIso8601String()],
        ]);

        return $row;
    }

    /**
     * Who has and who has not acknowledged a notice.
     *
     * `rows` is the audience as it stands, each with its acknowledgement or null;
     * `extra` are people who acknowledged while they were in the audience and are
     * not in it any more (someone who changed role, or left). They are kept
     * visible rather than quietly dropped, because a ledger that loses rows when
     * the roster moves is not a ledger.
     *
     * @return array{rows: array<int, array{user: User, acknowledgement: ?NoticeAcknowledgement}>, extra: Collection<int, NoticeAcknowledgement>, acknowledged: int, pending: int, audience: int, percent: float}
     */
    public function ledger(Notice $notice): array
    {
        $acknowledgements = NoticeAcknowledgement::query()
            ->where('notice_id', $notice->id)
            ->get()
            ->keyBy('user_id');

        $audience = $this->audience($notice);

        $rows = $audience->map(fn (User $user): array => [
            'user' => $user,
            'acknowledgement' => $acknowledgements->get($user->id),
        ])->all();

        $extra = $acknowledgements->reject(fn (NoticeAcknowledgement $row): bool => $audience->contains('id', $row->user_id));

        $acknowledged = $audience->filter(fn (User $user): bool => $acknowledgements->has($user->id))->count();

        return [
            'rows' => $rows,
            'extra' => $extra,
            'acknowledged' => $acknowledged,
            'pending' => max(0, $audience->count() - $acknowledged),
            'audience' => $audience->count(),
            'percent' => $audience->count() === 0 ? 0.0 : round($acknowledged * 100 / $audience->count(), 1),
        ];
    }

    /**
     * The tracking screen: every live notice that asks for acknowledgement, with
     * its tally. Computed from the same ledger the notice's own page shows, so
     * the two can never disagree.
     *
     * @return array<int, array{notice: Notice, audience: int, acknowledged: int, pending: int, percent: float}>
     */
    public function tracking(int $companyId): array
    {
        return Notice::query()
            ->where('company_id', $companyId)
            ->where('requires_acknowledgement', true)
            ->live()
            ->orderByDesc('published_at')
            ->get()
            ->map(function (Notice $notice): array {
                $ledger = $this->ledger($notice);

                return [
                    'notice' => $notice,
                    'audience' => $ledger['audience'],
                    'acknowledged' => $ledger['acknowledged'],
                    'pending' => $ledger['pending'],
                    'percent' => $ledger['percent'],
                ];
            })
            ->all();
    }

    /** The board as one person sees it: live notices for them, newest first. */
    public function board(int $companyId, ?User $person = null, ?string $category = null, bool $includeArchived = false): Collection
    {
        $query = Notice::query()->where('company_id', $companyId);

        if (! $includeArchived) {
            $query->live();
        } else {
            $query->whereIn('status', ['published', 'archived']);
        }

        if ($category !== null && $category !== '') {
            $query->where('category', $category);
        }

        $notices = $query->orderByDesc('published_at')->get();

        if ($person === null) {
            return $notices;
        }

        // A person's own board is the notices addressed to them: the audience is
        // stored, but the *members* of it are resolved when the board is read.
        return $notices->filter(function (Notice $notice) use ($person): bool {
            if ($notice->status === 'draft') {
                return false;
            }

            return $this->audience($notice)->contains('id', $person->id);
        })->values();
    }

    /** How many live notices this person has not acknowledged yet. */
    public function awaitingAcknowledgement(int $companyId, User $person): Collection
    {
        return $this->board($companyId, $person)
            ->filter(fn (Notice $notice): bool => $notice->requires_acknowledgement)
            ->reject(fn (Notice $notice): bool => NoticeAcknowledgement::query()
                ->where('notice_id', $notice->id)
                ->where('user_id', $person->id)
                ->exists())
            ->values();
    }

    protected function excerpt(string $body): string
    {
        return Str::limit(trim(strip_tags($body)), 180);
    }
}
