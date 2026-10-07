<?php

namespace App\Domain\Foundation\Services;

use App\Domain\Foundation\NumberingRule;
use App\Domain\Foundation\NumberingSequence;
use Illuminate\Support\Facades\DB;

/**
 * Row-locked document number allocation (spec section L, Rule 12).
 * The sequence row is locked inside the caller's transaction so two
 * concurrent requests can never receive the same number.
 */
class NumberingService
{
    /**
     * @param  int|null  $branchId  0/nil = company-wide rule; falls back to company rule
     * @return string formatted document number
     */
    public function allocate(int $documentTypeId, ?int $branchId = null, ?string $branchCode = null): string
    {
        return DB::transaction(function () use ($documentTypeId, $branchId, $branchCode) {
            $companyId = app(TenantContext::class)->companyId()
                ?? NumberingRule::query()->where('document_type_id', $documentTypeId)->value('company_id');

            $rule = $this->resolveRule($companyId, $documentTypeId, $branchId);

            $periodKey = match ($rule->reset_period) {
                'yearly' => date('Y'),
                'monthly' => date('Y-m'),
                default => '',
            };

            $sequence = NumberingSequence::query()
                ->where('numbering_rule_id', $rule->id)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $sequence = NumberingSequence::create([
                    'numbering_rule_id' => $rule->id,
                    'period_key' => $periodKey,
                    'last_value' => 0,
                ]);
                $sequence = NumberingSequence::query()
                    ->whereKey($sequence->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $sequence->last_value++;
            $sequence->save();

            return $this->format($rule, $sequence->last_value, $branchCode);
        });
    }

    protected function resolveRule(int $companyId, int $documentTypeId, ?int $branchId): NumberingRule
    {
        if ($branchId !== null && $branchId !== 0) {
            $branchRule = NumberingRule::query()
                ->where('company_id', $companyId)
                ->where('document_type_id', $documentTypeId)
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->first();

            if ($branchRule !== null) {
                return $branchRule;
            }
        }

        $companyRule = NumberingRule::query()
            ->where('company_id', $companyId)
            ->where('document_type_id', $documentTypeId)
            ->where('branch_id', 0)
            ->where('is_active', true)
            ->first();

        if ($companyRule === null) {
            abort(500, 'Numbering rule is not configured for this document type.');
        }

        return $companyRule;
    }

    protected function format(NumberingRule $rule, int $value, ?string $branchCode): string
    {
        $date = now();

        return strtr($rule->pattern, [
            '{PREFIX}' => $rule->prefix,
            '{BRANCH}' => $branchCode ?? 'MAIN',
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{SEQ}' => str_pad((string) $value, max(1, $rule->padding), '0', STR_PAD_LEFT),
        ]);
    }
}
