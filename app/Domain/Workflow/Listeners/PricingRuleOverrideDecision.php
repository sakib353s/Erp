<?php

namespace App\Domain\Workflow\Listeners;

use App\Domain\Masters\PricingRule;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Events\WorkflowApproved;
use App\Domain\Workflow\Events\WorkflowEvent;
use App\Domain\Workflow\Events\WorkflowRejected;

/**
 * Manual override decision hook (02-113): when a pricing_rule/override
 * request is approved the held payload is applied to the rule (the
 * store case was created inactive, so approving also activates it);
 * a rejection only stamps the rule — update holds never touched the
 * row, store holds stay disabled.
 */
class PricingRuleOverrideDecision
{
    public function handle(WorkflowEvent $event): void
    {
        if (! $event instanceof WorkflowApproved && ! $event instanceof WorkflowRejected) {
            return;
        }

        $approval = ApprovalRequest::query()->find($event->requestId);

        if ($approval === null
            || $approval->entity_type !== PricingRule::ENTITY_TYPE
            || $approval->action !== PricingRule::APPROVAL_ACTION
            || ! in_array($approval->status, ['approved', 'rejected'], true)) {
            return;
        }

        $rule = PricingRule::query()->find($approval->entity_id);

        // Nothing held (bypassed or directly applied) — nothing to decide.
        if ($rule === null || $rule->approval_status !== 'pending') {
            return;
        }

        if ($approval->status === 'rejected') {
            $rule->forceFill(['approval_status' => 'rejected'])->save();

            return;
        }

        $snapshot = array_intersect_key(
            (array) $approval->snapshot,
            array_flip($rule->getFillable()),
        );

        $rule->forceFill($snapshot + ['approval_status' => 'approved'])->save();
    }
}
