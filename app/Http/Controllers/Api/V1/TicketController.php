<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\DocumentType;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IssueTicketsRequest;
use App\Http\Requests\TicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Ticket;
use App\Services\BookingWorkflowService;
use App\Services\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class TicketController extends Controller
{
    public function __construct(private DocumentStorageService $storage, private BookingWorkflowService $workflow) {}

    /**
     * Issue tickets: records ticket numbers together with the issued e-ticket document.
     * Everything happens in one transaction; if the file cannot be stored, or the optional
     * "mark as Ticketed" step fails, nothing is saved and the uploaded file is removed.
     */
    public function store(IssueTicketsRequest $request, Booking $booking): JsonResponse
    {
        $data = $request->validated();
        $uploaded = null;

        try {
            $tickets = DB::transaction(function () use ($request, $booking, $data, &$uploaded) {
                $document = isset($data['document_id']) ? Document::findOrFail($data['document_id']) : null;
                if ($request->hasFile('e_ticket')) {
                    $uploaded = $document = $this->storage->store($request->file('e_ticket'), [
                        'booking_id' => $booking->id,
                        'document_type' => DocumentType::ETicket,
                        'passenger_id' => count($data['tickets']) === 1 ? $data['tickets'][0]['passenger_id'] : null,
                    ]);
                }
                if (! $document || ! $this->storage->exists($document)) {
                    throw ValidationException::withMessages(['e_ticket' => 'The e-ticket file could not be stored. Nothing was saved; please try again.']);
                }

                $created = [];
                foreach ($data['tickets'] as $row) {
                    $created[] = $booking->tickets()->create([
                        ...$row,
                        'issued_at' => $row['issued_at'] ?? now()->toDateString(),
                        'status' => TicketStatus::Issued,
                        'e_ticket_document_id' => $document->id,
                    ]);
                }

                if ($request->boolean('mark_ticketed') && $booking->status !== BookingStatus::Ticketed) {
                    $this->workflow->transition($booking, BookingStatus::Ticketed, [], $request->user()->id);
                }

                return $created;
            });
        } catch (Throwable $e) {
            // The database rolled back; remove the orphaned file too.
            if ($uploaded) {
                Storage::disk(DocumentStorageService::DISK)->delete($uploaded->storage_key);
            }
            throw $e;
        }

        return response()->json([
            'tickets' => TicketResource::collection(collect($tickets)->each->load('eTicket')),
            'booking_status' => $booking->refresh()->status->value,
        ], 201);
    }

    public function update(TicketRequest $request, Ticket $ticket): TicketResource
    {
        $ticket->update($request->validated());

        return new TicketResource($ticket->load('eTicket'));
    }

    public function destroy(Ticket $ticket): JsonResponse
    {
        Gate::authorize('edit-records');
        $ticket->delete();

        return response()->json(null, 204);
    }
}
