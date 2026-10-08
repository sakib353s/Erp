<?php

namespace App\Domain\Audit;

use Illuminate\Support\Str;

/**
 * §16-33 — the action vocabulary.
 *
 * The audit trail is only searchable if the words in it are known words. This
 * class is the one place that says what those words are:
 *
 *  · **modules** — the first segment of every action (`sales.invoice_issued`)
 *    resolves to a real business module, so a filter can be offered that is
 *    complete *by construction* rather than a hand-kept list that drifts behind
 *    the code (the tree records 286 actions; the old flat list named 39 of them);
 *  · **legacy names** — five producers recorded bare words (`pay`, `reconcile`,
 *    `escalate`) or built names by concatenation (`print_*`, `notify_*`) before
 *    this vocabulary existed. Those rows are hash-chained and must never be
 *    rewritten, so they are *translated* here: the viewer, the filters and any
 *    report show them under the same fact as their modern equivalents, and both
 *    names find the same rows;
 *  · **sensitive actions** — the ones worth waking somebody for: sign-in
 *    failures, refusals, role and instance changes, exports and downloads of the
 *    trail itself. Severity routing (§16-36) reads this list rather than
 *    pattern-matching action strings in a controller.
 *
 * Nothing here is a place to *record*: `AuditRecorder` still writes what the
 * caller says. This class describes and translates what was written.
 */
final class AuditVocabulary
{
    /** First segment of an action → the module a person would look under. */
    public const MODULES = [
        'accounting' => 'Accounting',
        'approval' => 'Approvals',
        'auth' => 'Sign-in',
        'branch' => 'Branches',
        'business' => 'Business management',
        'cash' => 'Expenses & cash',
        'cash_bank' => 'Cash & bank',
        'config' => 'Configuration',
        'customers' => 'Customers',
        'delivery' => 'Delivery',
        'document' => 'Documents',
        'documents' => 'Documents (public links)',
        'hr' => 'People',
        'instance' => 'Instance',
        'inventory' => 'Warehouse',
        'marketing' => 'Marketing',
        'menu' => 'Navigation',
        'permission' => 'Access control',
        'petty_cash' => 'Petty cash',
        'pos' => 'Counter (POS)',
        'purchase' => 'Purchase',
        'record' => 'Records',
        'returns' => 'Returns',
        'role' => 'Roles',
        'sales' => 'Sales',
        'search' => 'Search',
        'security' => 'Security',
        'workflow' => 'Workflows',
    ];

    /**
     * Names that were recorded before the vocabulary existed, and the fact they
     * actually describe. Historical rows keep their own words — the chain is
     * append-only — so translation happens on the way out, never on the way in.
     */
    public const LEGACY = [
        'reconcile' => 'sales.cod_reconciled',
        'pay' => 'sales.commission_paid',
        'escalate' => 'workflow.escalated',
    ];

    /** Names built by concatenation: `print_invoice`, `notify_sms`, … */
    public const LEGACY_PREFIXES = [
        'print_' => 'sales.bulk_printed',
        'notify_' => 'sales.bulk_notified',
    ];

    /** Prefixes that are worth somebody's attention (severity routing, §16-36). */
    public const SENSITIVE = [
        'auth.',
        'security.',
        'permission.',
        'role.',
        'instance.',
        'approval.reject',
        'config.protected_denied',
        'document.export',
        'document.download',
        'documents.public_link_',
        'sales.invoice_verification_',
        'pos.session_closed',
    ];

    /** The module key an action belongs to, or null when the name is unknown. */
    public static function module(?string $action): ?string
    {
        $canonical = self::canonical((string) $action);
        $segment = explode('.', $canonical)[0] ?? '';

        return array_key_exists($segment, self::MODULES) ? $segment : null;
    }

    /** The module's human name. */
    public static function moduleLabel(?string $action): string
    {
        $module = self::module($action);

        return $module === null ? 'Unknown' : self::MODULES[$module];
    }

    /** What the action says, in words: `sales.invoice_issued` → “Invoice issued”. */
    public static function label(?string $action): string
    {
        $canonical = self::canonical((string) $action);
        $rest = Str::after($canonical, '.');

        $words = str_replace('_', ' ', $rest === '' ? $canonical : $rest);

        return Str::ucfirst(strtolower($words));
    }

    /**
     * The modern name for a recorded action, so both generations of rows can be
     * shown under one fact.
     */
    public static function canonical(string $action): string
    {
        if (array_key_exists($action, self::LEGACY)) {
            return self::LEGACY[$action];
        }

        foreach (self::LEGACY_PREFIXES as $prefix => $canonical) {
            if (str_starts_with($action, $prefix)) {
                return $canonical;
            }
        }

        return $action;
    }

    /**
     * Every name that means the same fact, for a filter: asking for
     * `sales.cod_reconciled` also finds the rows recorded as `reconcile`.
     *
     * @return array<int, string>
     */
    public static function matchNames(string $action): array
    {
        $canonical = self::canonical($action);
        $names = [$canonical];

        foreach (self::LEGACY as $legacy => $target) {
            if ($target === $canonical) {
                $names[] = $legacy;
            }
        }

        foreach (self::LEGACY_PREFIXES as $prefix => $target) {
            if ($target === $canonical) {
                $names[] = $prefix.'%';
            }
        }

        return array_values(array_unique($names));
    }

    public static function isSensitive(?string $action): bool
    {
        $canonical = self::canonical((string) $action);

        foreach (self::SENSITIVE as $prefix) {
            if (str_starts_with($canonical, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Is this a name the vocabulary recognises, including the legacy ones? */
    public static function knows(?string $action): bool
    {
        $action = (string) $action;

        if ($action === '') {
            return false;
        }

        return self::module($action) !== null;
    }

    /**
     * The module filter, as a list of action patterns that belong to it — the
     * legacy names included, so "Sales" does not silently hide the commission
     * payments recorded as `pay`.
     *
     * @return array<int, string>
     */
    public static function patternsForModule(string $module): array
    {
        $patterns = [$module.'.%'];

        foreach (self::LEGACY + self::LEGACY_PREFIXES as $legacy => $canonical) {
            if (str_starts_with($canonical, $module.'.')) {
                $patterns[] = str_ends_with($legacy, '_') ? $legacy.'%' : $legacy;
            }
        }

        return array_values(array_unique($patterns));
    }

    /** @return array<string, string> module key → label, for a filter control. */
    public static function moduleOptions(): array
    {
        return self::MODULES;
    }
}
