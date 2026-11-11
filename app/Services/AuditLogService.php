<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    /** Attributes never written to the audit log in clear text. */
    private const REDACTED = ['password', 'remember_token', 'passport_number'];

    private const IGNORED = ['created_at', 'updated_at'];

    public function record(string $action, Model $model, ?array $before, ?array $after): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'entity_type' => class_basename($model),
            'entity_id' => $model->getKey(),
            'booking_id' => method_exists($model, 'auditBookingId') ? $model->auditBookingId() : null,
            'action' => $action,
            'before_json' => $this->clean($before),
            'after_json' => $this->clean($after),
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    private function clean(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        $values = array_diff_key($values, array_flip(self::IGNORED));
        foreach (self::REDACTED as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = $values[$key] === null ? null : '[redacted]';
            }
        }

        return $values;
    }
}
