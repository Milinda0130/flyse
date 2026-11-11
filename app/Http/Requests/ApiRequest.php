<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;

abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit-records') ?? false;
    }

    /** @return list<string> */
    protected function currencies(): array
    {
        return app(SettingsService::class)->get('currencies');
    }

    /** Uppercases IATA codes / identifiers before validation. */
    protected function upper(array $keys): void
    {
        $data = [];
        foreach ($keys as $key) {
            if (is_string($this->input($key))) {
                $data[$key] = strtoupper(trim($this->input($key)));
            }
        }
        $this->merge($data);
    }
}
