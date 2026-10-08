<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\DocumentType;
use InvalidArgumentException;
use RuntimeException;

/**
 * §16-24 — the title rule.
 *
 * A printed document's title is not decoration and it is not free text: it is a
 * claim about what the paper is. Three rules, enforced here rather than trusted
 * to whichever template renders last:
 *
 *  1. `document_types.printed_title` drives the output. One place says what
 *     each kind of paper is called, and the seeder keeps that place canonical
 *     (`DocumentTypeRegistry`).
 *  2. A normal sale prints **INVOICE**. That is the common case, so it gets the
 *     plain word — not "TAX INVOICE", which is a statutory claim, and not
 *     "PROFORMA INVOICE", which is a different document with a different code.
 *  3. A statutory form is a **separate** type and prints its own title
 *     (MUSHAK 9.1). It never borrows `invoice`, and `invoice` never borrows its
 *     title. The existing Mushak 9.1 door has always behaved this way; this
 *     service is where that intent becomes checkable.
 *
 * The tax block is conditional in the same spirit: a document type that is not
 * tax-applicable never shows a VAT line, and a document of a tax-applicable type
 * shows one only when the transaction actually carries tax (D10) — a zero-rate
 * sale does not get an empty "VAT 0.00" row that reads like a charge.
 */
class DocumentTitleService
{
    public const INVOICE = 'INVOICE';

    /** Titles that claim a statutory form and may only appear on one. */
    public const STATUTORY_WORDS = ['MUSHAK', 'VAT', 'TAX INVOICE'];

    /**
     * @return array{code:?string,title:string,statutory:bool,tax_applicable:bool,tax_block:bool}
     */
    public function resolve(?string $code, bool $hasTax = false): array
    {
        $type = $this->type($code);

        $title = $this->titleFrom($type, $code);
        $statutory = (bool) ($type?->is_statutory ?? false);
        $taxApplicable = (bool) ($type?->tax_applicable ?? false);

        $this->assertHonest($code, $title, $statutory);

        return [
            'code' => $type?->code,
            'title' => $this->displayTitle($code, $title),
            'statutory' => $statutory,
            'tax_applicable' => $taxApplicable,
            'tax_block' => $taxApplicable && $hasTax,
        ];
    }

    /** The title alone, for a template or a filename. */
    public function titleFor(?string $code): string
    {
        return $this->displayTitle($code, $this->titleFrom($this->type($code), $code));
    }

    /**
     * §16-50 — the paper's title in the reader's language.
     *
     * The honesty check above runs on the English claim (a statutory form must
     * not print as INVOICE whether the reader reads Bangla or English), so by the
     * time we reach here the word is already honest; we only pick the language.
     * The key is the document type code, so `doc.invoice` carries the Bangla
     * "চালান" — falling back to the English title when no Bangla row exists, never
     * to an empty heading.
     */
    protected function displayTitle(?string $code, string $title): string
    {
        if (($code ?? '') === '') {
            return $title;
        }

        return app(\App\Domain\Foundation\Services\Translator::class)->get('doc.'.$code, $title);
    }

    public function isStatutory(?string $code): bool
    {
        return (bool) ($this->type($code)?->is_statutory ?? false);
    }

    /** A statutory type must exist in `document_types`; an unknown code is a bug. */
    protected function type(?string $code): ?DocumentType
    {
        if ($code === null || $code === '') {
            return null;
        }

        $type = DocumentType::query()->where('code', $code)->first();

        if ($type === null) {
            throw new InvalidArgumentException("Unknown document type [{$code}] — the title rule has nothing to read.");
        }

        return $type;
    }

    protected function titleFrom(?DocumentType $type, ?string $code): string
    {
        $title = strtoupper(trim((string) ($type?->printed_title ?? '')));

        if ($title !== '') {
            return $title;
        }

        // No configured title: only one honest fallback exists, and only for the
        // one code whose title is a rule rather than a configuration.
        return $code === null || $code === 'invoice' ? self::INVOICE : strtoupper(str_replace('_', ' ', (string) $code));
    }

    /** Rule 8/9 as an assertion: no paper may claim more than it is. */
    protected function assertHonest(?string $code, string $title, bool $statutory): void
    {
        if ($statutory) {
            if ($title === self::INVOICE) {
                throw new RuntimeException(
                    "Statutory document type [{$code}] cannot print as INVOICE — it is a separate form, not an invoice.",
                );
            }

            return;
        }

        foreach (self::STATUTORY_WORDS as $word) {
            if ($word === 'VAT' ? preg_match('/(^|\W)VAT(\W|$)/', $title) === 1 : str_contains($title, $word)) {
                throw new RuntimeException(
                    "Document type [{$code}] is not statutory but its printed title is [{$title}] — a tax form's title belongs to a tax form.",
                );
            }
        }
    }
}
