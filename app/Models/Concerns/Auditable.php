<?php

namespace App\Models\Concerns;

use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit_logs row whenever the model is created, updated or deleted.
 * Models may define auditBookingId() so the change appears in that booking's activity.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            app(AuditLogService::class)->record('created', $model, null, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            $changes = array_diff_key($model->getChanges(), array_flip(['updated_at', ...$model->auditIgnored()]));
            if ($changes === []) {
                return;
            }
            $before = array_intersect_key($model->getRawOriginal(), $changes);
            app(AuditLogService::class)->record('updated', $model, $before, $changes);
        });

        static::deleted(function (Model $model) {
            app(AuditLogService::class)->record('deleted', $model, $model->getAttributes(), null);
        });
    }

    /** @return list<string> Attributes whose changes alone should not create an audit entry. */
    public function auditIgnored(): array
    {
        return [];
    }

    public function auditBookingId(): ?int
    {
        return $this->getAttribute('booking_id');
    }
}
