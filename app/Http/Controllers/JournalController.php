<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\Actions\CreateManualJournal;
use App\Domain\Accounting\Actions\PostReversal;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\Services\LedgerService;
use App\Http\Requests\ReverseJournalRequest;
use App\Http\Requests\StoreJournalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Journal entry screens (09-06…09-10). All posting flows through
 * CreateManualJournal → JournalPostingService; reversal is a separate
 * permissioned action. Posted entries have no edit path.
 */
class JournalController extends Controller
{
    public function __construct(
        protected CreateManualJournal $createJournal,
        protected PostReversal $postReversal,
        protected LedgerService $ledger,
    ) {}

    public function index(Request $request): View
    {
        $query = JournalEntry::query()
            ->with(['fiscalPeriod', 'poster'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if ($state = $request->query('state')) {
            $query->where('posting_state', $state);
        }

        if ($type = $request->query('type')) {
            $query->where('journal_type', $type);
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($search) {
                $q->where('entry_no', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->query('source') === 'auto') {
            $query->where('journal_type', '!=', 'manual');
        }

        return view('accounting.journals.index', [
            'entries' => $query->paginate(20)->withQueryString(),
            'state' => $request->query('state'),
            'type' => $request->query('type'),
            'q' => $request->query('q'),
        ]);
    }

    public function create(): View
    {
        return view('accounting.journals.create', [
            'accounts' => Account::query()->postable()->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'entryDate' => now()->toDateString(),
        ]);
    }

    public function store(StoreJournalRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $payload['journal_type'] = 'manual';
        $payload['branch_id'] = $payload['branch_id'] ?? $request->user()->default_branch_id;

        try {
            $entry = $this->createJournal->handle($payload, $request);
        } catch (\RuntimeException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()
            ->route('accounting.journals.show', $entry)
            ->with('status', "Journal {$entry->entry_no} posted.");
    }

    public function show(JournalEntry $journal): View
    {
        $journal->load(['lines.account', 'fiscalPeriod', 'creator', 'poster', 'reversalOf', 'reversals']);

        return view('accounting.journals.show', [
            'entry' => $journal,
            'ledger' => null,
        ]);
    }

    public function reverse(ReverseJournalRequest $request, JournalEntry $journal): RedirectResponse
    {
        try {
            $reversal = $this->postReversal->handle(
                $journal,
                $request->validated('reason'),
                $request,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()
            ->route('accounting.journals.show', $reversal)
            ->with('status', "Reversed {$journal->entry_no} via {$reversal->entry_no}.");
    }

    /** General ledger for one account (09-32). */
    public function ledger(Request $request, Account $account): View
    {
        $from = $request->query('from')
            ? Carbon::parse($request->query('from'))
            : null;
        $to = $request->query('to')
            ? Carbon::parse($request->query('to'))
            : null;

        return view('accounting.ledger', [
            'account' => $account,
            'ledger' => $this->ledger->accountLedger($account, $from, $to),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);
    }
}
