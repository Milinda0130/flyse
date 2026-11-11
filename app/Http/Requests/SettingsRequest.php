<?php

namespace App\Http\Requests;

class SettingsRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_array($this->currencies)) {
            $this->merge(['currencies' => array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $this->currencies)))]);
        }
        if (is_string($this->default_currency)) {
            $this->merge(['default_currency' => strtoupper($this->default_currency)]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('manage-settings') ?? false;
    }

    public function rules(): array
    {
        return [
            'agency_name' => ['sometimes', 'string', 'max:255'],
            'agency_address' => ['nullable', 'string', 'max:500'],
            'agency_phone' => ['nullable', 'string', 'max:40'],
            'agency_email' => ['nullable', 'email', 'max:255'],
            'agency_website' => ['nullable', 'url:http,https', 'max:255'],
            'agency_logo_url' => ['nullable', 'url:https', 'max:500'],
            'currencies' => ['sometimes', 'array', 'min:1', 'max:30'],
            'currencies.*' => ['string', 'size:3', 'alpha'],
            'default_currency' => ['sometimes', 'string', 'size:3', 'in:'.implode(',', $this->input('currencies', $this->currencies()))],
            'payment_methods' => ['sometimes', 'array', 'min:1', 'max:30'],
            'payment_methods.*' => ['string', 'max:40'],
            'expense_categories' => ['sometimes', 'array', 'min:1', 'max:50'],
            'expense_categories.*' => ['string', 'max:60'],
        ];
    }
}
