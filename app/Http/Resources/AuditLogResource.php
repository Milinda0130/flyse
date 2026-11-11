<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /** Cost-related keys hidden from users without cost access. */
    private const COST_KEYS = ['supplier_total'];

    public function toArray(Request $request): array
    {
        $hideCosts = ! $request->user()?->can('view-costs');
        $isSupplierPayment = $this->entity_type === 'Payment'
            && in_array(($this->after_json['direction'] ?? $this->before_json['direction'] ?? null), ['supplier_payment', 'supplier_refund'], true);

        $filter = fn (?array $values) => $values === null ? null
            : ($hideCosts ? array_diff_key($values, array_flip(self::COST_KEYS)) : $values);

        return [
            'id' => $this->id,
            'user' => new UserSummaryResource($this->whenLoaded('user')),
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'booking_id' => $this->booking_id,
            'action' => $this->action,
            'before' => $hideCosts && $isSupplierPayment ? null : $filter($this->before_json),
            'after' => $hideCosts && $isSupplierPayment ? null : $filter($this->after_json),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
