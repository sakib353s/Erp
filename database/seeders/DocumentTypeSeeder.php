<?php

namespace Database\Seeders;

use App\Domain\Documents\DocumentType;
use App\Domain\Documents\Support\DocumentTypeRegistry;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\NumberingRule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Structural document-type registry (Rules 8/9): the commercial sales
 * document prints as "INVOICE"; Mushak 9.1 / 11 remain SEPARATE
 * statutory types. Renderers read titles from these rows — never from
 * hard-coded strings. Also backfills the default numbering rule for
 * every company × numbered type (idempotent — first boot normally
 * materialises these, existing companies need the same scaffolding
 * when a new type is added to the registry).
 */
class DocumentTypeSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach (DocumentTypeRegistry::map() as $row) {
            DocumentType::updateOrCreate(['code' => $row['code']], $row);
        }

        foreach (Company::query()->pluck('id') as $companyId) {
            foreach (DocumentTypeRegistry::NUMBERING_PREFIXES as $code => $prefix) {
                $type = DocumentType::query()->where('code', $code)->first();

                if ($type === null) {
                    continue;
                }

                NumberingRule::firstOrCreate(
                    [
                        'company_id' => $companyId,
                        'branch_id' => 0,
                        'document_type_id' => $type->id,
                    ],
                    [
                        'prefix' => $prefix,
                        'pattern' => config('erp.numbering.default_pattern'),
                        'padding' => (int) config('erp.numbering.default_padding'),
                        'reset_period' => config('erp.numbering.default_reset_period'),
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
