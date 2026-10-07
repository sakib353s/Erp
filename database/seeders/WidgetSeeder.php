<?php

namespace Database\Seeders;

use App\Domain\Foundation\Module;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Widget;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Imports the verbatim widget catalog (§46/§48) as EXACTLY 25 widget
 * containers (decision D22): the 26 listed labels collapse into 25 via
 * the §48-A composite "Branch Comparison & Recent Activity".
 *
 * The seeder REFUSES to run if the resulting count deviates — the
 * dashboard contract is asserted, not assumed.
 */
class WidgetSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $file = (string) config('erp.navigation.widgets_file');

        if (! is_readable($file)) {
            throw new RuntimeException("Widget catalog not readable: {$file}");
        }

        $labels = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/[├└]──\s*(.+)$/', $line, $m)) {
                $labels[] = trim($m[1]);
            }
        }

        // §48-A: the last two labels form ONE composite container.
        $a = array_search('Branch Comparison', $labels, true);
        $b = array_search('Recent Activity', $labels, true);

        if ($a === false || $b === false) {
            throw new RuntimeException('§48-A composite labels (Branch Comparison / Recent Activity) missing from catalog.');
        }

        array_splice($labels, min($a, $b), abs($b - $a) + 1, ['Branch Comparison & Recent Activity']);

        $expected = (int) config('erp.dashboard.expected_widget_containers');

        if (count($labels) !== $expected) {
            throw new RuntimeException(
                'Widget container count is '.count($labels)." but the spec mandates {$expected} — refusing to seed a non-compliant dashboard.",
            );
        }

        $dashboardModule = Module::query()->where('code', 'dashboard')->first();
        $permission = Permission::query()->where('key', 'dashboard.view')->first();

        foreach ($labels as $index => $label) {
            $code = $this->codeFor($label);

            Widget::updateOrCreate(
                ['code' => $code],
                [
                    'module_id' => $dashboardModule?->id,
                    'label' => $label,
                    'label_key' => 'widget.'.$code,
                    'container' => 'dashboard',
                    'permission_id' => $permission?->id,
                    'feature_key' => 'dashboard',
                    'sort' => ($index + 1) * 10,
                    'is_active' => true,
                ],
            );
        }
    }

    protected function codeFor(string $label): string
    {
        $lower = strtolower($label);

        $specials = [
            'receivable aging' => 'receivable_aging',
            'payable aging' => 'payable_aging',
            'sales chart' => 'sales_chart',
            'branch comparison & recent activity' => 'branch_activity',
        ];

        foreach ($specials as $needle => $code) {
            if (str_starts_with($lower, $needle)) {
                return $code;
            }
        }

        $slug = str_replace(["'", '’'], '', $label);
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', $slug);

        return trim((string) $slug, '_');
    }
}
