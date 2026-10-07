<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\User;

/**
 * First-boot onboarding checklist (spec §49-10): shows REAL completion
 * state instead of fake dashboard data.
 */
class OnboardingChecklist
{
    /** @return array<int, array{key:string,label:string,done:bool,route:?string}> */
    public function steps(): array
    {
        $company = Company::current();

        return [
            [
                'key' => 'company',
                'label' => 'Company profile configured',
                'done' => $company !== null && $company->address_line1 !== null,
                'route' => '/app/settings/general',
            ],
            [
                'key' => 'branch',
                'label' => 'At least one branch operating',
                'done' => Branch::query()->where('is_active', true)->exists(),
                'route' => '/app/branches',
            ],
            [
                'key' => 'users',
                'label' => 'Team users created beyond the setup admin',
                'done' => User::query()->count() > 1,
                'route' => '/app/users',
            ],
            [
                'key' => 'numbering',
                'label' => 'Document numbering rules configured',
                'done' => \App\Domain\Foundation\NumberingRule::query()->where('is_active', true)->exists(),
                'route' => '/app/settings/numbering',
            ],
            [
                'key' => 'workflow',
                'label' => 'At least one approval workflow active',
                'done' => \App\Domain\Workflow\WorkflowDefinition::query()->where('is_active', true)->exists(),
                'route' => '/app/workflows',
            ],
            [
                'key' => 'security',
                'label' => 'Security settings reviewed',
                'done' => app(\App\Domain\Settings\Services\SettingService::class)->hasExplicitGroup('security'),
                'route' => '/app/settings/security',
            ],
        ];
    }

    public function complete(): bool
    {
        foreach ($this->steps() as $step) {
            if (! $step['done']) {
                return false;
            }
        }

        return true;
    }
}
