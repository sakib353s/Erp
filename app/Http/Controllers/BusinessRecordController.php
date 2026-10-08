<?php

namespace App\Http\Controllers;

use App\Domain\Business\BusinessRecord;
use App\Domain\Business\RecordsRegistry;
use App\Domain\Business\Services\BusinessRecordService;
use App\Domain\Documents\Document;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Http\Requests\RenewBusinessRecordRequest;
use App\Http\Requests\StoreBusinessRecordRequest;
use App\Http\Requests\UpdateBusinessRecordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * §12-03/04/09/10 — the registers behind the company's identity, its papers and
 * its compliance calendar.
 *
 * One controller for nine kinds of record is not a shortcut: the screens are one
 * screen with a different configuration (which columns, which fields, which
 * dates), and the controller asks {@see RecordsRegistry} rather than deciding for
 * itself. The consequence is that a contract's page and a licence's page cannot
 * drift apart, and a new kind of record is a registry entry rather than a fifth
 * copy of the same CRUD.
 *
 * Two rules run through everything here:
 *
 *  · **company and branch** — a record belonging to another company is a 404, and
 *    one belonging to a branch the person cannot see is a 404 too (not a 403:
 *    the register does not confirm that something exists there).
 *  · **the work is in the service** — renewing, completing, attaching and
 *    retiring all write history and audit rows, and those belong in one place.
 */
class BusinessRecordController extends Controller
{
    public function __construct(
        protected BusinessRecordService $records,
        protected RecordsRegistry $registry,
    ) {}

    /** The desk: every shelf, the register, and the counts that matter. */
    public function index(Request $request): View
    {
        $person = $request->user();

        $filters = [
            'kind' => $request->string('kind')->toString() ?: null,
            'group' => $request->string('group')->toString() ?: null,
            'state' => $request->string('state')->toString(),
            'q' => $request->string('q')->toString(),
            'branch_id' => $request->query('branch_id'),
            'retired' => $request->string('state')->toString() === 'retired',
        ];

        return view('business.records.index', [
            'registry' => $this->registry,
            'records' => $this->records->register($filters),
            'filters' => $filters,
            'summary' => $this->records->summary($filters['kind'] ?: null),
            'counts' => $this->records->kindCounts(),
            'branches' => $this->branchOptions($person),
            'canManage' => (bool) $person->can('business.records.manage'),
        ]);
    }

    /** One kind's register — the page every shelf leaf in the menu lands on. */
    public function kind(Request $request, string $kind): View
    {
        $key = $this->registry->kindForSlug($kind);

        abort_if($key === null, 404);

        $filters = [
            'kind' => $key,
            'state' => $request->string('state')->toString(),
            'q' => $request->string('q')->toString(),
            'branch_id' => $request->query('branch_id'),
            'retired' => $request->string('state')->toString() === 'retired',
        ];

        return view('business.records.kind', [
            'registry' => $this->registry,
            'kind' => $key,
            'config' => $this->registry->config($key),
            'records' => $this->records->register($filters),
            'filters' => $filters,
            'summary' => $this->records->summary($key),
            'branches' => $this->branchOptions($request->user()),
            'canManage' => (bool) $request->user()->can('business.records.manage'),
        ]);
    }

