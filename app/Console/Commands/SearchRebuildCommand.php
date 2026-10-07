<?php

namespace App\Console\Commands;

use App\Search\SearchIndexRebuilder;
use Illuminate\Console\Command;

/**
 * Full search-index rebuild from source tables (row 15-28).
 * Safe self-heal op — no business data is invented.
 */
class SearchRebuildCommand extends Command
{
    protected $signature = 'erp:search:rebuild {--company= : Limit to a single company id}';

    protected $description = 'Rebuild the global search index from source tables';

    public function handle(SearchIndexRebuilder $rebuilder): int
    {
        $company = $this->option('company');
        $counts = $rebuilder->rebuild($company !== null ? (int) $company : null);

        if ($counts === []) {
            $this->warn('No company found — nothing to rebuild.');

            return self::SUCCESS;
        }

        foreach ($counts as $type => $n) {
            $this->components->twoColumnDetail(ucfirst($type).' rows', (string) $n);
        }

        $this->components->twoColumnDetail('Total', (string) array_sum($counts));

        return self::SUCCESS;
    }
}
