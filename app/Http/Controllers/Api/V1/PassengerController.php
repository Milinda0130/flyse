<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PassengerRequest;
use App\Http\Resources\PassengerResource;
use App\Models\Booking;
use App\Models\Passenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PassengerController extends Controller
{
    public function store(PassengerRequest $request, Booking $booking): JsonResponse
    {
        $passenger = $booking->passengers()->create($request->validated());

        return (new PassengerResource($passenger))->response()->setStatusCode(201);
    }

    public function update(PassengerRequest $request, Passenger $passenger): PassengerResource
    {
        $passenger->update($request->validated());

        return new PassengerResource($passenger);
    }

    public function destroy(Passenger $passenger): JsonResponse
    {
        Gate::authorize('edit-records');
        abort_if($passenger->tickets()->exists(), 422, 'Remove or reassign this passenger\'s tickets first.');
        $passenger->delete();

        return response()->json(null, 204);
    }
}
