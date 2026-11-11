<?php

namespace App\Http\Requests;

use App\Models\Booking;

class UpdateBookingRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->upper(['pnr', 'currency']);
    }

    public function rules(): array
    {
        return [
            ...StoreBookingRequest::coreRules(false, $this->currencies()),
            // Status changes have rules of their own: POST /bookings/{id}/status.
            'status' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['status.prohibited' => 'Use "Change status" to change the booking status.'];
    }

    public function after(): array
    {
        return [function ($validator) {
            /** @var Booking $booking */
            $booking = $this->route('booking');
            if ($this->filled('currency') && $this->currency !== $booking->currency && $booking->payments()->exists()) {
                $validator->errors()->add('currency', 'Currency cannot change after payments have been recorded.');
            }
            // Compare with the saved departure date when only the return date is being edited.
            $departure = $this->exists('departure_date') ? $this->input('departure_date') : $booking->departure_date?->format('Y-m-d');
            if ($this->filled('return_date') && $departure && $this->input('return_date') < $departure && ! $validator->errors()->has('return_date')) {
                $validator->errors()->add('return_date', 'The return date must be on or after the departure date.');
            }
            // Same for the route: origin and destination must differ even when only one is edited.
            $origin = $this->exists('origin') ? $this->input('origin') : $booking->origin;
            $destination = $this->exists('destination') ? $this->input('destination') : $booking->destination;
            if ($origin && $destination && strcasecmp($origin, $destination) === 0 && ! $validator->errors()->has('destination')) {
                $validator->errors()->add('destination', 'The destination must be different from the departure city.');
            }
        }];
    }
}
