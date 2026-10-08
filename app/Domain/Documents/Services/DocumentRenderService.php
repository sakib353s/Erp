<?php

namespace App\Domain\Documents\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PrintHistory;
use App\Domain\Documents\Sources\DocumentSourceRegistry;
use App\Domain\Documents\Support\PrintLabels;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Settings\Services\LocalizationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * §16-23/§16-24/§16-25 — one door that turns any supported document into paper.
 *
 * The steps, in this order, because each one depends on the one before it:
 *
 *  1. **the entity**, loaded through its own source definition and refused
 *     unless it belongs to the acting company;
 *  2. **the title**, from the type rule (§16-24) — never from the template;
 *  3. **the paper**, rendered by the shared shell so twelve document types
 *     cannot drift into twelve layouts;
 *  4. **the filed copy**, stored with the checksum of the exact bytes rendered
 *     and the next version number for that entity;
 *  5. **the history row and the audit event**, written with the title, the paper
 *     size, the copy locale, the watermark and the checksum — so the sheet in
 *     somebody's hand can be matched against the record later (§16-25).
 *
 * Renderers that read existing ledgers (a ledger, a statement, the audit trail)
 * file their output too: "we printed it" is a fact about the application, not
 * about the reader.
 */
class DocumentRenderService
{
    public function __construct(
        protected DocumentSourceRegistry $sources,
        protected DocumentTitleService $titles,
        protected DocumentRenderer $renderer,
        protected LocalizationService $localization,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function definition(string $type): array
    {
        $definition = $this->sources->definitions()[$type] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("There is no printable document of type [{$type}] in this build.");
        }

        return $definition;
    }

    public function permission(string $type): string
    {
        return (string) $this->definition($type)['permission'];
    }

    /**
     * Everything the shell needs for one document, without filing anything.
     *
     * @param  array<string, mixed>  $query
     * @param  array{page_format?:string,locale?:string,watermark?:?string}  $options
     * @return array<string, mixed>
     */
    public function paper(string $type, int $id, array $query, User $actor, array $options = []): array
    {
        $definition = $this->definition($type);
        $companyId = (int) ($this->context->companyId() ?? $actor->company_id);

        $entity = ($definition['load'])($id, $query);

        if ($entity === null && ! ($definition['standalone'] ?? false)) {
            throw (new ModelNotFoundException())->setModel('document', [$id]);
        }

        if ($entity !== null && $entity->getAttribute('company_id') !== null
            && (int) $entity->getAttribute('company_id') !== $companyId) {
            throw (new ModelNotFoundException())->setModel($entity::class, [$id]);
        }

        $paper = ($definition['build'])($entity, $query);

        $hasTax = collect($paper['tax_lines'] ?? [])->contains(fn (array $line) => abs((float) ($line['value'] ?? 0)) > 0.00005);
        $title = $this->titles->resolve((string) $definition['title_code'], $hasTax);

        $locale = in_array($options['locale'] ?? 'en', array_keys(PrintLabels::LOCALES), true) ? $options['locale'] : 'en';
        $pageFormat = in_array($options['page_format'] ?? 'a4', $definition['papers'], true) ? $options['page_format'] : 'a4';

        return [
            'type' => $type,
            'label' => (string) $definition['label'],
            'definition' => $definition,
            'entity' => $entity,
            'paper' => $paper,
            'title' => $title,
            'page_format' => $pageFormat,
            'locale' => $locale,
            'watermark' => $options['watermark'] ?? null,
            'copies' => (int) ($options['copies'] ?? 1),
            'company' => Company::query()->find($companyId),
            'brand' => $this->brandImages($companyId),
            'labels' => PrintLabels::for($locale),
            'generated_at' => now(),
            'printed_by' => $actor,
        ];
    }

    /** The rendered HTML for one document, ready to be sent to a browser or a file. */
    public function html(string $type, int $id, array $query, User $actor, array $options = []): array
    {
        $data = $this->paper($type, $id, $query, $actor, $options);
        $data['html'] = view('documents.print.shell', $data + ['localization' => $this->localization])->render();

        return $data;
    }

