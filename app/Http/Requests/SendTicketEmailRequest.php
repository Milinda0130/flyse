<?php

namespace App\Http\Requests;

use App\Enums\EmailType;
use Illuminate\Validation\Rule;

/**
 * Selected document ids (never file paths), an optional one-off recipient, an idempotency key
 * from the dialog, and an explicit confirmation when re-sending the same tickets.
 * Alternative ticket PDFs also need the sender's "I reviewed this document" confirmation.
 */
class SendTicketEmailRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('email-tickets') ?? false;
    }

    public function rules(): array
    {
        return [
            'email_type' => ['sometimes', Rule::enum(EmailType::class)],
            'document_ids' => ['required', 'array', 'min:1', 'max:20'],
            'document_ids.*' => ['integer', 'distinct'],
            'recipient' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'confirm_resend' => ['sometimes', 'boolean'],
            'confirm_reviewed' => ['sometimes', 'boolean'],
            // Only when staff know the official ticket is still pending; changes the email wording.
            'official_pending' => ['sometimes', 'boolean'],
        ];
    }

    public function emailType(): EmailType
    {
        return EmailType::tryFrom((string) $this->input('email_type')) ?? EmailType::Official;
    }

    public function messages(): array
    {
        return ['document_ids.required' => 'Select at least one ticket PDF to send.'];
    }
}