    /** One record: what it says, the papers filed against it, and its history. */
    public function show(Request $request, BusinessRecord $record): View
    {
        $this->guard($record, $request);

        $record->load(['files.document', 'files.attacher', 'events.actor', 'branch', 'creator']);

        $filed = $record->files->pluck('document_id')->all();

        return view('business.records.show', [
            'registry' => $this->registry,
            'record' => $record,
            'canManage' => (bool) $request->user()->can('business.records.manage'),
            'attachable' => Document::query()
                ->where('company_id', $record->company_id)
                ->when($filed !== [], fn ($q) => $q->whereNotIn('id', $filed))
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'original_name', 'purpose', 'extension']),
            'libraryUrl' => route('documents.index'),
            'groups' => $this->registry->groups(),
        ]);
    }

    public function store(StoreBusinessRecordRequest $request): RedirectResponse
    {
        $record = $this->records->create($request->recordData(), $request->user());

        return redirect()
            ->route('records.show', $record)
            ->with('status', $record->describe().' recorded.');
    }

    public function update(UpdateBusinessRecordRequest $request, BusinessRecord $record): RedirectResponse
    {
        $this->guard($record, $request);

        $this->records->update($record, $request->recordData(), $request->user());

        return back()->with('status', 'Record updated. The previous values are in the audit trail.');
    }

    /** Extend an expiry, keeping the old date on the record's own history. */
    public function renew(RenewBusinessRecordRequest $request, BusinessRecord $record): RedirectResponse
    {
        $this->guard($record, $request);

        $this->records->renew($record, $request->user(), $request->validated());

        return back()->with(
            'status',
            'Renewed to '.$record->fresh()->expires_on->format('d M Y').'. The previous date stays in the history.',
        );
    }

    /** Stamp a recurring record done and let it set its own next date. */
    public function complete(Request $request, BusinessRecord $record): RedirectResponse
    {
        $this->guard($record, $request);

        $data = $request->validate([
            'completed_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->records->complete($record, $request->user(), $data);

        $fresh = $record->fresh();

        return back()->with('status', $fresh->due_on !== null
            ? 'Recorded. Next due '.$fresh->due_on->format('d M Y').'.'
            : 'Recorded. This one does not repeat, so nothing is due next.');
    }

    /** File a paper from the document library against this record. */
    public function attach(Request $request, BusinessRecord $record): RedirectResponse
    {
        $this->guard($record, $request);

        $data = $request->validate([
            'document_id' => [
                'required', 'integer',
                Rule::exists('documents', 'id')->where('company_id', $record->company_id),
            ],
            'label' => ['nullable', 'string', 'max:120'],
        ], [
            'document_id.exists' => 'That file is not in this company’s document library.',
        ]);

        $document = Document::query()->whereKey($data['document_id'])->firstOrFail();

        $file = $this->records->attach($record, $document, $request->user(), $data['label'] ?? null);

        return back()->with('status', $file->wasRecentlyCreated
            ? 'Filed “'.$file->label().'”.'
            : 'That file is already filed against this record.');
    }

    public function detach(Request $request, BusinessRecord $record, Document $document): RedirectResponse
    {
        $this->guard($record, $request);
        abort_unless((int) $document->company_id === (int) $record->company_id, 404);

        $this->records->detach($record, $document, $request->user());

        return back()->with('status', 'Unfiled. The file itself stays in the document library.');
    }

    /** Retire a record. It leaves the working list; it does not leave the books. */
    public function retire(Request $request, BusinessRecord $record): RedirectResponse
    {
        $this->guard($record, $request);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:255']],
            ['reason.required' => 'A record is retired for a reason — say what it was, because the row is kept.'],
            ['reason' => 'reason'],
        );

        $this->records->retire($record, $request->user(), $data['reason']);

        return back()->with('status', 'Retired. It stays on the register and in the audit trail.');
    }

    /* ------------------------------------------------------------- the lenses */

    /** What has lapsed, what is about to, and what carries no date at all. */
    public function renewals(Request $request): View
    {
        $kind = $request->string('kind')->toString() ?: null;

        if ($kind !== null && ! $this->registry->has($kind)) {
            $kind = null;
        }

        return view('business.compliance.renewals', [
            'registry' => $this->registry,
            'lenses' => $this->records->renewals(RecordsRegistry::HORIZON_DAYS, $kind),
            'summary' => $this->records->summary($kind),
            'kind' => $kind,
        ]);
    }

    /** One month of expiries and deadlines, laid out as the weeks it really has. */
    public function calendar(Request $request): View
    {
        $month = $request->string('month')->toString() ?: null;

        return view('business.compliance.calendar', [
            'registry' => $this->registry,
            'calendar' => $this->records->calendar($month),
            'summary' => $this->records->summary(),
        ]);
    }

    /** The recurring half, grouped by how often it comes round. */
    public function obligations(Request $request): View
    {
        return view('business.compliance.obligations', [
            'registry' => $this->registry,
            'groups' => $this->records->obligations(),
            'summary' => $this->records->summary(),
            'canManage' => (bool) $request->user()->can('business.records.manage'),
        ]);
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * A record from another company, or from a branch this person cannot see, is
     * simply not here.
     */
    private function guard(BusinessRecord $record, Request $request): void
    {
        abort_unless((int) $record->company_id === (int) $request->user()->company_id, 404);

        $ids = $request->user()->accessibleBranchIds();

        if ($ids !== null && $record->branch_id !== null && ! in_array((int) $record->branch_id, $ids, true)) {
            abort(404);
        }
    }

    /** @return Collection<int, Branch> */
    private function branchOptions(User $person): Collection
    {
        $ids = $person->accessibleBranchIds();

        return Branch::query()
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
}