    /**
     * Render, file and log — the whole of §16-23 in one call.
     *
     * @return array<string, mixed> the paper data plus `document` and `history`
     */
    public function render(string $type, int $id, array $query, User $actor, Request $request, array $options = []): array
    {
        $data = $this->html($type, $id, $query, $actor, $options);
        $paper = $data['paper'];
        $entity = $data['entity'];
        $isDownload = ($options['action'] ?? 'print') === 'download';

        $document = $this->renderer->storeGenerated(
            $data['html'],
            companyId: (int) ($this->context->companyId() ?? $actor->company_id),
            directory: $isDownload ? 'downloads' : 'prints',
            basename: str_replace(['/', '\\', ' '], '-', (string) ($paper['reference'] ?? $type)).'-'.$type,
            owner: $entity,
            typeCode: (string) $data['definition']['title_code'],
            user: $actor,
            branchId: $entity?->getAttribute('branch_id') !== null ? (int) $entity->getAttribute('branch_id') : null,
        );

        // Versioning: the third print of the same invoice is version 3 of it,
        // not another document with no place in the sequence.
        if ($entity !== null) {
            $previous = Document::query()
                ->where('company_id', $document->company_id)
                ->where('owner_type', $entity::class)
                ->where('owner_id', $entity->getKey())
                ->where('document_type_id', $document->document_type_id)
                ->where('id', '!=', $document->id)
                ->max('version');

            $document->forceFill(['version' => ((int) $previous) + 1])->save();
        }

        $history = $this->renderer->recordPrint(
            $entity ?? new Document(['company_id' => $document->company_id]),
            (string) $data['definition']['title_code'],
            $request,
            document: $document,
            title: $data['title']['title'],
            pageFormat: $data['page_format'],
            locale: $data['locale'],
            watermark: $data['watermark'],
            checksum: (string) $document->checksum,
            copies: $data['copies'],
            printableType: $entity === null ? 'report:'.$type : null,
            printableId: $entity === null ? (int) $document->company_id : null,
        );

        $this->audit->record([
            'action' => $isDownload ? 'documents.downloaded' : 'documents.printed',
            'entity_type' => $entity?->getTable() ?? 'report',
            'entity_id' => $entity?->getKey() ?? $document->company_id,
            'actor_id' => $actor->id,
            'after' => [
                'type' => $type,
                'title' => $data['title']['title'],
                'page_format' => $data['page_format'],
                'locale' => $data['locale'],
                'watermark' => $data['watermark'],
                'copies' => $data['copies'],
                'document_id' => $document->id,
                'version' => $document->version,
                'checksum' => $document->checksum,
            ],
        ]);

        return $data + ['document' => $document, 'history' => $history];
    }

    /**
     * The print/download history of one document, newest first.
     *
     * A statement reads a party, and a party may be a customer or a supplier, so
     * the history key is the class of the entity that was actually rendered
     * rather than a class written down in the definition — otherwise a supplier
     * statement's history would be filed under customers. A report has no entity
     * at all, and is filed against its code and the company.
     *
     * @param  array<string, mixed>  $query
     * @return \Illuminate\Support\Collection<int, PrintHistory>
     */
    public function history(string $type, int $id, array $query = [], int $limit = 50): \Illuminate\Support\Collection
    {
        $definition = $this->definition($type);

        if ($definition['standalone'] ?? false) {
            return $this->historyQuery('report:'.$type, (int) ($this->context->companyId() ?? 0), $limit);
        }

        $entity = ($definition['load'])($id, $query);

        // The document itself is gone (deleted, or another company's): there is
        // no history to show, and an empty page is the truthful answer.
        if ($entity === null) {
            return collect();
        }

        return $this->historyQuery($entity::class, (int) $entity->getKey(), $limit);
    }

    public function historyCount(string $type, int $id, array $query = []): int
    {
        return $this->history($type, $id, $query, 500)->count();
    }

    /** @return \Illuminate\Support\Collection<int, PrintHistory> */
    protected function historyQuery(string $printableType, int $printableId, int $limit): \Illuminate\Support\Collection
    {
        return PrintHistory::query()
            ->with(['user', 'documentType'])
            ->where('printable_type', $printableType)
            ->where('printable_id', $printableId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The logo, seal and signature the company uploaded (§15 Company Logo &
     * Seal are documents with a purpose, not columns), inlined as data URIs so a
     * printed file stands on its own — the paper must not depend on the
     * application being reachable when somebody opens it.
     *
     * @return array<string, ?string>
     */
    protected function brandImages(int $companyId): array
    {
        $images = ['logo' => null, 'seal' => null, 'signature' => null];

        $documents = Document::query()
            ->where('company_id', $companyId)
            ->whereIn('purpose', array_keys($images))
            ->orderByDesc('id')
            ->get()
            ->unique('purpose');

        foreach ($documents as $document) {
            if (in_array($document->extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)
                && $document->mime_type !== null
                && Storage::disk($document->disk)->exists($document->path)) {
                $images[$document->purpose] = 'data:'.$document->mime_type.';base64,'
                    .base64_encode((string) Storage::disk($document->disk)->get($document->path));
            }
        }

        return $images;
    }

    /** Types a desk may offer, with the reason for any it may not print yet. */
    public function catalogue(): array
    {
        $rows = [];

        foreach (DocumentSourceRegistry::CODES as $code) {
            $definition = $this->definition($code);

            $rows[] = [
                'code' => $code,
                'label' => (string) $definition['label'],
                'permission' => (string) $definition['permission'],
                'papers' => $definition['papers'],
                'title' => $this->titles->resolve((string) $definition['title_code'])['title'],
                'where' => DocumentSourceRegistry::HINTS[$code] ?? null,
                'standalone' => (bool) ($definition['standalone'] ?? false),
                'period' => (bool) ($definition['period'] ?? false),
                'available' => true,
                'reason' => null,
            ];
        }

        foreach (DocumentSourceRegistry::UNAVAILABLE as $code => $reason) {
            $rows[] = [
                'code' => $code,
                'label' => ucfirst(str_replace('_', ' ', $code)),
                'permission' => null,
                'papers' => [],
                'title' => $this->titles->titleFor($code),
                'where' => null,
                'standalone' => false,
                'period' => false,
                'available' => false,
                'reason' => $reason,
            ];
        }

        return $rows;
    }
}
