<?php

namespace App\Http\Requests;

use App\Enums\TicketStatus;
use App\Rules\ReasonableDate;
use Illuminate\Validation\Rule;

class TicketRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->ticket_number)) {
            $this->merge(['ticket_number' => preg_replace('/\s+/', '', $this->ticket_number)]);
        }
        $this->upper(['airline_pnr']);
    }

    public function rules(): array
    {
        $bookingId = $this->route('booking')?->id ?? $this->route('ticket')?->booking_id;
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'passenger_id' => [$required, Rule::exists('passengers', 'id')->where('booking_id', $bookingId)],
            'booking_flight_id' => ['nullable', Rule::exists('booking_flights', 'id')->where('booking_id', $bookingId)],
            'ticket_number' => [$required, 'string', 'max:30'],
            'airline_pnr' => ['nullable', 'string', 'max:20', 'alpha_num'],
            'issued_at' => ['nullable', 'date', new ReasonableDate],
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'e_ticket_document_id' => ['nullable', Rule::exists('documents', 'id')->where('booking_id', $bookingId)->where('document_type', 'e_ticket')->whereNull('deleted_at')],
            'fare_conditions' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
