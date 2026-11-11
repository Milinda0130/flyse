<?php

namespace App\Http\Requests;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Rules\ReasonableDate;
use Illuminate\Validation\Rule;

class FlightRequest extends ApiRequest
{
    public static function fieldRules(string $prefix = '', bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            $prefix.'segment_order' => ['sometimes', 'integer', 'min:1', 'max:99'],
            $prefix.'airline' => [$required, 'string', 'max:100'],
            $prefix.'flight_number' => [$required, 'string', 'max:12', 'regex:/^[A-Z0-9]{2,3}\s?\d{1,5}[A-Z]?$/'],
            $prefix.'origin_code' => [$required, 'string', 'size:3', 'alpha'],
            $prefix.'destination_code' => [$required, 'string', 'size:3', 'alpha', 'different:'.$prefix.'origin_code'],
            $prefix.'departure_at' => [$required, 'date', new ReasonableDate],
            $prefix.'arrival_at' => ['nullable', 'date', new ReasonableDate],
            $prefix.'origin_timezone' => ['nullable', 'timezone:all'],
            $prefix.'destination_timezone' => ['nullable', 'timezone:all'],
            $prefix.'cabin_class' => ['sometimes', Rule::enum(CabinClass::class)],
            $prefix.'baggage_allowance' => ['nullable', 'string', 'max:100'],
            $prefix.'status' => ['sometimes', Rule::enum(FlightStatus::class)],
            $prefix.'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Normalises one flight payload (codes uppercased). */
    public static function normalise(array $flight): array
    {
        foreach (['flight_number', 'origin_code', 'destination_code'] as $key) {
            if (isset($flight[$key]) && is_string($flight[$key])) {
                $flight[$key] = strtoupper(trim($flight[$key]));
            }
        }

        return $flight;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(self::normalise($this->all()));
    }

    public function rules(): array
    {
        return self::fieldRules('', $this->isMethod('post'));
    }
}
