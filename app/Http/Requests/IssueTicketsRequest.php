<?php

namespace App\Http\Requests;

use App\Rules\ReasonableDate;
use Illuminate\Validation\Rule;

/**
 * Record issued tickets together with their e-ticket document.
 * The e-ticket is either uploaded now or chosen from this booking's existing e-ticket documents.
 * One document may cover several passengers' tickets.
 */
class IssueTicketsRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_array($this->tickets)) {
            $this->merge(['tickets' => array_map(function ($t) {
                if (! is_array($t)) {
                    return $t;
                }
                if (isset($t['ticket_number']) && is_string($t['ticket_number'])) {
                    $t['ticket_number'] = preg_replace('/\s+/', '', $t['ticket_number']);
                }
                if (isset($t['airline_pnr']) && is_string($t['airline_pnr'])) {
                    $t['airline_pnr'] = strtoupper(trim($t['airline_pnr'])) ?: null;
                }

                return $t;
            }, $this->tickets)]);
        }
    }

    public function rules(): array
    {
        $bookingId = $this->route('booking')->id;

        return [
            'tickets' => ['required', 'array', 'min:1', 'max:50'],
            'tickets.*.passenger_id' => ['required', 'distinct', Rule::exists('passengers', 'id')->where('booking_id', $bookingId)],
            'tickets.*.ticket_number' => ['required', 'string', 'max:30'],
            'tickets.*.airline_pnr' => ['nullable', 'string', 'max:20', 'alpha_num'],
            'tickets.*.booking_flight_id' => ['nullable', Rule::exists('booking_flights', 'id')->where('booking_id', $bookingId)],
            'tickets.*.issued_at' => ['nullable', 'date', new ReasonableDate],
            'tickets.*.fare_conditions' => ['nullable', 'string', 'max:5000'],
            'e_ticket' => [
                'required_without:document_id', 'nullable', 'file', 'max:'.DocumentUploadRequest::MAX_FILE_KB,
                'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png',
            ],
            'document_id' => ['nullable', Rule::exists('documents', 'id')->where('booking_id', $bookingId)->where('document_type', 'e_ticket')->whereNull('deleted_at')],
            'mark_ticketed' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tickets.*.ticket_number' => 'ticket number',
            'tickets.*.passenger_id' => 'passenger',
            'tickets.*.airline_pnr' => 'airline PNR',
            'e_ticket' => 'e-ticket file',
        ];
    }

    public function messages(): array
    {
        return ['e_ticket.required_without' => 'Upload the issued e-ticket or choose one already attached to this booking.'];
    }
}
