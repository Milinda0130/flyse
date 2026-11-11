<?php

namespace App\Http\Requests;

use App\Enums\PassengerType;
use App\Rules\ReasonableDate;
use Illuminate\Validation\Rule;

class PassengerRequest extends ApiRequest
{
    public static function fieldRules(string $prefix = '', bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            $prefix.'full_name_as_document' => [$required, 'string', 'max:255'],
            $prefix.'passenger_type' => ['sometimes', Rule::enum(PassengerType::class)],
            $prefix.'date_of_birth' => ['nullable', 'date', new ReasonableDate, 'before_or_equal:today'],
            $prefix.'nationality' => ['nullable', 'string', 'max:80'],
            $prefix.'passport_number' => ['nullable', 'string', 'max:30'],
            $prefix.'passport_expiry' => ['nullable', 'date', new ReasonableDate],
            $prefix.'phone' => ['nullable', 'string', 'max:40'],
            $prefix.'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function rules(): array
    {
        return self::fieldRules('', $this->isMethod('post'));
    }
}
