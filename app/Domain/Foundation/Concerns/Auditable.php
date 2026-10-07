<?php

namespace App\Domain\Foundation\Concerns;

use App\Domain\Audit\Services\AuditRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Records create/update/delete actions in the tamper-evident audit trail
 * (Rule 16). Before/after payloads pass through AuditRedactor so secrets
 * never reach the audit tables. Updates capture the original values via
 * `getOriginal()` at the `updating` moment.
 */
trait Auditable
{
    /**
     * Runtime-only stash for the update payload. These MUST be declared
     * properties (not dynamic ones): on an Eloquent model, writing an
     * undeclared property routes through __set() into the attributes
     * array and would end up as literal COLUMNS in the UPDATE statement.
     */
    protected ?array $auditBeforePayload = null;

    protected ?array $auditAfterPayload = null;

    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            app(AuditRecorder::class)->recordModel(
                action: 'record.create',
                model: $model,
                before: null,
                after: $model->getAttributes(),
            );
        });

        static::updating(function (Model $model): void {
            $dirty = $model->getDirty();
            unset($dirty['updated_at']);

            if ($dirty === []) {
                return;
            }

            $before = [];
            foreach (array_keys($dirty) as $key) {
                $before[$key] = $model->getOriginal($key);
            }

            $model->auditBeforePayload = $before;
            $model->auditAfterPayload = $dirty;
        });

        static::updated(function (Model $model): void {
            if ($model->auditBeforePayload === null) {
                return;
            }

            app(AuditRecorder::class)->recordModel(
                action: 'record.update',
                model: $model,
                before: $model->auditBeforePayload,
                after: $model->auditAfterPayload,
            );

            $model->auditBeforePayload = null;
            $model->auditAfterPayload = null;
        });

        static::deleted(function (Model $model): void {
            app(AuditRecorder::class)->recordModel(
                action: 'record.delete',
                model: $model,
                before: $model->getAttributes(),
                after: null,
            );
        });
    }
}
