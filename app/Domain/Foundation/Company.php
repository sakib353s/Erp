<?php

namespace App\Domain\Foundation;

use App\Domain\Foundation\Concerns\Auditable;
use App\Domain\People\Employee;
use App\Domain\Workflow\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * THE company of this operational ERP instance (Rules 1 & 2).
 * Creation is guarded by CompanyService (singleton) + a DB unique column.
 */
class Company extends Model
{
    use Auditable;

    protected $fillable = [
        'name', 'legal_name', 'email', 'phone', 'website',
        'address_line1', 'address_line2', 'area', 'district', 'postal_code', 'country',
        'currency', 'timezone', 'locale', 'alt_locale', 'fiscal_year_start_month',
        'trade_license_no', 'tin', 'bin', 'is_active',
        'logo_document_id', 'seal_document_id', 'date_format', 'number_format',
        'lakh_crore_display', 'bengali_numerals', 'business_preferences',
    ];

    protected $casts = [
        'singleton' => 'boolean',
        'is_active' => 'boolean',
        'fiscal_year_start_month' => 'integer',
        'lakh_crore_display' => 'boolean',
        'bengali_numerals' => 'boolean',
        'business_preferences' => 'array',
    ];

    public static function current(): ?static
    {
        return static::query()->first();
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function currentFiscalYear(): ?FiscalYear
    {
        return $this->fiscalYears()->current()->first();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
