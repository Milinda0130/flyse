<?php

namespace App\Http\Requests;

use App\Rules\ReasonableDate;

class CustomerRequest extends ApiRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'full_name' => [$required, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', new ReasonableDate, 'before:today'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'passport_number' => ['nullable', 'string', 'max:30'],
            'passport_expiry' => ['nullable', 'date', new ReasonableDate],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
