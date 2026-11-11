<?php

namespace App\Http\Requests;

use App\Enums\IssueStatus;
use Illuminate\Validation\Rule;

class IssueUpdateRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'update_text' => ['required', 'string', 'max:10000'],
            'status' => ['nullable', Rule::enum(IssueStatus::class)],
        ];
    }
}
