<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use App\Models\Booking;
use App\Services\GeneratedTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The agency's own ticket PDF, built from the booking records while the official e-ticket is not available. */
class GeneratedTicketController extends Controller
{
    public function __construct(private GeneratedTicketService $service) {}

    /** Shows the PDF for one passenger without saving it. */
    public function preview(Request $request, Booking $booking): Response
    {
        Gate::authorize('email-tickets');
        $data = $request->validate(['passenger_id' => ['required', 'integer', Rule::exists('passengers', 'id')->where('booking_id', $booking->id)]]);
        $codes = $this->service->codes($request->only(['reservation_code', 'airline_pnr']));
        if ($problems = $this->service->problems($booking)) {
            throw ValidationException::withMessages(['booking' => $problems]);
        }
        $passenger = $booking->passengers->firstWhere('id', (int) $data['passenger_id']);

        return response($this->service->render($booking, $passenger, $codes), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Saves one PDF per passenger as alternative ticket documents, pending verification. */
    public function store(Request $request, Booking $booking): JsonResponse
    {
        Gate::authorize('email-tickets');
        $data = $request->validate([
            'passenger_ids' => ['nullable', 'array', 'min:1', 'max:20'],
            'passenger_ids.*' => ['integer', 'distinct'],
        ]);
        $codes = $this->service->codes($request->only(['reservation_code', 'airline_pnr']));
        $documents = $this->service->generate($booking, $data['passenger_ids'] ?? null, $codes, $request->user());

        return DocumentResource::collection(collect($documents)->each->load(['uploader:id,name', 'verifier:id,name']))
            ->response()->setStatusCode(201);
    }
}
