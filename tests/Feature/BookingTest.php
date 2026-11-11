<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_with_two_passengers_and_segments_is_created_in_one_request(): void
    {
        $this->actingAsRole('staff');

        $response = $this->postJson('/api/v1/bookings', $this->bookingPayload())->assertCreated();

        $year = now()->format('Y');
        $response->assertJsonPath('booking_code', "BK-{$year}-0001")
            ->assertJsonPath('pnr', 'QX7K2M')
            ->assertJsonPath('route', 'CMB-DOH-ARN')
            ->assertJsonCount(2, 'passengers')
            ->assertJsonCount(2, 'flights')
            ->assertJsonPath('flights.0.flight_number', 'QR655')
            ->assertJsonPath('flights.0.departure_at', '2026-11-17T04:05')
            ->assertJsonPath('finance.customer_due', '750000.00')
            ->assertJsonPath('finance.gross_margin', '60000.00');

        $this->postJson('/api/v1/bookings', $this->bookingPayload())->assertCreated()->assertJsonPath('booking_code', "BK-{$year}-0002");
    }

    public function test_invalid_segment_rolls_back_whole_booking(): void
    {
        $this->actingAsRole('owner');
        $payload = $this->bookingPayload(['flights' => [1 => ['destination_code' => 'DOH']]]);

        $this->postJson('/api/v1/bookings', $payload)->assertStatus(422)->assertJsonValidationErrors('flights.1.destination_code');
        $this->assertSame(0, Booking::count());
    }

    public function test_readonly_user_cannot_create_or_edit_bookings(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking();

        $this->actingAsRole('readonly');
        $this->postJson('/api/v1/bookings', $this->bookingPayload())->assertForbidden();
        $this->putJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])->assertForbidden();
        $this->getJson("/api/v1/bookings/{$booking->id}")->assertOk();
    }

    public function test_search_by_pnr_ticket_passenger_phone_and_code(): void
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking();
        $passengerId = $booking->passengers()->first()->id;
        Storage::fake('documents');
        $this->post("/api/v1/bookings/{$booking->id}/tickets", [
            'tickets' => [['passenger_id' => $passengerId, 'ticket_number' => '157 2100000001']],
            'e_ticket' => $this->eTicketPdf(),
        ], ['Accept' => 'application/json'])->assertCreated();
        $other = $this->createBooking(['pnr' => 'ZZZ111', 'passengers' => [['full_name_as_document' => 'OTHER PERSON'], ['full_name_as_document' => 'SECOND']]]);
        Customer::find($other->customer_id)->update(['phone' => '+46 70 000 0000', 'full_name' => 'Another Customer']);

        foreach (['QX7K2M', 'qx7k', '1572100000001', 'nirosha', '0771234567', '77 123', $booking->booking_code] as $term) {
            $this->getJson('/api/v1/bookings?q='.urlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $booking->id);
        }
    }

    public function test_duplicate_pnr_search_returns_all_matches(): void
    {
        $this->actingAsRole('owner');
        $this->createBooking(['pnr' => 'ABC123']);
        $this->createBooking(['pnr' => 'ABC123']);

        $this->getJson('/api/v1/bookings?q=ABC123')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filters_by_status_and_departure_range(): void
    {
        $this->actingAsRole('owner');
        $this->createBooking(['status' => 'changed']);
        $this->createBooking(['status' => 'confirmed', 'flights' => [
            ['departure_at' => '2027-03-01T10:00', 'arrival_at' => '2027-03-01T12:00'],
            ['departure_at' => '2027-03-01T14:00', 'arrival_at' => '2027-03-01T18:00'],
        ]]);

        $this->getJson('/api/v1/bookings?status=changed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'changed');
        $this->getJson('/api/v1/bookings?departure_from=2026-10-01&departure_to=2026-12-31')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/bookings?departure_from=2026-12-01&departure_to=2026-10-01')->assertStatus(422);
        $this->getJson('/api/v1/bookings?sort=password')->assertStatus(422);
    }

    public function test_status_change_is_audited_with_before_and_after(): void
    {
        $user = $this->actingAsRole('staff');
        $booking = $this->createBooking();

        // Status cannot be changed through a plain edit; it has its own workflow endpoint.
        $this->putJson("/api/v1/bookings/{$booking->id}", ['status' => 'cancelled'])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->postJson("/api/v1/bookings/{$booking->id}/status", ['status' => 'cancelled', 'reason' => 'Customer changed plans', 'next_action' => 'Await refund'])->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('cancellation_reason', 'Customer changed plans');

        $log = AuditLog::where('entity_type', 'Booking')->where('action', 'status_changed')->latest('id')->first();
        $this->assertSame('confirmed', $log->before_json['status']);
        $this->assertSame('cancelled', $log->after_json['status']);
        $this->assertSame('Customer changed plans', $log->after_json['reason']);
        $this->assertSame($user->id, $log->user_id);

        $this->getJson("/api/v1/bookings/{$booking->id}/activity")->assertOk()->assertJsonPath('data.0.action', 'status_changed');
        // Cancelled bookings remain searchable.
        $this->getJson('/api/v1/bookings?status=cancelled')->assertJsonCount(1, 'data');
    }

    public function test_segments_passengers_and_tickets_can_be_managed(): void
    {
        $this->actingAsRole('staff');
        $booking = $this->createBooking();

        $flight = $this->postJson("/api/v1/bookings/{$booking->id}/flights", [
            'airline' => 'Qatar Airways', 'flight_number' => 'QR168', 'origin_code' => 'ARN', 'destination_code' => 'DOH', 'departure_at' => '2026-12-01T15:00',
        ])->assertCreated()->assertJsonPath('segment_order', 3)->json();
        $this->putJson("/api/v1/booking-flights/{$flight['id']}", ['status' => 'delayed', 'departure_at' => '2026-12-01T17:00'])
            ->assertOk()->assertJsonPath('status', 'delayed');

        $passenger = $this->postJson("/api/v1/bookings/{$booking->id}/passengers", ['full_name_as_document' => 'CHILD PERERA', 'passenger_type' => 'child'])
            ->assertCreated()->json();

        // A ticket cannot reference another booking's passenger.
        Storage::fake('documents');
        $other = $this->createBooking();
        $this->post("/api/v1/bookings/{$other->id}/tickets", ['tickets' => [['passenger_id' => $passenger['id'], 'ticket_number' => '1']], 'e_ticket' => $this->eTicketPdf()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('tickets.0.passenger_id');

        $this->post("/api/v1/bookings/{$booking->id}/tickets", ['tickets' => [['passenger_id' => $passenger['id'], 'ticket_number' => '1572100000009']], 'e_ticket' => $this->eTicketPdf()], ['Accept' => 'application/json'])->assertCreated();
        $this->deleteJson("/api/v1/passengers/{$passenger['id']}")->assertStatus(422);
    }
}
