<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentUploadRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Booking;
use App\Models\Document;
use App\Services\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private DocumentStorageService $storage) {}

    public function store(DocumentUploadRequest $request, Booking $booking): JsonResponse
    {
        $stored = [];
        // Alternative ticket PDFs start "pending verification" unless the uploader confirms they reviewed it.
        $extra = [];
        if ($request->input('document_type') === DocumentType::AlternativeTicket->value) {
            $reviewed = $request->boolean('reviewed') && $request->user()->can('verify-documents');
            $extra = [
                'source_type' => $request->input('source_type'),
                'source_description' => $request->input('source_description'),
                'verification_status' => $reviewed ? VerificationStatus::Verified : VerificationStatus::Pending,
                'verified_by' => $reviewed ? $request->user()->id : null,
                'verified_at' => $reviewed ? now() : null,
            ];
        }
        try {
            DB::transaction(function () use ($request, $booking, $extra, &$stored) {
                foreach ($request->file('files') as $file) {
                    $stored[] = $this->storage->store($file, [
                        'booking_id' => $booking->id,
                        'passenger_id' => $request->input('passenger_id'),
                        'issue_id' => $request->input('issue_id'),
                        'booking_flight_id' => $request->input('booking_flight_id'),
                        'notes' => $request->input('notes'),
                        'document_type' => $request->input('document_type'),
                        ...$extra,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Do not leave orphaned files behind if the database write failed.
            foreach ($stored as $document) {
                Storage::disk(DocumentStorageService::DISK)->delete($document->storage_key);
            }
            throw $e;
        }

        return DocumentResource::collection(collect($stored)->each->load(['uploader:id,name', 'verifier:id,name', 'linkedTickets:id,ticket_number,e_ticket_document_id']))
            ->response()->setStatusCode(201);
    }

    /**
     * Marks an alternative ticket PDF as verified or rejected after a person reviewed it.
     * Official agency documents have no verification step (they are not changed here).
     */
    public function verify(Request $request, Document $document): DocumentResource
    {
        Gate::authorize('verify-documents');
        abort_unless($document->isAlternativeTicket(), 422, 'Only alternative ticket PDFs need verification.');
        $data = $request->validate([
            'status' => ['required', 'in:verified,rejected'],
            'note' => ['nullable', 'string', 'max:500', 'required_if:status,rejected'],
        ], ['note.required_if' => 'Give a short reason for rejecting the document.']);

        $document->update([
            'verification_status' => $data['status'],
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'verification_note' => $data['note'] ?? null,
        ]);

        return new DocumentResource($document->load(['uploader:id,name', 'verifier:id,name', 'linkedTickets:id,ticket_number,e_ticket_document_id']));
    }

    /** Streams a private document after checking the user may view it. */
    public function download(Request $request, Document $document): StreamedResponse
    {
        // Expense receipts are only for users who manage expenses.
        if ($document->booking_id === null) {
            Gate::authorize('manage-expenses');
        }

        return $this->storage->stream($document, $request->boolean('inline'));
    }

    /** Archives the attachment (soft delete); the file is kept for recovery and the action is audited. */
    public function destroy(Document $document): JsonResponse
    {
        Gate::authorize('delete-documents');
        // An issued ticket must always keep its e-ticket: replace it on the ticket before archiving.
        $inUse = $document->linkedTickets()->whereIn('status', ['issued', 'changed', 'used'])->pluck('ticket_number');
        abort_if($inUse->isNotEmpty(), 422, 'This e-ticket belongs to ticket(s) '.$inUse->join(', ').'. Attach a replacement e-ticket to those tickets before archiving it.');
        $document->delete();

        return response()->json(null, 204);
    }
}
