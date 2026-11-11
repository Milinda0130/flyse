<?php

namespace App\Http\Requests;

use App\Enums\PaymentStatus;
use App\Rules\ReasonableDate;
use Illuminate\Validation\Rule;

class UpdatePaymentRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-payments') ?? false;
    }

    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'method' => ['sometimes', 'string', 'max:40'],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['sometimes', 'date', new ReasonableDate],
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
