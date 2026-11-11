<?php

namespace App\Http\Requests;

use App\Enums\CabinClass;
use App\Enums\TripType;
use App\Rules\ReasonableDate;
use App\Services\BookingWorkflowService;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->upper(['pnr', 'currency']);
        foreach (['origin', 'destination'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
        $this->deriveTripFromItinerary();
        if (is_array($this->flights)) {
            $this->merge(['flights' => array_map(fn ($f) => is_array($f) ? FlightRequest::normalise($f) : $f, $this->flights)]);
        }
    }

    /**
     * When a full itinerary is sent instead of a requested trip (older clients, imports),
     * fill the trip summary from the passengers and flight segments.
     */
    private function deriveTripFromItinerary(): void
    {
        $flights = is_array($this->flights) ? array_values(array_filter($this->flights, 'is_array')) : [];
        $passengers = is_array($this->passengers) ? count($this->passengers) : 0;
        $derived = [];
        if (! $this->filled('passenger_count') && $passengers > 0) {
            $derived['passenger_count'] = $passengers;
        }
        if ($flights) {
            $first = $flights[0];
            $last = $flights[count($flights) - 1];
            $derived += array_filter([
                'origin' => $this->filled('origin') ? null : ($first['origin_code'] ?? null),
                'destination' => $this->filled('destination') ? null : ($last['destination_code'] ?? null),
                'departure_date' => $this->filled('departure_date') || empty($first['departure_at']) ? null : substr((string) $first['departure_at'], 0, 10),
                'cabin_class' => $this->filled('cabin_class') ? null : ($first['cabin_class'] ?? null),
            ]);
        }
        if (! $this->filled('trip_type')) {
            $derived['trip_type'] = $this->filled('return_date') ? 'round_trip' : 'one_way';
        }
        $this->merge($derived);
    }

    /**
     * Fields shared by create and edit. Only the customer and the requested trip are needed to
     * start an inquiry; reservation, pricing and ticketing details are added later.
     */
    public static function coreRules(bool $creating, array $currencies): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'customer_id' => [$required, Rule::exists('customers', 'id')->whereNull('deleted_at')],
            // Requested trip
            'trip_type' => [$required, Rule::enum(TripType::class)],
            'origin' => [$required, 'string', 'max:60'],
            'destination' => [$required, 'string', 'max:60', 'different:origin'],
            'departure_date' => ['nullable', 'date', new ReasonableDate],
            'return_date' => ['nullable', 'date', new ReasonableDate, 'after_or_equal:departure_date'],
            'cabin_class' => ['nullable', Rule::enum(CabinClass::class)],
            'passenger_count' => [$required, 'integer', 'min:1', 'max:50'],
            // Reservation & pricing (optional until the reservation stage)
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')],
            'pnr' => ['nullable', 'string', 'max:20', 'alpha_num'],
            'booking_date' => ['sometimes', 'date', new ReasonableDate],
            'currency' => ['sometimes', 'string', Rule::in($currencies)],
            'payment_due_date' => ['nullable', 'date', new ReasonableDate],
            'ticketing_deadline' => ['nullable', 'date', new ReasonableDate],
            'selling_total' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'service_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'supplier_total' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'next_action' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function rules(): array
    {
        return [
            ...self::coreRules(true, $this->currencies()),
            // A new booking can start as an inquiry or reservation; Ticketed needs issued tickets first.
            'status' => ['sometimes', Rule::in(BookingWorkflowService::CREATABLE)],
            'passengers' => ['array', 'max:20'],
            ...PassengerRequest::fieldRules('passengers.*.'),
            'flights' => ['array', 'max:20'],
            ...FlightRequest::fieldRules('flights.*.'),
            'initial_payment' => ['nullable', 'array'],
            'initial_payment.amount' => ['required_with:initial_payment', 'numeric', 'gt:0', 'decimal:0,2'],
            'initial_payment.method' => ['required_with:initial_payment', 'string', 'max:40'],
            'initial_payment.transaction_date' => ['required_with:initial_payment', 'date', new ReasonableDate],
            'initial_payment.transaction_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'passengers.*.full_name_as_document' => 'passenger name',
            'origin' => 'departure city or airport',
            'destination' => 'destination',
            'flights.*.airline' => 'airline',
            'flights.*.flight_number' => 'flight number',
            'flights.*.origin_code' => 'origin',
            'flights.*.destination_code' => 'destination',
            'flights.*.departure_at' => 'departure time',
        ];
    }
}
