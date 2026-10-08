<?php

namespace App\Http\Controllers;

use App\Domain\Documents\PrintHistory;
use App\Domain\Documents\Services\DocumentRenderService;
use App\Domain\Documents\Sources\DocumentSourceRegistry;
use App\Domain\Documents\Support\PrintLabels;
use App\Domain\Foundation\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * §16-23/§16-24/§16-25 — the doors onto the reusable renderer.
 *
 * Three of them, because there are three different questions:
 *
 *  · `/app/documents/print` — what can this company print at all, and what has
 *    it printed lately (the desk);
 *  · `/app/documents/print/{type}/{id}` — render *this* document. One door for
 *    twelve types, so a new type cannot arrive with its own permission
 *    mistakes, its own watermark behaviour or its own idea of a filename;
 *  · `/app/documents/print/{type}/{id}/history` (and the company-wide log at
 *    `/app/documents/print-log`) — who printed which copy of it, on what paper,
 *    with what checksum (§16-25).
 *
 * Printing is a read of a document the person is already allowed to read, so the
 * permission is the document's own: `sales.invoices.print` where a print key
 * exists, the entity's view key where it does not. Reading the history has its
 * own key, because "who printed this" is an accountability question and is not
 * the same job as raising the document.
 */
class DocumentRenderController extends Controller
{
    public function __construct(
        protected DocumentRenderService $renderer,
        protected DocumentSourceRegistry $sources,
        protected TenantContext $context,
    ) {}

    /** The desk: every printable type, and the last things that came off a printer. */
    public function index(Request $request): View
    {
        $actor = $request->user();

        $types = collect($this->renderer->catalogue())
            ->map(function (array $row) use ($actor): array {
                $row['may'] = $row['permission'] !== null && $actor->can($row['permission']);

                return $row;
            });

        return view('documents.print.index', [
            'types' => $types,
            'mayReadHistory' => $actor->can('documents.view_history'),
            'recent' => $this->recent($request, 15),
            'windows' => ['a4' => 'A4', 'thermal' => '80 mm thermal'],
            'locales' => PrintLabels::LOCALES,
        ]);
    }

    /** Render one document — as a page to print, or as a file to save. */
    public function show(Request $request, string $type, int $id): Response
    {
        if (! $this->sources->has($type)) {
            abort(404, "There is no printable document of type [{$type}] in this build.");
        }

        $unavailable = $this->sources->unavailableReason($type);

        if ($unavailable !== null) {
            abort(422, $unavailable);
        }

        $definition = $this->renderer->definition($type);

        $input = $this->validateOptions($request, $definition['papers']);

        abort_unless(
            $request->user()->can((string) $definition['permission']),
            403,
            'Printing this document needs the '.$definition['permission'].' permission.',
        );

        $action = ($input['action'] ?? 'print') === 'download' ? 'download' : 'print';

        $data = $this->renderer->render($type, $id, $input, $request->user(), $request, [
            'page_format' => $input['page_format'] ?? 'a4',
            'locale' => $input['locale'] ?? 'en',
            'watermark' => $input['watermark'] ?? null,
            'copies' => (int) ($input['copies'] ?? 1),
            'action' => $action,
        ]);

        $response = response($data['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        if ($action !== 'download') {
            return $response;
        }

        return $response->header(
            'Content-Disposition',
            'attachment; filename="'.$this->filename($data).'"',
        );
    }

    /** §16-25 — the history of one document. */
    public function history(Request $request, string $type, int $id): View
    {
        if (! $this->sources->has($type)) {
            abort(404, "There is no printable document of type [{$type}] in this build.");
        }

        abort_unless($request->user()->can('documents.view_history'), 403);

        $query = $request->only(['party', 'from', 'to']);

        return view('documents.history', [
            'type' => $type,
            'label' => $this->labelFor($type),
            'entityId' => $id,
            'rows' => $this->renderer->history($type, $id, $query, 100),
            'total' => $this->renderer->historyCount($type, $id, $query),
            'subject' => $this->subject($type, $id),
            'companyLog' => false,
        ]);
    }

    /** The company's print and download log, newest first. */
    public function log(Request $request): View
    {
        abort_unless($request->user()->can('documents.view_history'), 403);

        return view('documents.history', [
            'type' => null,
            'label' => 'Everything printed and downloaded',
            'entityId' => null,
            'rows' => $this->recent($request, 200),
            'total' => $this->logQuery($request)->count(),
            'subject' => null,
            'companyLog' => true,
        ]);
    }

    /** @param array<int, string> $papers */
    protected function validateOptions(Request $request, array $papers): array
    {
        return Validator::make($request->query(), [
            'page_format' => ['nullable', Rule::in($papers)],
            'locale' => ['nullable', Rule::in(array_keys(PrintLabels::LOCALES))],
            'watermark' => ['nullable', 'string', 'max:24', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-]*$/'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:10'],
            'action' => ['nullable', Rule::in(['print', 'download'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'party' => ['nullable', Rule::in(['customer', 'supplier'])],
            'filter' => ['nullable', 'string', 'max:60'],
        ])->validate();
    }

    /** The title of the paper is what the file should be called. */
    protected function filename(array $data): string
    {
        $parts = array_filter([
            (string) $data['title']['title'],
            (string) ($data['paper']['reference'] ?? ''),
            now()->format('Ymd-Hi'),
        ]);

        return str_replace(['/', '\\', '"', ' '], '-', implode('-', $parts)).'.html';
    }

    protected function labelFor(string $type): string
    {
        foreach ($this->renderer->catalogue() as $row) {
            if ($row['code'] === $type) {
                return (string) $row['label'];
            }
        }

        return ucfirst(str_replace('_', ' ', $type));
    }

    /** Who or what the history belongs to, in words the reader recognises. */
    protected function subject(string $type, int $id): ?string
    {
        if (in_array($type, ['audit_report'], true)) {
            return 'The company’s audit trail';
        }

        try {
            $data = $this->renderer->paper($type, $id, [], request()->user());
        } catch (\Throwable) {
            return null;
        }

        return (string) ($data['paper']['party']['name'] ?? $data['paper']['reference'] ?? null);
    }

    protected function recent(Request $request, int $limit)
    {
        return $this->logQuery($request)->limit($limit)->get();
    }

    protected function logQuery(Request $request)
    {
        $companyId = (int) ($this->context->companyId() ?? $request->user()->company_id);

        return PrintHistory::query()
            ->with(['user', 'documentType', 'document'])
            ->where('company_id', $companyId)
            ->when($request->query('type') !== null, fn ($query) => $query->whereHas(
                'documentType',
                fn ($inner) => $inner->where('code', $request->query('type')),
            ))
            ->when($request->query('user') !== null, fn ($query) => $query->where('user_id', (int) $request->query('user')))
            ->when($request->query('from') !== null, fn ($query) => $query->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->query('to') !== null, fn ($query) => $query->whereDate('created_at', '<=', $request->query('to')))
            ->orderByDesc('id');
    }

    /** Every printable type, for the related-pages strip on the desk. */
    public function codes(): array
    {
        return DocumentSourceRegistry::CODES;
    }
}
