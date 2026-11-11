<?php

namespace App\Http\Requests;

use App\Enums\DocumentSource;
use App\Enums\DocumentType;
use Illuminate\Validation\Rule;

class DocumentUploadRequest extends ApiRequest
{
    /** Per-file limit in kilobytes (10 MB) and per-request total in bytes (30 MB). */
    public const MAX_FILE_KB = 10240;

    public const MAX_TOTAL_BYTES = 30 * 1024 * 1024;

    public function rules(): array
    {
        $bookingId = $this->route('booking')?->id;

        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => [
                'required', 'file', 'max:'.self::MAX_FILE_KB,
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
            ],
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'passenger_id' => ['nullable', Rule::exists('passengers', 'id')->where('booking_id', $bookingId)],
            'issue_id' => ['nullable', Rule::exists('booking_issues', 'id')->where('booking_id', $bookingId)],
            'booking_flight_id' => ['nullable', Rule::exists('booking_flights', 'id')->where('booking_id', $bookingId)],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Alternative ticket PDFs: where it came from (internal), and whether the uploader reviewed it.
            'source_type' => ['nullable', 'required_if:document_type,alternative_ticket', 'prohibited_unless:document_type,alternative_ticket', Rule::enum(DocumentSource::class)->except(DocumentSource::AgencyGenerated)],
            'source_description' => ['nullable', 'string', 'max:255', 'required_if:source_type,other_authorized', 'prohibited_unless:document_type,alternative_ticket'],
            'reviewed' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['files.*' => 'file', 'source_type' => 'document source', 'source_description' => 'source description'];
    }

    public function after(): array
    {
        return [function ($validator) {
            $total = array_sum(array_map(fn ($f) => $f->getSize(), $this->file('files', []) ?: []));
            if ($total > self::MAX_TOTAL_BYTES) {
                $validator->errors()->add('files', 'The total upload size may not exceed 30 MB.');
            }
            // A ticket document to email must be a real PDF (checked by content, not the filename).
            if ($this->input('document_type') === DocumentType::AlternativeTicket->value) {
                foreach ($this->file('files', []) ?: [] as $file) {
                    if ($file->getMimeType() !== 'application/pdf') {
                        $validator->errors()->add('files', $file->getClientOriginalName().': an alternative ticket must be a PDF file.');
                    }
                }
            }
        }];
    }
}
