<?php

namespace App\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One normalized, permission- and branch-aware search hit (D17 / §16-49).
 * Rebuilt from source tables by SearchIndexRebuilder; never seeded
 * with fake business data.
 *
 * `entity_type` is a machine key ('purchase_bill'), and it is never shown to
 * anybody as-is: the words a person reads come from the map below, so a group
 * heading says "Purchase bills" rather than "Purchase_bills" and a palette row
 * can say what it found. A type that is not in the map still works — it is
 * humanized rather than dropped — because the alternative is a heading reading
 * `purchase_bills` the day somebody adds a source and forgets this file.
 */
class SearchIndex extends Model
{
    protected $table = 'search_index';

    protected $fillable = [
        'company_id', 'entity_type', 'entity_id', 'branch_id',
        'permission_key', 'title', 'subtitle', 'excerpt', 'url', 'normalized',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'branch_id' => 'integer',
    ];

    /** entity_type => [group heading, one record's kind] */
    public const TYPES = [
        'user' => ['People', 'Person'],
        'branch' => ['Branches', 'Branch'],
        'warehouse' => ['Warehouses', 'Warehouse'],
        'role' => ['Roles', 'Role'],
        'document' => ['Files', 'File'],
        'customer' => ['Customers', 'Customer'],
        'supplier' => ['Suppliers', 'Supplier'],
        'product' => ['Products', 'Product'],
        'employee' => ['Employees', 'Employee'],
        'invoice' => ['Invoices', 'Invoice'],
        'quotation' => ['Quotations', 'Quotation'],
        'order' => ['Sales orders', 'Sales order'],
        'challan' => ['Delivery challans', 'Delivery challan'],
        'purchase_order' => ['Purchase orders', 'Purchase order'],
        'purchase_bill' => ['Purchase bills', 'Purchase bill'],
        'warranty' => ['Warranties', 'Warranty'],
    ];

    /** The heading a group of these records is listed under. */
    public static function heading(string $type): string
    {
        return self::TYPES[$type][0] ?? ucfirst(str_replace('_', ' ', $type)).'s';
    }

    /** What one of these records is, in words, beside its title. */
    public static function label(string $type): string
    {
        return self::TYPES[$type][1] ?? ucfirst(str_replace('_', ' ', $type));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Foundation\Company::class);
    }
}
