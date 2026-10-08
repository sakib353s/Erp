<?php

namespace App\Domain\Business;

/**
 * §12-16 — the shapes a visit comes in.
 *
 * The purposes are not decoration: a Bangladeshi gate asks why somebody is here
 * because the answer decides where they wait and who walks them in. A courier
 * stops at reception, an interviewer is expected by HR, a maintenance crew has
 * to be walked past the stock room. So the purpose is a closed list of six, each
 * with the shelf it belongs on and the sentence the desk shows beside it.
 *
 * Nothing here is stored on a visit — a visit holds the key, and this is what the
 * key means. That way the wording can change without a migration and no row can
 * ever hold a purpose nobody can read.
 */
class VisitorRegistry
{
    /**
     * @var array<string, array{label:string, icon:string, blurb:string}>
     */
    public const PURPOSES = [
        'meeting' => [
            'label' => 'Meeting',
            'icon' => 'bi-people',
            'blurb' => 'Here to see somebody — the host is told the moment they arrive.',
        ],
        'delivery' => [
            'label' => 'Delivery / courier',
            'icon' => 'bi-box-seam',
            'blurb' => 'Drop at reception; nothing comes past the gate unaccompanied.',
        ],
        'interview' => [
            'label' => 'Interview / candidate',
            'icon' => 'bi-person-badge',
            'blurb' => 'Sent to whoever is hiring, with the paper trail on the visit.',
        ],
        'service' => [
            'label' => 'Service / maintenance',
            'icon' => 'bi-tools',
            'blurb' => 'Someone working on the building — noted, because they carry tools.',
        ],
        'vendor' => [
            'label' => 'Vendor / supplier',
            'icon' => 'bi-truck',
            'blurb' => 'Here on business: which supplier, and the items they brought in.',
        ],
        'other' => [
            'label' => 'Other',
            'icon' => 'bi-three-dots',
            'blurb' => 'Anything the six shelves do not name — described in the notes.',
        ],
    ];

    /** The purposes a booking may be made under. */
    public static function purposes(): array
    {
        return array_keys(self::PURPOSES);
    }

    public static function label(string $purpose): string
    {
        return self::PURPOSES[$purpose]['label'] ?? ucfirst($purpose);
    }

    public static function icon(string $purpose): string
    {
        return self::PURPOSES[$purpose]['icon'] ?? 'bi-person';
    }
}
