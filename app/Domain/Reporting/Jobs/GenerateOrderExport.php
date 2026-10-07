<?php

namespace App\Domain\Reporting\Jobs;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Reporting\OrderExport;
use App\Domain\Reporting\OrderExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Builds the CSV for one order export and files it as a document.
 * Scope is re-read from the export row (company + requester branch
 * visibility fixed at creation) — the job never widens it. Failures
 * land on the export row as `failed`, never as a silent empty file.
 */
class GenerateOrderExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $exportId) {}

    public function handle(OrderExporter $exporter, DocumentRenderer $renderer, AuditRecorder $audit): void
    {
        $export = OrderExport::query()->find($this->exportId);

        if ($export === null) {
            return;
        }

        $export->forceFill(['status' => OrderExport::STATUS_PROCESSING])->save();

        try {
            [$csv, $rowCount] = $exporter->buildCsv($export);

            $document = $renderer->storeGenerated(
                $csv,
                companyId: (int) $export->company_id,
                directory: 'exports',
                basename: 'orders-'.now()->format('Ymd-His'),
                owner: $export,
                typeCode: null,
                user: $export->user,
                mime: 'text/csv',
                extension: 'csv',
            );

            $export->forceFill([
                'status' => OrderExport::STATUS_COMPLETED,
                'row_count' => $rowCount,
                'document_id' => $document->id,
                'error' => null,
                'completed_at' => now(),
            ])->save();

            $audit->record([
                'action' => 'sales.orders_export',
                'entity_type' => 'order_export',
                'entity_id' => $export->id,
                'actor_id' => $export->user_id,
                'after' => [
                    'export_id' => $export->id,
                    'row_count' => $rowCount,
                    'document_id' => $document->id,
                    'filename' => $document->original_name,
                    'filters' => $export->filters ?? [],
                ],
                'reason' => null,
            ]);
        } catch (\Throwable $e) {
            $export->forceFill([
                'status' => OrderExport::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 490),
            ])->save();
        }
    }
}
