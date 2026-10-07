<?php

namespace App\Console\Commands;

use App\Domain\Foundation\Services\CatalogImporter;
use Illuminate\Console\Command;

/**
 * Re-imports/updates the navigation registry from the verbatim §47
 * catalog and flips status='active' for entries whose routes now exist
 * (later phases run this to reveal their newly implemented pages).
 */
class MenuSyncCommand extends Command
{
    protected $signature = 'menu:sync {--dry-run : Parse and report without writing}';

    protected $description = 'Sync menu_items/modules/permissions from database/catalog/menu_tree.txt';

    public function handle(CatalogImporter $importer): int
    {
        $dry = (bool) $this->option('dry-run');
        $stats = $importer->sync($dry);

        $this->components->twoColumnDetail('Modules', (string) $stats['modules']);
        $this->components->twoColumnDetail('Menu entries', (string) $stats['items']);
        $this->components->twoColumnDetail('Active (route exists)', (string) $stats['active']);
        $this->components->twoColumnDetail('Planned (never rendered)', (string) $stats['planned']);
        $this->components->twoColumnDetail('Permission rows touched', (string) $stats['permissions']);

        if ($dry) {
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }
}
