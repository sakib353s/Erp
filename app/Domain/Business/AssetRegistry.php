<?php

namespace App\Domain\Business;

use InvalidArgumentException;

/**
 * §12-14 — what each kind of asset is, in one place.
 *
 * The menu calls them four things (asset register, vehicle management, vehicle
 * trip log, equipment) and the database stores one: a row in `business_assets`
 * with a `category`. This registry is the translation — for every category it
 * says what to call it, whether it has a vehicle's extra fields, which ledger
 * account its cost normally sits in, and how long it usually lasts.
 *
 * The defaults are *defaults*, not rules: a company may depreciate a laptop over
 * three years and a delivery van over eight, and it may leave its furniture
 * undepreciated altogether if that is how its accountant keeps the books. What
 * the registry stops is the silent case — an asset with a cost and no decision
 * about how that cost turns into expense.
 */
class AssetRegistry
{
    public const CATEGORY_VEHICLE = 'vehicle';

    public const CATEGORY_EQUIPMENT = 'equipment';

    public const CATEGORY_FURNITURE = 'furniture';

    public const CATEGORY_COMPUTER = 'computer';

    public const CATEGORY_MACHINERY = 'machinery';

    public const CATEGORY_OTHER = 'other';

    /** The categories that count as "equipment" on the equipment screen. */
    public const EQUIPMENT_CATEGORIES = [self::CATEGORY_EQUIPMENT, self::CATEGORY_MACHINERY, self::CATEGORY_COMPUTER];

    /**
     * `vehicle`    — it has plates, a driver and statutory dates.
     * `gl`         — where its acquisition cost normally sits (informational: the
     *                register does not post the purchase, the books do).
     * `life`       — the useful life a straight-line schedule defaults to, months.
     * `wears_out`  — worth asking the depreciation question at all.
     */
    public const CATEGORIES = [
        self::CATEGORY_VEHICLE => [
            'label' => 'Vehicle',
            'plural' => 'Vehicles',
            'icon' => 'bi-truck',
            'vehicle' => true,
            'gl' => '1510',
            'life' => 96,
            'wears_out' => true,
            'hint' => 'Plates, a driver, and papers that run out: fitness, insurance and tax token live in the certificate register and appear on the compliance calendar with everything else.',
        ],
        self::CATEGORY_EQUIPMENT => [
            'label' => 'Equipment',
            'plural' => 'Equipment',
            'icon' => 'bi-gear',
            'vehicle' => false,
            'gl' => '1520',
            'life' => 60,
            'wears_out' => true,
            'hint' => 'Generators, freezers, shelving, tools — what the work is actually done with.',
        ],
        self::CATEGORY_MACHINERY => [
            'label' => 'Machinery',
            'plural' => 'Machinery',
            'icon' => 'bi-gear-wide-connected',
            'vehicle' => false,
            'gl' => '1520',
            'life' => 120,
            'wears_out' => true,
            'hint' => 'Production and packing machines: the longest lives and the biggest numbers.',
        ],
        self::CATEGORY_COMPUTER => [
            'label' => 'Computers & IT',
            'plural' => 'Computers & IT',
            'icon' => 'bi-pc-display',
            'vehicle' => false,
            'gl' => '1540',
            'life' => 36,
            'wears_out' => true,
            'hint' => 'Laptops, printers, the POS terminals and the network — short lives, quick obsolescence.',
        ],
        self::CATEGORY_FURNITURE => [
            'label' => 'Furniture & fittings',
            'plural' => 'Furniture & fittings',
            'icon' => 'bi-lamp',
            'vehicle' => false,
            'gl' => '1530',
            'life' => 120,
            'wears_out' => false,
            'hint' => 'Desks, racking, counters and the fit-out of each branch.',
        ],
        self::CATEGORY_OTHER => [
            'label' => 'Other asset',
            'plural' => 'Other assets',
            'icon' => 'bi-box-seam',
            'vehicle' => false,
            'gl' => '1580',
            'life' => 60,
            'wears_out' => false,
            'hint' => 'Everything the register still has to hold: deposits, signage, a leasehold improvement.',
        ],
    ];

    /** The shelves the menu names, as the pages that show them. */
    public const SHELVES = [
        'register' => ['label' => 'Asset register', 'icon' => 'bi-hdd-stack', 'categories' => null],
        'vehicles' => ['label' => 'Vehicle management', 'icon' => 'bi-truck', 'categories' => [self::CATEGORY_VEHICLE]],
        'equipment' => ['label' => 'Equipment', 'icon' => 'bi-gear', 'categories' => self::EQUIPMENT_CATEGORIES],
    ];

    public const CONDITIONS = [
        'new' => 'New',
        'good' => 'Good',
        'fair' => 'Fair',
        'poor' => 'Poor',
    ];

    public const STATUSES = [
        'in_use' => 'In use',
        'stored' => 'In store',
        'under_repair' => 'Under repair',
        'disposed' => 'Disposed',
    ];

    public const METHODS = [
        'none' => 'Not depreciated',
        'straight_line' => 'Straight line',
    ];

    /** The two ledger accounts a depreciation posting touches. */
    public const DEPRECIATION_EXPENSE_CODE = '5270';

    public const ACCUMULATED_DEPRECIATION_CODE = '1590';

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return self::CATEGORIES;
    }

    public function has(string $category): bool
    {
        return array_key_exists($category, self::CATEGORIES);
    }

    /** @return array<string, mixed> */
    public function config(string $category): array
    {
        if (! $this->has($category)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown asset category "%s". Known categories: %s.',
                $category,
                implode(', ', array_keys(self::CATEGORIES)),
            ));
        }

        return self::CATEGORIES[$category];
    }

    public function label(string $category): string
    {
        return $this->config($category)['label'];
    }

    public function plural(string $category): string
    {
        return $this->config($category)['plural'];
    }

    public function isVehicle(string $category): bool
    {
        return (bool) $this->config($category)['vehicle'];
    }

    public function defaultGl(string $category): ?string
    {
        return $this->config($category)['gl'];
    }

    public function defaultLife(string $category): ?int
    {
        return $this->config($category)['life'];
    }

    /** @return array<string, string> */
    public function options(): array
    {
        $options = [];

        foreach (self::CATEGORIES as $category => $config) {
            $options[$category] = $config['plural'];
        }

        return $options;
    }

    /** @return array<int, string> */
    public function categoriesFor(?string $shelf): array
    {
        if ($shelf === null || ! isset(self::SHELVES[$shelf])) {
            return array_keys(self::CATEGORIES);
        }

        return self::SHELVES[$shelf]['categories'] ?? array_keys(self::CATEGORIES);
    }

    public function shelf(?string $shelf): ?array
    {
        return $shelf !== null && isset(self::SHELVES[$shelf]) ? self::SHELVES[$shelf] : null;
    }

    public function conditionLabel(?string $condition): string
    {
        return $condition === null ? '—' : (self::CONDITIONS[$condition] ?? ucfirst($condition));
    }

    public function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? ucfirst($status);
    }

    public function methodLabel(string $method): string
    {
        return self::METHODS[$method] ?? ucfirst($method);
    }
}
