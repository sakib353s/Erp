<?php

namespace App\Http\Controllers;

use App\Domain\Business\Notice;
use App\Domain\Business\Services\NoticeService;
use App\Domain\Foundation\Role;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Branch;
use App\Http\Requests\StoreNoticeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * §12-12 — the notice board.
 *
 * Four screens, four permissions' worth of intent: the board (what is live for
 * *you*), the register (everything, including drafts), one notice (which is also
 * where you acknowledge it), and the tracking page (who has not).
 *
 * The audience a notice declares is stored, and every screen reads it back
 * through {@see NoticeService} — so “who is this for” has one answer, and the
 * page that lists the people who have not acknowledged it uses the same one.
 */
class NoticeController extends Controller
{
    public function __construct(protected NoticeService $notices) {}

    /** The board: live notices addressed to me, newest first. */
    public function index(Request $request): View
    {
        $person = $request->user();

        $board = $this->notices->board(
            companyId: (int) $person->company_id,
            person: $person,
            category: $request->string('category')->toString() ?: null,
        );

        $awaiting = $this->notices->awaitingAcknowledgement((int) $person->company_id, $person)
            ->map(fn (Notice $notice): int => $notice->id)
            ->all();

        return view('business.notices.index', [
            'notices' => $board,
            'awaiting' => $awaiting,
            'category' => $request->string('category')->toString(),
            'categories' => Notice::CATEGORIES,
            'canPublish' => (bool) $person->can('business.notices.create'),
        ]);
    }

    /** The register: every notice of the company, with its audience and tally. */
    public function register(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        $query = Notice::query()
            ->where('company_id', $companyId)
            ->with('creator')
            ->orderByDesc('created_at');

        if (($status = $request->string('status')->toString()) !== '') {
            $query->where('status', $status);
        }

        if (($category = $request->string('category')->toString()) !== '') {
            $query->where('category', $category);
        }

        if (($search = trim($request->string('q')->toString())) !== '') {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('body', 'like', '%'.$search.'%'));
        }

        return view('business.notices.register', [
            'notices' => $query->paginate(20)->withQueryString(),
            'status' => $request->string('status')->toString(),
            'category' => $request->string('category')->toString(),
            'search' => $search,
            'categories' => Notice::CATEGORIES,
            'statuses' => Notice::STATUSES,
        ]);
    }

    public function create(Request $request): View
    {
        return view('business.notices.create', [
            'categories' => Notice::CATEGORIES,
            'modes' => [
                Notice::AUDIENCE_ALL => 'Everybody in the company',
                Notice::AUDIENCE_ROLES => 'People holding certain roles',
                Notice::AUDIENCE_BRANCHES => 'People of certain branches',
                Notice::AUDIENCE_USERS => 'Named people',
            ],
            'roles' => $this->roles((int) $request->user()->company_id),
            'branches' => $this->branches((int) $request->user()->company_id),
            'people' => $this->people((int) $request->user()->company_id),
        ]);
    }

    public function store(StoreNoticeRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        $notice = $this->notices->create([
            'title' => $data['title'],
            'body' => $data['body'],
            'category' => $data['category'],
            'audience_type' => $data['audience_type'],
            'audience' => $request->audienceIds(),
            'requires_acknowledgement' => (bool) ($data['requires_acknowledgement'] ?? false),
            'expires_at' => $data['expires_at'] ?? null,
        ], $actor);

        if (($data['intent'] ?? 'draft') === 'publish') {
            $reached = $this->notices->publish($notice, $actor);

            return redirect()->route('notices.show', $notice)
                ->with('status', sprintf('“%s” published to %d people.', $notice->title, $reached));
        }

        return redirect()->route('notices.show', $notice)->with('status', 'Saved as a draft.');
    }

    public function show(Request $request, Notice $notice): View
    {
        $this->guardCompany($request, $notice);

        $person = $request->user();

        /*
         * The audience is not decoration: a notice addressed to the people of
         * one branch is not a notice the rest of the company may read by typing
         * its URL. Publishers see everything (including drafts) — that is what
         * the register is for — and everybody else sees only what was addressed
         * to them.
         */
        if (! $person->can('business.notices.create')) {
            abort_unless($notice->status === 'published', 404);

            abort_unless(
                $this->notices->audience($notice)->contains('id', $person->id),
                403,
                'This notice is addressed to a different audience.',
            );
        }

        $ledger = $this->notices->ledger($notice);

        return view('business.notices.show', [
            'notice' => $notice->load('creator'),
            'ledger' => $ledger,
            'mine' => $person->id === $notice->created_by || $person->can('business.notices.create'),
            'acknowledged' => $notice->acknowledgements()->where('user_id', $person->id)->exists(),
            'audienceLabel' => $this->notices->audienceLabel($notice),
            'canTrack' => (bool) $person->can('business.notices.create'),
        ]);
    }

    /** Acknowledgment tracking: every live notice that asks for one, with its tally. */
    public function tracking(Request $request): View
    {
        return view('business.notices.tracking', [
            'rows' => $this->notices->tracking((int) $request->user()->company_id),
        ]);
    }

    public function publish(Request $request, Notice $notice): RedirectResponse
    {
        $this->guardCompany($request, $notice);

        $reached = $this->notices->publish($notice, $request->user());

        return back()->with('status', sprintf('Published to %d people.', $reached));
    }

    public function archive(Request $request, Notice $notice): RedirectResponse
    {
        $this->guardCompany($request, $notice);

        $this->notices->archive($notice, $request->user());

        return back()->with('status', 'Notice archived. It stays on the register and in the audit trail.');
    }

    /**
     * “I have read this.” A second click is not a second acknowledgement — the
     * service returns the row that already exists — and a notice that asks for
     * none says so instead of pretending to record something.
     */
    public function acknowledge(Request $request, Notice $notice): RedirectResponse
    {
        $this->guardCompany($request, $notice);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $row = $this->notices->acknowledge($notice, $request->user(), $validated['note'] ?? null);

        if ($row === null) {
            return back()->with('status', 'This notice does not ask for acknowledgement — there is nothing to record.');
        }

        return back()->with('status', $row->wasRecentlyCreated
            ? 'Acknowledged. Your name is on the notice’s ledger.'
            : 'You had already acknowledged this notice — the first record stands.');
    }

    /* ------------------------------------------------------------- helpers */

    /** @return Collection<int, Role> */
    protected function roles(int $companyId): Collection
    {
        return Role::query()->where('company_id', $companyId)->orderBy('name')->get();
    }

    /** @return Collection<int, Branch> */
    protected function branches(int $companyId): Collection
    {
        return Branch::query()->where('company_id', $companyId)->orderBy('name')->get();
    }

    /** @return Collection<int, User> */
    protected function people(int $companyId): Collection
    {
        return User::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get();
    }

    protected function guardCompany(Request $request, Notice $notice): void
    {
        abort_if((int) $notice->company_id !== (int) $request->user()->company_id, 404);
    }
}
