<?php

namespace App\Http\Requests;

use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Rules\ReasonableDate;
use Illuminate\Validation\Rule;

class PaymentRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->upper(['currency']);
        if (! $this->filled('currency') && $this->route('booking')) {
            $this->merge(['currency' => $this->route('booking')->currency]);
        }
    }

    public function authorize(): bool
    {
        $direction = PaymentDirection::tryFrom((string) $this->input('direction'));

        // Unknown directions fall through to validation (422) instead of 403.
        return $direction === null
            ? ($this->user()?->can('edit-records') ?? false)
            : $this->user()->can('record-payment', $direction);
    }

    public function rules(): array
    {
        /** @var Booking $booking */
        $booking = $this->route('booking');

        return [
            'direction' => ['required', Rule::enum(PaymentDirection::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            // Without exchange-rate support, transactions must be in the booking currency.
            'currency' => ['required', Rule::in([$booking->currency])],
            'method' => ['required', 'string', 'max:40'],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'transaction_date' => ['required', 'date', new ReasonableDate, 'before_or_equal:'.now()->addDay()->toDateString()],
            'status' => ['sometimes', Rule::in([PaymentStatus::Completed->value, PaymentStatus::Pending->value])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['currency.in' => 'Transactions must use the booking currency.'];
    }
}
