<?php

namespace Tests;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Services\BookingWorkflowService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function bookingPayload(array $overrides = []): array
    {
        $customer = Customer::create(['full_name' => 'Saman Perera', 'phone' => '+94 77 123 4567']);

        return array_replace_recursive([
            'customer_id' => $customer->id,
            'pnr' => 'qx7k2m',
            'booking_date' => '2026-10-01',
            'currency' => 'LKR',
            'status' => 'confirmed',
            'selling_total' => '740000.00',
            'service_fee' => '10000',
            'supplier_total' => '690000',
            'passengers' => [
                ['full_name_as_document' => 'SAMAN PERERA', 'passport_number' => 'N1234567'],
                ['full_name_as_document' => 'NIROSHA PERERA'],
            ],
            'flights' => [
                ['airline' => 'Qatar Airways', 'flight_number' => 'qr655', 'origin_code' => 'cmb', 'destination_code' => 'DOH',
                    'departure_at' => '2026-11-17T04:05', 'arrival_at' => '2026-11-17T06:55', 'cabin_class' => 'economy', 'baggage_allowance' => '2PC'],
                ['airline' => 'Qatar Airways', 'flight_number' => 'QR167', 'origin_code' => 'DOH', 'destination_code' => 'ARN',
                    'departure_at' => '2026-11-17T08:10', 'arrival_at' => '2026-11-17T13:50'],
            ],
        ], $overrides);
    }

    /**
     * Creates a booking through the API. Statuses that cannot be created directly
     * (ticketed, changed, cancelled, completed) are set afterwards as a test fixture.
     */
    protected function createBooking(array $overrides = []): Booking
    {
        $payload = $this->bookingPayload($overrides);
        $status = $payload['status'] ?? 'inquiry';
        if (! in_array($status, BookingWorkflowService::CREATABLE, true)) {
            $payload['status'] = 'confirmed';
        }
        $id = $this->postJson('/api/v1/bookings', $payload)->assertCreated()->json('id');
        $booking = Booking::findOrFail($id);
        if ($booking->status->value !== $status) {
            $booking->forceFill(['status' => $status])->saveQuietly();
        }

        return $booking;
    }

    /** A small valid PDF upload for e-ticket tests. */
    protected function eTicketPdf(string $name = 'e-ticket.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }
}
