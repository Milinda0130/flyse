<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlightRequest;
use App\Http\Resources\FlightResource;
use App\Models\Booking;
use App\Models\BookingFlight;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Segment edits are audited, so the previous itinerary stays visible in the booking activity. */
class BookingFlightController extends Controller
{
    public function store(FlightRequest $request, Booking $booking): JsonResponse
    {
        $data = $request->validated();
        $data['segment_order'] ??= ($booking->flights()->max('segment_order') ?? 0) + 1;
        $flight = $booking->flights()->create($data);

        return (new FlightResource($flight))->response()->setStatusCode(201);
    }

    public function update(FlightRequest $request, BookingFlight $bookingFlight): FlightResource
    {
        $bookingFlight->update($request->validated());

        return new FlightResource($bookingFlight);
    }

    public function destroy(BookingFlight $bookingFlight): JsonResponse
    {
        Gate::authorize('edit-records');
        $bookingFlight->delete();

        return response()->json(null, 204);
    }
}
