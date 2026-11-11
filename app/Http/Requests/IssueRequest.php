<?php

namespace App\Http\Requests;

use App\Enums\IssueSeverity;
use App\Enums\IssueSource;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Rules\ReasonableDate;
use Illuminate\Validation\Rule;

class IssueRequest extends ApiRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';
        $bookingId = $this->route('booking')?->id ?? $this->route('issue')?->booking_id;

        return [
            'issue_type' => [$required, Rule::enum(IssueType::class)],
            'source' => [$required, Rule::enum(IssueSource::class)],
            'severity' => ['sometimes', Rule::enum(IssueSeverity::class)],
            'status' => ['sometimes', Rule::enum(IssueStatus::class)],
            'summary' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'next_action' => ['nullable', 'string', 'max:255'],
            'reported_at' => ['sometimes', 'date', new ReasonableDate],
            'due_at' => ['nullable', 'date', new ReasonableDate],
            'customer_notified' => ['sometimes', 'boolean'],
            'resolution_notes' => ['nullable', 'string', 'max:10000'],
            'refund_reference' => ['nullable', 'string', 'max:255'],
            'booking_flight_id' => ['nullable', Rule::exists('booking_flights', 'id')->where('booking_id', $bookingId)],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where('is_active', true)],
            'update_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
