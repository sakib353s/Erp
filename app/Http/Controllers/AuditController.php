<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditArchive;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditVocabulary;
use App\Domain\Audit\Services\AuditChainVerifier;
use App\Http\Requests\ExportAuditRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;

/**
 * §16-35 — the tamper-evident audit viewer (Rule 16).
 *
 * Filterable server-side, permission-gated, and **scoped twice**: to the acting
 * company, and — for a user limited to branches — to the branches they may read.
 * The branch filter was once written as `whereIn(...)->orWhere(...)`, which is
 * one predicate too few: the `orWhere` escaped the branch group *and* the
 * company filter, so a company-wide event of the acting user was matched
 * regardless of which company it belonged to. It is grouped now, and an explicit
 * company predicate sits in front of it, because "the trail of this company" is
 * the first thing the page is about.
 *
 * §16-33 gives the page its words: modules to filter by, labels to read, and the
 * translation of the handful of names that were recorded before the vocabulary
 * existed. The export is gated separately (`audit.export`) and always records a
 * `document.export` row; the printable report is the reusable renderer's
 * `audit_report` type, so the paper is produced, filed and checksummed like
 * every other document.
 */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();

        $query = AuditEvent::query()
            ->where('company_id', $actor->company_id)
            ->orderByDesc('id');

        $actorBranches = $actor->accessibleBranchIds();

        if ($actorBranches !== null) {
            // Branch rows of their branches, plus their *own* company-wide rows —
            // and nothing else. Passing a null inside whereIn would have turned
            // into `or branch_id is null`, which admits every company-wide row of
            // every actor: the branch limit would have quietly stopped meaning
            // anything on exactly the page that exists to be trustworthy.
            $query->where(function ($inner) use ($actorBranches, $actor) {
                $inner->whereIn('branch_id', $actorBranches)
                    ->orWhere(function ($own) use ($actor) {
                        $own->whereNull('branch_id')->where('actor_id', $actor->id);
                    });
            });
        }

        if ($module = trim((string) $request->query('module'))) {
            $patterns = AuditVocabulary::patternsForModule($module);

            $query->where(function ($inner) use ($patterns) {
                foreach ($patterns as $pattern) {
                    str_ends_with($pattern, '%')
                        ? $inner->orWhere('action', 'like', $pattern)
                        : $inner->orWhere('action', 'like', $pattern.'%');
                }
            });
        }

        if ($action = trim((string) $request->query('action'))) {
            // A filtered action also finds the rows recorded under its older
            // name: asking for `sales.cod_reconciled` must not hide `reconcile`.
            $query->where(function ($inner) use ($action) {
                foreach (AuditVocabulary::matchNames($action) as $name) {
                    str_ends_with($name, '%')
                        ? $inner->orWhere('action', 'like', $name)
                        : $inner->orWhere('action', '=', $name);
                }
            });
        }

        if ($request->boolean('sensitive')) {
            $query->where(function ($inner) {
                foreach (AuditVocabulary::SENSITIVE as $prefix) {
                    $inner->orWhere('action', 'like', $prefix.'%');
                }
            });
        }

        if ($entityType = trim((string) $request->query('entity_type'))) {
            $query->where('entity_type', $entityType);
        }

        if ($result = (string) $request->query('result')) {
            $query->where('result', $result);
        }

        if ($actorId = (int) $request->query('actor_id')) {
            $query->where('actor_id', $actorId);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', \Illuminate\Support\Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', \Illuminate\Support\Carbon::parse($to)->endOfDay());
        }

        if ($search = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q->where('reason', 'like', "%{$search}%")
                ->orWhere('action', 'like', "%{$search}%"));
        }

        $total = AuditEvent::query()->where('company_id', $actor->company_id)->count();
        $verifier = app(AuditChainVerifier::class);
        $affordable = $total < 5000; // a full verify on a huge log is a CLI job

        $archives = AuditArchive::query()
            ->where('company_id', $actor->company_id)
            ->orderByDesc('period')
            ->get();

        return view('audit.index', [
            'events' => $query->paginate(25)->withQueryString(),
            'filters' => $request->query(),
            'modules' => AuditVocabulary::moduleOptions(),
            'commonActions' => config('erp.audit.actions', []),
            'vocabularySize' => count(AuditVocabulary::MODULES),
            'chain' => $affordable ? $verifier->verify((int) $actor->company_id) : null,
            'archives' => $archives,
            'archiveVerdicts' => $affordable ? $verifier->verifyArchives((int) $actor->company_id) : null,
            'lastVerification' => AuditEvent::query()
                ->where('company_id', $actor->company_id)
                ->where('action', 'security.chain_verified')
                ->orderByDesc('id')
                ->first(),
            'eventTotal' => $total,
        ]);
    }

    public function show(Request $request, AuditEvent $event): View
    {
        abort_unless($event->company_id === $request->user()->company_id, 404);

        return view('audit.show', [
            'event' => $event,
            'previous' => AuditEvent::query()
                ->where('company_id', $event->company_id)
                ->where('seq', '<', $event->seq)
                ->orderByDesc('seq')
                ->first(),
            'next' => AuditEvent::query()
                ->where('company_id', $event->company_id)
                ->where('seq', '>', $event->seq)
                ->orderBy('seq')
                ->first(),
        ]);
    }

    /**
     * §16-34 — “verify now”, for the person looking at the page.
     *
     * The result is recorded in the trail itself: a verification that leaves no
     * trace cannot be told apart from a page that was never checked. Nothing is
     * repaired here — a broken chain is reported, not rewritten, because a
     * tamper-evident log that can repair itself is not evidence of anything.
     */
    public function verify(Request $request)
    {
        $actor = $request->user();
        $companyId = (int) $actor->company_id;

        $verifier = app(AuditChainVerifier::class);
        $chain = $verifier->verify($companyId);
        $archives = $verifier->verifyArchives($companyId);

        app(\App\Domain\Audit\Services\AuditRecorder::class)->record([
            'company_id' => $companyId,
            'action' => 'security.chain_verified',
            'entity_type' => 'audit_chain',
            'entity_id' => null,
            'actor_id' => $actor->id,
            'result' => $chain['ok'] && $archives['ok'] ? 'success' : 'failure',
            'after' => [
                'chain_ok' => $chain['ok'],
                'chain_checked' => $chain['checked'],
                'chain_reason' => $chain['reason'],
                'archives' => $archives['archives'],
                'archives_ok' => $archives['ok'],
            ],
        ]);

        if (! $chain['ok']) {
            return back()->with('status', sprintf(
                'Chain BROKEN after %d events: %s at seq %s. Nothing was changed — investigate the row.',
                $chain['checked'],
                $chain['reason'],
                $chain['broken_at'] ?? '?',
            ));
        }

        $seals = $archives['archives'] === 0
            ? 'no sealed periods yet'
            : ($archives['ok']
                ? $archives['archives'].' sealed period(s) re-verified'
                : 'a sealed period no longer matches its seal');

        return back()->with('status', sprintf(
            'Chain verified — %d events, %s.',
            $chain['checked'],
            $seals,
        ));
    }

    public function export(ExportAuditRequest $request)
    {
        $actor = $request->user();

        $query = AuditEvent::query()
            ->where('company_id', $actor->company_id)
            ->orderBy('seq');

        if ($from = $request->validated('from')) {
            $query->where('created_at', '>=', \Illuminate\Support\Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->validated('to')) {
            $query->where('created_at', '<=', \Illuminate\Support\Carbon::parse($to)->endOfDay());
        }

        $filename = 'audit-'.now()->format('Ymd-His').'.csv';

        app(\App\Domain\Audit\Services\AuditRecorder::class)->record([
            'action' => 'document.export',
            'entity_type' => 'audit_log',
            'entity_id' => null,
            'actor_id' => $actor->id,
            'after' => ['file' => $filename, 'filters' => $request->validated()],
            'ip' => (string) $request->ip(),
        ]);

        return Response::streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['seq', 'created_at', 'action', 'entity_type', 'entity_id', 'actor_type', 'actor_id', 'branch_id', 'result', 'reason', 'ip', 'correlation_id', 'prev_hash', 'row_hash']);

            foreach ($query->cursor() as $event) {
                fputcsv($out, [
                    $event->seq,
                    optional($event->created_at)->toIso8601String(),
                    $event->action,
                    $event->entity_type,
                    $event->entity_id,
                    $event->actor_type,
                    $event->actor_id,
                    $event->branch_id,
                    $event->result,
                    $event->reason,
                    $event->ip,
                    $event->correlation_id,
                    $event->prev_hash,
                    $event->row_hash,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
