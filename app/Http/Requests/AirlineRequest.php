<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AirlineRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-airlines') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->upper(['iata_code', 'icao_code']);
    }

    public function rules(): array
    {
        $id = $this->route('airline')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'iata_code' => ['nullable', 'string', 'regex:/^[A-Z0-9]{2}$/', Rule::unique('airlines', 'iata_code')->ignore($id)],
            'icao_code' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/', Rule::unique('airlines', 'icao_code')->ignore($id)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'iata_code.regex' => 'The IATA airline code has 2 characters, e.g. EK.',
            'icao_code.regex' => 'The ICAO airline code has 3 letters, e.g. UAE.',
            'iata_code.unique' => 'Another airline already uses this IATA code.',
            'icao_code.unique' => 'Another airline already uses this ICAO code.',
        ];
    }
}
