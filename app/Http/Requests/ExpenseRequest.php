<?php

namespace App\Http\Requests;

use App\Rules\ReasonableDate;
use Illuminate\Validation\Rule;

class ExpenseRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->upper(['currency']);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('manage-expenses') ?? false;
    }

    public function rules(): array
    {
        $required = $this->route('expense') ? 'sometimes' : 'required';

        return [
            'expense_date' => [$required, 'date', new ReasonableDate],
            'category' => [$required, 'string', 'max:60'],
            'description' => [$required, 'string', 'max:255'],
            'amount' => [$required, 'numeric', 'gt:0', 'max:999999999999.99', 'decimal:0,2'],
            'currency' => [$required, Rule::in($this->currencies())],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'receipt' => ['nullable', 'file', 'max:'.DocumentUploadRequest::MAX_FILE_KB, 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ];
    }
}
