<?php

namespace Tests\Feature;

use App\Models\BookingIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueTest extends TestCase
{
    use RefreshDatabase;

    public function test_airline_cancellation_workflow_tracks_history(): void
    {
        $user = $this->actingAsRole('staff');
        $booking = $this->createBooking();
        $flightId = $booking->flights()->first()->id;

        $issue = $this->postJson("/api/v1/bookings/{$booking->id}/issues", [
            'issue_type' => 'airline_cancellation', 'source' => 'airline', 'severity' => 'high',
            'summary' => 'QR655 cancelled', 'booking_flight_id' => $flightId, 'due_at' => now()->subHour()->toIso8601String(),
            'assigned_to' => $user->id,
        ])->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('is_overdue', true)->assertJsonCount(1, 'updates')->json();

        $this->getJson('/api/v1/issues?overdue=1')->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/issues/{$issue['id']}/updates", ['update_text' => 'Called airline', 'status' => 'waiting_airline'])
            ->assertCreated()->assertJsonPath('status_from', 'open')->assertJsonPath('status_to', 'waiting_airline');

        $this->patchJson("/api/v1/issues/{$issue['id']}", ['status' => 'resolved', 'customer_notified' => true, 'resolution_notes' => 'Rebooked on QR657'])
            ->assertOk()
            ->assertJsonPath('status', 'resolved')
            ->assertJsonPath('is_overdue', false)
            ->assertJsonCount(3, 'updates');

        $this->assertNotNull(BookingIssue::find($issue['id'])->resolved_at);
        $this->getJson('/api/v1/issues?open_only=1')->assertJsonCount(0, 'data');

        $this->patchJson("/api/v1/issues/{$issue['id']}", ['status' => 'open'])->assertOk();
        $this->assertNull(BookingIssue::find($issue['id'])->resolved_at);
    }

    public function test_issue_flight_must_belong_to_booking(): void
    {
        $this->actingAsRole('owner');
        $a = $this->createBooking();
        $b = $this->createBooking();

        $this->postJson("/api/v1/bookings/{$a->id}/issues", [
            'issue_type' => 'delay', 'source' => 'airline', 'summary' => 'x', 'booking_flight_id' => $b->flights()->first()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('booking_flight_id');
    }
}
