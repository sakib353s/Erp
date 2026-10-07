<?php

namespace App\Http\Requests;

/**
 * Workflow definition update — identical validation surface as create:
 * a full rewrite of levels/conditions is what produces a NEW immutable
 * WorkflowVersion in WorkflowController::update.
 */
class UpdateWorkflowRequest extends StoreWorkflowRequest
{
}
