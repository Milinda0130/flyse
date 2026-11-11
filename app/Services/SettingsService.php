<?php

namespace App\Services;

use App\Models\Setting;

class SettingsService
{
    public const DEFAULTS = [
        'agency_name' => 'FLYSE TRAVELS AB',
        'agency_address' => "Lärdomsgatan 9, Göteborg, Västra Götaland County\n417 56, Sweden",
        'agency_phone' => '+46735265341',
        'agency_email' => 'geethnsl@gmail.com',
        'agency_website' => '',
        // Public https URL of the agency logo for customer emails (optional).
        'agency_logo_url' => '',
        // Private storage key of the uploaded logo for ticket PDFs (set only through the logo upload).
        'agency_logo_file' => '',
        'default_currency' => 'LKR',
        'currencies' => ['LKR', 'USD', 'EUR', 'SEK', 'GBP'],
        'payment_methods' => ['Cash', 'Bank transfer', 'Card', 'Cheque', 'Online'],
        'expense_categories' => ['Rent', 'Salaries', 'Utilities', 'Bank charges', 'Marketing', 'Office supplies', 'Other'],
    ];

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = Setting::query()->pluck('value', 'key')->all();

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values): array
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            $setting = Setting::find($key) ?? new Setting(['key' => $key]);
            $setting->value = $value;
            $setting->save();
        }

        return $this->all();
    }
}
