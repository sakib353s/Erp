<?php

namespace Database\Seeders;

use App\Domain\Foundation\Portal;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Structural portal registry (correction E): ERP core + optional
 * technician and supplier portals. No business data.
 */
class PortalSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $portals = [
            ['code' => 'erp', 'name' => 'ERP', 'description' => 'Core enterprise resource planning application.'],
            ['code' => 'technician', 'name' => 'Technician Portal', 'description' => 'Field service technicians: assignments, warranty service, checklists.'],
            ['code' => 'supplier', 'name' => 'Supplier Portal', 'description' => 'External suppliers: isolated portal with only supplier-granted data (correction E).'],
        ];

        foreach ($portals as $portal) {
            Portal::updateOrCreate(['code' => $portal['code']], $portal + ['is_active' => true]);
        }
    }
}
