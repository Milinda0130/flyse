<?php

namespace App\Http\Requests;

use App\Enums\BookingStatus;
use App\Enums\PaymentDirection;
use App\Rules\ReasonableDate;
use Illuminate\Validation\Rule;

/**
 * Change a booking's status, with only the details that step needs:
 * - Reservation Confirmed: optional supplier, PNR, deadlines and prices.
 * - Changed: a note describing what changed (required).
 * - Cancelled: a reason (required), optional retained amounts and a refund owed.
 * The ticketing requirements are checked in BookingWorkflowService against saved data.
 */
class StatusTransitionRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->upper(['pnr']);
    }

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        // Recording a refund is a financial action with its own permission.
        return ! $this->filled('customer_refund_amount')
            || $this->user()->can('record-payment', PaymentDirection::CustomerRefund);
    }

    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'];

        return [
            'status' => ['required', Rule::enum(BookingStatus::class)],
            'note' => ['nullable', 'string', 'max:2000', Rule::requiredIf($this->input('status') === BookingStatus::Changed->value)],
            'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf($this->input('status') === BookingStatus::Cancelled->value)],
            'next_action' => ['nullable', 'string', 'max:255'],
            // Reservation details
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')],
            'pnr' => ['nullable', 'string', 'max:20', 'alpha_num'],
            'ticketing_deadline' => ['nullable', 'date', new ReasonableDate],
            'payment_due_date' => ['nullable', 'date', new ReasonableDate],
            'selling_total' => $money,
            'service_fee' => $money,
            'supplier_total' => $money,
            // Cancellation refund
            'customer_refund_amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'refund_method' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'Describe what changed (for example the new flight time or date).',
            'reason.required' => 'Enter the reason for the cancellation.',
        ];
    }
}
