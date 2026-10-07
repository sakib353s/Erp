<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\Services\AuditChainVerifier;
use App\Http\Requests\ExportAuditRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;

/**
 * Tamper-evident audit viewer (Rule 16): filterable server-side,
 * permission-gated, branch-scoped for branch-limited users. The export
 * is permission-gated separately (audit.export) and always records a
 * document.export audit row.
 */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();

        $query = AuditEvent::query()->orderByDesc('id');

        $actorBranches = $actor->accessibleBranchIds();
        if ($actorBranches !== null) {
            $query->whereIn('branch_id', array_merge($actorBranches, [0, null]))
                ->orWhere(function ($q) use ($actor) {
                    $q->whereNull('branch_id')->where('actor_id', $actor->id);
                });
        }

        if ($action = trim((string) $request->query('action'))) {
            $query->where('action', $action);
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

        return view('audit.index', [
            'events' => $query->paginate(25)->withQueryString(),
            'filters' => $request->query(),
            'knownActions' => config('erp.audit.actions', []),
            'chainStatus' => AuditEvent::query()->where('company_id', $actor->company_id)->count() < 5000
                ? app(AuditChainVerifier::class)->verify((int) $actor->company_id)
                : null, // full verify is a CLI job on huge logs (erp:chain-verify)
        ]);
    }

    public function show(Request $request, AuditEvent $event): View
    {
        abort_unless($event->company_id === $request->user()->company_id, 404);

        return view('audit.show', ['event' => $event]);
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
