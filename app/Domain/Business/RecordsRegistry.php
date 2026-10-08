<?php

namespace App\Domain\Business;

use InvalidArgumentException;

/**
 * §12-03/04/09/10 — what each kind of business record *is*, in one place.
 *
 * The menu tree calls these nine things (trade licence, TIN & BIN, company
 * certificates, company documents, logo & seal, contracts, agreements,
 * certificates vault, insurance register, RJSC filings, compliance calendar,
 * brand assets) and the database calls them one table with a `kind`. This
 * registry is the translation: for every kind it says which fields exist, which
 * group it sits in, which date the register should track, and what the screen
 * should call each column.
 *
 * The point of putting it here rather than in nine controllers is that the
 * *rules of behaviour* are identical and must stay identical: a licence, a
 * policy and a contract all end on a date and can be renewed; a filing and a
 * statutory obligation both repeat and are completed. A field that exists gets
 * an input, a column and a filter automatically; a field that is missing is
 * missing everywhere, in the same way, with the same explanation.
 */
class RecordsRegistry
{
    /** How far ahead the expiring/due lenses look by default. */
    public const HORIZON_DAYS = 90;

    /** Inside this window a date reads as "act now" rather than "note it". */
    public const NEAR_DAYS = 30;

    public const KIND_LICENCE = 'licence';

    public const KIND_TAX_ID = 'tax_id';

    public const KIND_CERTIFICATE = 'certificate';

    public const KIND_CONTRACT = 'contract';

    public const KIND_AGREEMENT = 'agreement';

    public const KIND_BRAND_ASSET = 'brand_asset';

    public const KIND_INSURANCE = 'insurance';

    public const KIND_FILING = 'filing';

    public const KIND_OBLIGATION = 'obligation';

    /**
     * Field flags, in the order the forms and tables use them:
     *
     *  · `reference`   — the number the paper carries (licence no, policy no,
     *                    acknowledgement no)
     *  · `issuer`      — who issued it, or what it is filed with
     *  · `party`       — the other side of a contract or agreement
     *  · `value`       — a fee, a value or a sum insured
     *  · `issued`      — the date it was issued
     *  · `validity`    — it runs from a start date and (may) end on an expiry
     *                    date; these are the records a *renewal* can extend
     *  · `due`         — it has a deadline that repeats, and finishing it rolls
     *                    the next deadline forward
     *  · `attachment`  — the paper itself belongs in the document library,
     *                    attached to this record
     */
    public const KINDS = [
        self::KIND_LICENCE => [
            'label' => 'Trade licence',
            'plural' => 'Trade licences',
            'group' => 'company',
            'icon' => 'bi-patch-check',
            'hint' => 'A licence is only real while it is unexpired. Renewals are logged against the record, so the register can show the number that was true last year as well as the one that is true now.',
            'reference' => true,
            'reference_label' => 'Licence number',
            'issuer' => true,
            'issuer_label' => 'Issuing authority',
            'party' => false,
            'value' => true,
            'value_label' => 'Renewal fee',
            'issued' => true,
            'validity' => true,
            'due' => false,
            'attachment' => true,
        ],

        self::KIND_TAX_ID => [
            'label' => 'TIN / BIN',
            'plural' => 'TIN & BIN registrations',
            'group' => 'company',
            'icon' => 'bi-upc-scan',
            'hint' => 'TIN and BIN do not expire. What matters is that the numbers on file are the numbers on the certificates, and that a change of number is a recorded event rather than an edit nobody remembers.',
            'reference' => true,
            'reference_label' => 'TIN / BIN',
            'issuer' => true,
            'issuer_label' => 'Issuing office',
            'party' => false,
            'value' => false,
            'issued' => true,
            'validity' => false,
            'due' => false,
            'attachment' => true,
        ],

        self::KIND_CERTIFICATE => [
            'label' => 'Company certificate',
            'plural' => 'Company certificates',
            'group' => 'company',
            'icon' => 'bi-award',
            'hint' => 'Incorporation, commencement, share allotment and the rest — kept with the number they were issued under, who issued them, and the date they run out if they have one.',
            'reference' => true,
            'reference_label' => 'Certificate number',
            'issuer' => true,
            'issuer_label' => 'Issuing authority',
            'party' => false,
            'value' => false,
            'issued' => true,
            'validity' => true,
            'due' => false,
            'attachment' => true,
        ],

        self::KIND_CONTRACT => [
            'label' => 'Contract',
            'plural' => 'Contracts',
            'group' => 'documents',
            'icon' => 'bi-file-earmark-text',
            'hint' => 'A contract has a counterparty, a value and an end date. The register notices the end date before the other side does.',
            'reference' => true,
            'reference_label' => 'Contract number',
            'issuer' => false,
            'party' => true,
            'party_label' => 'Counterparty',
            'value' => true,
            'value_label' => 'Contract value',
            'issued' => true,
            'validity' => true,
            'due' => false,
            'attachment' => true,
        ],

        self::KIND_AGREEMENT => [
            'label' => 'Agreement',
            'plural' => 'Agreements',
            'group' => 'documents',
            'icon' => 'bi-file-earmark-check',
            'hint' => 'Distribution, tenancy, service and supply agreements: the terms that are not orders, with the parties named and the period on file.',
            'reference' => true,
            'reference_label' => 'Agreement number',
            'issuer' => false,
            'party' => true,
            'party_label' => 'Other party',
            'value' => true,
            'value_label' => 'Value, if stated',
            'issued' => true,
            'validity' => true,
            'due' => false,
            'attachment' => true,
        ],

        self::KIND_BRAND_ASSET => [
            'label' => 'Brand asset',
            'plural' => 'Brand assets',
            'group' => 'documents',
            'icon' => 'bi-palette',
            'hint' => 'The master logo, the seal, signboard artwork and the like: which version is current, who keeps it, and the file itself attached from the document library.',
            'reference' => true,
            'reference_label' => 'Version / asset code',
            'issuer' => false,
            'party' => false,
            'value' => false,
            'issued' => false,
            'validity' => false,
            'due' => false,
            'attachment' => true,
        ],

        self::KIND_INSURANCE => [
            'label' => 'Insurance policy',
            'plural' => 'Insurance policies',
            'group' => 'compliance',
            'icon' => 'bi-shield-check',
            'hint' => 'Fire, stock, vehicle and employee cover: the policy number, the insurer, the sum insured and the day cover stops.',
            'reference' => true,
            'reference_label' => 'Policy number',
            'issuer' => true,
            'issuer_label' => 'Insurer',
            'party' => false,
            'value' => true,
            'value_label' => 'Sum insured',
            'issued' => true,
            'validity' => true,
            'due' => false,
            'attachment' => true,
        ],

        self::KIND_FILING => [
            'label' => 'RJSC filing',
            'plural' => 'RJSC filings',
            'group' => 'compliance',
            'icon' => 'bi-building-gear',
            'hint' => 'Returns and filings with the registrar: what is due, what has been filed, and the acknowledgement number it was filed under. Completing one rolls the next due date forward.',
            'reference' => true,
            'reference_label' => 'Acknowledgement number',
            'issuer' => true,
            'issuer_label' => 'Filed with',
            'party' => false,
            'value' => false,
            'issued' => false,
            'validity' => false,
            'due' => true,
            'attachment' => true,
        ],

        self::KIND_OBLIGATION => [
            'label' => 'Statutory obligation',
            'plural' => 'Statutory obligations',
            'group' => 'compliance',
            'icon' => 'bi-calendar-check',
            'hint' => 'The compliance calendar: VAT returns, TDS deposits, labour-law duties. Each one knows how often it comes round, so completing it sets the next date rather than clearing the row.',
            'reference' => false,
            'issuer' => true,
            'issuer_label' => 'Authority',
            'party' => false,
            'value' => false,
            'issued' => false,
            'validity' => false,
            'due' => true,
            'attachment' => true,
        ],
    ];

