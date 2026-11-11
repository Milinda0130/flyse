<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Out-of-range years (e.g. a mistyped "12003") are a field error, never a database error. */
class DateValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_digit_years_are_rejected_with_a_field_message(): void
    {
        $this->actingAsRole('staff');
        $booking = $this->createBooking(['status' => 'confirmed']);

        $this->postJson("/api/v1/bookings/{$booking->id}/passengers", [
            'full_name_as_document' => 'RANDIKA LAKSHAN', 'passenger_type' => 'adult',
            'date_of_birth' => '12003-02-08', 'passport_expiry' => '2030-02-02',
        ])->assertStatus(422)->assertJsonValidationErrors('date_of_birth')
            ->assertJsonFragment(['Enter a valid date (year between 1900 and 2100).']);

        $this->postJson("/api/v1/bookings/{$booking->id}/passengers", [
            'full_name_as_document' => 'RANDIKA LAKSHAN', 'passenger_type' => 'adult', 'passport_expiry' => '20300-02-02',
        ])->assertStatus(422)->assertJsonValidationErrors('passport_expiry');

        // A correct date still saves.
        $this->postJson("/api/v1/bookings/{$booking->id}/passengers", [
            'full_name_as_document' => 'RANDIKA LAKSHAN', 'passenger_type' => 'adult',
            'date_of_birth' => '2003-02-08', 'passport_expiry' => '2030-02-02',
        ])->assertCreated();
    }
}
