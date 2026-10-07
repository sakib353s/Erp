<?php

namespace App\Domain\Documents;

use App\Domain\Foundation\NumberingRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Document TYPE registry (Rules 8/9/10). The normal commercial sales
 * document has printed_title = "INVOICE"; Mushak 9.1 / 11 are separate
 * statutory rows. Renderers read titles from here — never from code.
 */
class DocumentType extends Model
{
    protected $fillable = [
        'code', 'type_group', 'name', 'printed_title', 'default_template',
        'language', 'is_statutory', 'tax_applicable', 'requires_numbering', 'is_active',
    ];

    protected $casts = [
        'is_statutory' => 'boolean',
        'tax_applicable' => 'boolean',
        'requires_numbering' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function numberingRules(): HasMany
    {
        return $this->hasMany(NumberingRule::class);
    }

    public static function invoiceTitle(): string
    {
        return static::where('code', 'invoice')->value('printed_title') ?? 'INVOICE';
    }
}
