<?php

namespace App\Http\Requests;

use App\Enums\BaggageStatus;
use App\Enums\CabinClass;
use App\Enums\Refundability;
use App\Enums\SegmentDirection;
use App\Enums\TripType;
use App\Enums\YesNoUnknown;
use App\Rules\ReasonableDate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A quotation with its options and segments, saved as one document. Prices are inputs only:
 * totals are always calculated on the server. A Draft may be incomplete; completeness is checked
 * when it is marked Ready, turned into a PDF or emailed.
 */
class QuotationRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $up = fn ($v) => is_string($v) ? strtoupper(trim($v)) : $v;
        $data = ['origin_code' => $up($this->input('origin_code')), 'destination_code' => $up($this->input('destination_code')), 'currency' => $up($this->input('currency'))];
        if ($this->input('trip_type') === TripType::OneWay->value) {
            $data['return_date'] = null;
        }
        $options = $this->input('options');
        if (is_array($options)) {
            foreach ($options as $i => $option) {
                if (! is_array($option)) {
                    continue;
                }
                $options[$i]['currency'] = $up($option['currency'] ?? null) ?: $data['currency'];
                foreach ($option['segments'] ?? [] as $j => $segment) {
                    if (! is_array($segment)) {
                        continue;
                    }
                    foreach (['from_code', 'to_code', 'flight_number'] as $field) {
                        $options[$i]['segments'][$j][$field] = $up($segment[$field] ?? null);
                    }
                }
            }
            $data['options'] = $options;
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:99999999'];
        $text = fn (int $max) => ['nullable', 'string', 'max:'.$max];
        $currencies = Rule::in($this->currencies());
        $code = ['nullable', 'string', 'regex:/^[A-Z]{3}$/'];

        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'trip_type' => ['required', Rule::enum(TripType::class)],
            'origin_country' => $text(80),
            'origin_city' => ['required', 'string', 'max:120'],
            'origin_code' => $code,
            'destination_country' => $text(80),
            'destination_city' => ['required', 'string', 'max:120'],
            'destination_code' => $code,
            'outbound_date' => ['required', 'date', new ReasonableDate],
            'return_date' => ['nullable', 'required_if:trip_type,round_trip', 'date', new ReasonableDate, 'after_or_equal:outbound_date'],
            'adults' => ['required', 'integer', 'min:1', 'max:9'],
            'children' => ['nullable', 'integer', 'min:0', 'max:9'],
            'infants' => ['nullable', 'integer', 'min:0', 'max:9', 'lte:adults'],
            'cabin_class' => ['required', Rule::enum(CabinClass::class)],
            'currency' => ['required', $currencies],
            'valid_until' => ['nullable', 'date', new ReasonableDate],
            'requested_airline' => $text(120),
            'preferred_departure_time' => $text(60),
            'max_stops' => ['nullable', 'integer', 'min:0', 'max:4'],
            'requested_baggage' => $text(120),
            'customer_requirements' => $text(2000),
            'message_to_customer' => $text(2000),
            'internal_notes' => $text(2000),

            'options' => ['sometimes', 'array', 'max:10'],
            'options.*.label' => ['required', 'string', 'max:60'],
            'options.*.fare_family' => $text(80),
            'options.*.cabin_class' => ['nullable', Rule::enum(CabinClass::class)],
            'options.*.currency' => ['required', $currencies],
            'options.*.adult_fare' => $money,
            'options.*.adult_tax' => $money,
            'options.*.child_fare' => $money,
            'options.*.child_tax' => $money,
            'options.*.infant_fare' => $money,
            'options.*.infant_tax' => $money,
            'options.*.other_charges' => $money,
            'options.*.supplier_cost' => $money,
            'options.*.checked_bag_status' => ['nullable', Rule::enum(BaggageStatus::class)],
            'options.*.checked_bag_allowance' => $text(60),
            'options.*.cabin_bag_status' => ['nullable', Rule::enum(BaggageStatus::class)],
            'options.*.cabin_bag_allowance' => $text(60),
            'options.*.personal_item_status' => ['nullable', Rule::enum(BaggageStatus::class)],
            'options.*.personal_item_allowance' => $text(60),
            'options.*.baggage_notes' => $text(500),
            'options.*.refundability' => ['nullable', Rule::enum(Refundability::class)],
            'options.*.change_permitted' => ['nullable', Rule::enum(YesNoUnknown::class)],
            'options.*.change_fee' => $money,
            'options.*.fare_difference' => ['nullable', Rule::enum(YesNoUnknown::class)],
            'options.*.cancellation_conditions' => $text(1000),
            'options.*.no_show_conditions' => $text(1000),
            'options.*.other_conditions' => $text(1000),
            'options.*.extras' => ['nullable', 'array', 'max:5'],
            'options.*.extras.*.title' => ['required', 'string', 'max:80'],
            'options.*.extras.*.amount' => $money,
            'options.*.extras.*.details' => $text(600),
            'options.*.price_valid_until' => ['nullable', 'date', new ReasonableDate],
            'options.*.notes' => $text(1000),
            'options.*.internal_notes' => $text(1000),

            'options.*.segments' => ['nullable', 'array', 'max:16'],
            'options.*.segments.*.direction' => ['required', Rule::enum(SegmentDirection::class)],
            'options.*.segments.*.airline_id' => ['nullable', 'integer', Rule::exists('airlines', 'id')],
            'options.*.segments.*.airline_name' => ['required_without:options.*.segments.*.airline_id', 'nullable', 'string', 'max:120'],
            'options.*.segments.*.operating_airline_id' => ['nullable', 'integer', Rule::exists('airlines', 'id')],
            'options.*.segments.*.operating_airline_name' => $text(120),
            'options.*.segments.*.flight_number' => ['required', 'string', 'regex:/^[A-Z0-9]{2}\s?\d{1,4}[A-Z]?$/'],
            'options.*.segments.*.from_code' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'options.*.segments.*.from_name' => $text(120),
            'options.*.segments.*.to_code' => ['required', 'string', 'regex:/^[A-Z]{3}$/', 'different:options.*.segments.*.from_code'],
            'options.*.segments.*.to_name' => $text(120),
            'options.*.segments.*.departure_at' => ['required', 'date', new ReasonableDate],
            'options.*.segments.*.arrival_at' => ['nullable', 'date', new ReasonableDate],
            'options.*.segments.*.departure_tz' => ['nullable', 'timezone:all'],
            'options.*.segments.*.arrival_tz' => ['nullable', 'timezone:all'],
            'options.*.segments.*.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:2400'],
            'options.*.segments.*.departure_terminal' => $text(20),
            'options.*.segments.*.arrival_terminal' => $text(20),
            'options.*.segments.*.aircraft' => $text(60),
            'options.*.segments.*.cabin_class' => ['nullable', Rule::enum(CabinClass::class)],
            'options.*.segments.*.baggage_note' => $text(120),
            'options.*.segments.*.notes' => $text(500),
        ];
    }

    public function messages(): array
    {
        return [
            'return_date.required_if' => 'Enter the return date for a return trip.',
            'options.*.segments.*.flight_number.regex' => 'Use the airline code and number, e.g. EK 651.',
            'options.*.segments.*.from_code.regex' => 'Use the 3-letter airport code.',
            'options.*.segments.*.to_code.regex' => 'Use the 3-letter airport code.',
            'options.*.segments.*.to_code.different' => 'Arrival and departure airports must differ.',
            'options.*.segments.*.airline_name.required_without' => 'Choose or type the airline.',
            'infants.lte' => 'Each infant must travel with an adult.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('trip_type') !== TripType::OneWay->value) {
                return;
            }
            foreach ((array) $this->input('options', []) as $i => $option) {
                foreach ((array) ($option['segments'] ?? []) as $j => $segment) {
                    if (($segment['direction'] ?? null) === SegmentDirection::Return->value) {
                        $validator->errors()->add("options.$i.segments.$j.direction", 'A one-way quotation has no return flights.');
                    }
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'options.*.label' => 'option name',
            'options.*.segments.*.flight_number' => 'flight number',
            'options.*.segments.*.departure_at' => 'departure time',
            'options.*.segments.*.arrival_at' => 'arrival time',
            'options.*.segments.*.from_code' => 'departure airport',
            'options.*.segments.*.to_code' => 'arrival airport',
        ];
    }
}