    /** The shelf headings the register index groups kinds under. */
    public const GROUPS = [
        'company' => 'Company identity',
        'compliance' => 'Compliance',
        'documents' => 'Documents & papers',
    ];

    /** Repeat cycles a filing or obligation can be put on. */
    public const CADENCES = [
        1 => 'Monthly',
        3 => 'Quarterly',
        6 => 'Half-yearly',
        12 => 'Yearly',
        24 => 'Every two years',
        60 => 'Every five years',
    ];

    /**
     * What a register row can be, once the dates have been read. These are
     * statements about the *clock*, not about the row's status: a retired record
     * is still retired whether or not its date has passed.
     */
    public const STATES = [
        'overdue' => 'Overdue',
        'expired' => 'Expired',
        'due_soon' => 'Due soon',
        'expiring' => 'Expiring',
        'valid' => 'In force',
        'undated' => 'No date on file',
        'retired' => 'Retired',
    ];

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return self::KINDS;
    }

    /** @return array<string, string> */
    public function groups(): array
    {
        return self::GROUPS;
    }

    /** @return array<string, array<string, mixed>> */
    public function kindsFor(string $group): array
    {
        return array_filter(self::KINDS, fn (array $kind) => $kind['group'] === $group);
    }

    public function has(string $kind): bool
    {
        return array_key_exists($kind, self::KINDS);
    }

    /**
     * The configuration for one kind. An unknown kind is a programming mistake,
     * not a user mistake, so it is loud and it names the kinds that do exist.
     *
     * @return array<string, mixed>
     */
    public function config(string $kind): array
    {
        if (! $this->has($kind)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown business record kind "%s". Known kinds: %s.',
                $kind,
                implode(', ', array_keys(self::KINDS)),
            ));
        }

        return self::KINDS[$kind];
    }

    public function label(string $kind): string
    {
        return $this->config($kind)['label'];
    }

    public function plural(string $kind): string
    {
        return $this->config($kind)['plural'];
    }

    /** The URL segment a kind's register lives at: `brand_asset` → `brand-asset`. */
    public function slug(string $kind): string
    {
        return str_replace('_', '-', $kind);
    }

    /** The kind behind a URL segment, or null when the segment is not a kind. */
    public function kindForSlug(string $slug): ?string
    {
        $kind = str_replace('-', '_', strtolower($slug));

        return $this->has($kind) ? $kind : null;
    }

    /** @return array<string, string> kind => plural label, for selects. */
    public function options(): array
    {
        $options = [];

        foreach (self::KINDS as $kind => $config) {
            $options[$kind] = $config['plural'];
        }

        return $options;
    }

    /** Does this kind have an expiry date a renewal could extend? */
    public function isRenewable(string $kind): bool
    {
        return (bool) $this->config($kind)['validity'];
    }

    /** Does this kind carry a deadline that repeats? */
    public function isRecurring(string $kind): bool
    {
        return (bool) $this->config($kind)['due'];
    }

    public function cadenceLabel(?int $months): string
    {
        if ($months === null || $months <= 0) {
            return 'One-off';
        }

        return self::CADENCES[$months] ?? 'Every '.$months.' months';
    }

    /** The tone a state chip should wear. */
    public function tone(string $state): string
    {
        return match ($state) {
            'expired', 'overdue' => 'erp-chip-danger',
            'expiring', 'due_soon' => 'erp-chip-warn',
            'valid' => 'erp-chip-soft',
            default => 'erp-chip-outline',
        };
    }
}
