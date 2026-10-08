<?php

namespace App\Domain\Business;

/**
 * §12-15 — the providers a Bangladeshi company almost always has.
 *
 * Not fixtures and not demo data: this is the same kind of reference row as a
 * chart-of-accounts entry. A company switching the desk on gets the six
 * counterparties that exist on day one — two power distributors, the water
 * authority, the gas utility, a connectivity line and the landlord — each with
 * the account its bills belong in, and every one of them editable, because a
 * company in Uttara deals with DPDC and a company in Narayanganj deals with
 * somebody else.
 *
 * The rows are only materialised for a company that has none, so a registry a
 * person has edited is never overwritten by a later visit to the desk.
 */
class UtilityRegistry
{
    /**
     * @var array<int, array{code:string, name:string, family:string, consumer_no:?string, premises:?string, due_day:?int}>
     */
    public const DEFAULTS = [
        [
            'code' => 'DESCO',
            'name' => 'DESCO — Dhaka Electric Supply Company',
            'family' => UtilityProvider::FAMILY_ELECTRICITY,
            'consumer_no' => null,
            'premises' => null,
            'due_day' => 10,
        ],
        [
            'code' => 'DPDC',
            'name' => 'DPDC — Dhaka Power Distribution Company',
            'family' => UtilityProvider::FAMILY_ELECTRICITY,
            'consumer_no' => null,
            'premises' => null,
            'due_day' => 10,
        ],
        [
            'code' => 'WASA',
            'name' => 'WASA — Water Supply & Sewerage Authority',
            'family' => UtilityProvider::FAMILY_WATER,
            'consumer_no' => null,
            'premises' => null,
            'due_day' => 15,
        ],
        [
            'code' => 'TITAS',
            'name' => 'TITAS — Titas Gas Transmission & Distribution',
            'family' => UtilityProvider::FAMILY_GAS,
            'consumer_no' => null,
            'premises' => null,
            'due_day' => 20,
        ],
        [
            'code' => 'INTERNET',
            'name' => 'Internet & Mobile',
            'family' => UtilityProvider::FAMILY_INTERNET,
            'consumer_no' => null,
            'premises' => null,
            'due_day' => 5,
        ],
        [
            'code' => 'RENT',
            'name' => 'Landlord — office rent',
            'family' => UtilityProvider::FAMILY_RENT,
            'consumer_no' => null,
            'premises' => null,
            'due_day' => 1,
        ],
    ];
}
