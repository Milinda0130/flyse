<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The inquiry -> reservation -> ticketed -> changed / cancelled workflow (task scenarios 1-8). */
class BookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function inquiry(array $extra = []): array
    {
        $customer = Customer::create(['full_name' => 'Saman Perera', 'phone' => '+94 77 123 4567']);

        return $this->postJson('/api/v1/bookings', [
            'customer_id' => $customer->id,
            'trip_type' => 'one_way',
            'origin' => 'CMB',
            'destination' => 'ARN',
            'departure_date' => '2026-11-17',
            'cabin_class' => 'economy',
            'passenger_count' => 2,
            'notes' => 'Window seats if possible',
            ...$extra,
        ])->assertCreated()->json();
    }

    /** Takes an inquiry to Reservation Confirmed with passengers and segments. */
    private function reserve(int $id): void
    {
        $supplier = Supplier::create(['name' => 'Lanka Air Consolidators', 'supplier_type' => 'consolidator']);
        $this->postJson("/api/v1/bookings/{$id}/status", [
            'status' => 'confirmed', 'supplier_id' => $supplier->id, 'pnr' => 'qx7k2m',
            'ticketing_deadline' => '2026-10-20T18:00', 'payment_due_date' => '2026-10-25',
            'selling_total' => '740000', 'service_fee' => '10000', 'supplier_total' => '690000',
        ])->assertOk();
        foreach (['SAMAN PERERA', 'NIROSHA PERERA'] as $name) {
            $this->postJson("/api/v1/bookings/{$id}/passengers", ['full_name_as_document' => $name])->assertCreated();
        }
        $this->postJson("/api/v1/bookings/{$id}/flights", ['airline' => 'Qatar Airways', 'flight_number' => 'QR655', 'origin_code' => 'CMB', 'destination_code' => 'DOH', 'departure_at' => '2026-11-17T04:05'])->assertCreated();
        $this->postJson("/api/v1/bookings/{$id}/flights", ['airline' => 'Qatar Airways', 'flight_number' => 'QR167', 'origin_code' => 'DOH', 'destination_code' => 'ARN', 'departure_at' => '2026-11-17T08:10'])->assertCreated();
    }

    private function issueTickets(int $id, array $tickets, array $extra = [])
    {
        return $this->post("/api/v1/bookings/{$id}/tickets", ['tickets' => $tickets, ...$extra], ['Accept' => 'application/json']);
    }

    public function test_scenario1_inquiry_needs_only_customer_and_trip(): void
    {
        $this->actingAsRole('staff');
        $b = $this->inquiry();

        $this->assertSame('inquiry', $b['status']);
        $this->assertMatchesRegularExpression('/^BK-\d{4}-\d{4}$/', $b['booking_code']);
        $this->assertSame('CMB → ARN', $b['requested_route']);
        $this->assertSame(2, $b['passenger_count']);
        $this->assertNull($b['pnr']);
        $this->assertNull($b['finance']['customer_due'], 'unknown price must not be shown as 0');
        $this->assertSame('not_priced', $b['finance']['payment_status']);
        $this->assertNull($b['finance']['gross_margin']);
        $keys = array_column($b['checklist'], 'key');
        $this->assertContains('flights', $keys);
        $this->assertContains('passengers', $keys);
        $this->assertContains('price', $keys);
        $this->assertSame(['confirmed', 'pending_payment', 'on_hold', 'cancelled'], $b['allowed_transitions']);

        // Reopen and edit.
        $this->getJson("/api/v1/bookings/{$b['id']}")->assertOk()->assertJsonPath('destination', 'ARN');
        $this->putJson("/api/v1/bookings/{$b['id']}", ['trip_type' => 'round_trip', 'return_date' => '2026-12-01'])->assertOk()
            ->assertJsonPath('return_date', '2026-12-01');
        $this->putJson("/api/v1/bookings/{$b['id']}", ['return_date' => '2026-11-01'])->assertStatus(422)->assertJsonValidationErrors('return_date');

        // Cannot start a booking as Ticketed.
        $this->postJson('/api/v1/bookings', ['customer_id' => $b['customer_id'], 'origin' => 'CMB', 'destination' => 'DXB', 'passenger_count' => 1, 'trip_type' => 'one_way', 'status' => 'ticketed'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_scenario2_reservation_details_added_later(): void
    {
        $this->actingAsRole('owner');
        $b = $this->inquiry();
        $this->reserve($b['id']);

        $detail = $this->getJson("/api/v1/bookings/{$b['id']}")->assertOk();
        $detail->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('pnr', 'QX7K2M')
            ->assertJsonPath('supplier.name', 'Lanka Air Consolidators')
            ->assertJsonPath('finance.customer_due', '750000.00')
            ->assertJsonPath('finance.gross_margin', '60000.00')
            ->assertJsonPath('route', 'CMB-DOH-ARN')
            ->assertJsonCount(2, 'passengers');
        $this->assertContains('tickets', array_column($detail->json('checklist'), 'key'));
        $this->assertSame(1, Booking::count(), 'details were added to the same booking');
    }

    public function test_scenario3_ticketed_requires_ticket_numbers_and_stored_eticket(): void
    {
        $this->actingAsRole('owner');
        $b = $this->inquiry();
        $id = $b['id'];

        // An inquiry cannot jump to Ticketed at all.
        $this->postJson("/api/v1/bookings/{$id}/status", ['status' => 'ticketed'])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->reserve($id);
        $passengers = Booking::find($id)->passengers()->pluck('id')->all();

        // Without tickets: refused with a clear list of what is missing.
        $r = $this->postJson("/api/v1/bookings/{$id}/status", ['status' => 'ticketed'])->assertStatus(422);
        $this->assertStringContainsString('SAMAN PERERA: no ticket number recorded.', json_encode($r->json('errors.status')));

        // A ticket needs an e-ticket document.
        $this->issueTickets($id, [['passenger_id' => $passengers[0], 'ticket_number' => '1572100000001']])
            ->assertStatus(422)->assertJsonValidationErrors('e_ticket');

        // Ticketing only one of two passengers while asking to mark Ticketed: everything rolls back.
        $this->issueTickets($id, [['passenger_id' => $passengers[0], 'ticket_number' => '1572100000001']], ['e_ticket' => $this->eTicketPdf(), 'mark_ticketed' => '1'])
            ->assertStatus(422);
        $this->assertSame(0, Ticket::count(), 'no ticket saved when the step failed');
        $this->assertSame(0, Document::count(), 'no document row saved when the step failed');
        $this->assertSame([], Storage::disk('documents')->allFiles(), 'uploaded file removed after rollback');
        $this->assertSame('confirmed', Booking::find($id)->status->value);

        // One PDF covering both passengers, then marked Ticketed in the same step.
        $this->issueTickets($id, [
            ['passenger_id' => $passengers[0], 'ticket_number' => '157 2100000001'],
            ['passenger_id' => $passengers[1], 'ticket_number' => '1572100000002'],
        ], ['e_ticket' => $this->eTicketPdf(), 'mark_ticketed' => '1'])
            ->assertCreated()->assertJsonPath('booking_status', 'ticketed')->assertJsonCount(2, 'tickets');

        $doc = Document::sole();
        $this->assertSame('e_ticket', $doc->document_type->value);
        Storage::disk('documents')->assertExists($doc->storage_key);
        $this->assertSame([$doc->id, $doc->id], Ticket::pluck('e_ticket_document_id')->all());
        $this->getJson("/api/v1/bookings/{$id}")->assertJsonPath('documents.0.ticket_numbers', ['1572100000001', '1572100000002'])
            ->assertJsonPath('passengers.0.passport_number', null);

        // The e-ticket cannot be archived while tickets depend on it.
        $this->deleteJson("/api/v1/documents/{$doc->id}")->assertStatus(422);
    }

    public function test_scenario3b_missing_file_blocks_ticketed(): void
    {
        $this->actingAsRole('owner');
        $id = $this->inquiry(['passenger_count' => 1])['id'];
        $this->reserve($id);
        Booking::find($id)->update(['passenger_count' => 2]);
        $passengers = Booking::find($id)->passengers()->pluck('id')->all();
        $this->issueTickets($id, [
            ['passenger_id' => $passengers[0], 'ticket_number' => '1'],
            ['passenger_id' => $passengers[1], 'ticket_number' => '2'],
        ], ['e_ticket' => $this->eTicketPdf()])->assertCreated();

        // The file disappears from storage: Ticketed must be refused.
        Storage::disk('documents')->delete(Document::sole()->storage_key);
        $r = $this->postJson("/api/v1/bookings/{$id}/status", ['status' => 'ticketed'])->assertStatus(422);
        $this->assertStringContainsString('missing from storage', json_encode($r->json('errors.status')));

        // Re-using an existing e-ticket document of this booking works without a new upload.
        Storage::disk('documents')->put(Document::sole()->storage_key, '%PDF-1.4');
        $this->postJson("/api/v1/bookings/{$id}/status", ['status' => 'ticketed'])->assertOk()->assertJsonPath('status', 'ticketed');
    }

    public function test_scenario5_schedule_change_is_found_recorded_and_history_kept(): void
    {
        $this->actingAsRole('owner');
        $id = $this->inquiry()['id'];
        $this->reserve($id);
        $booking = Booking::find($id);
        $p = $booking->passengers()->pluck('id')->all();
        $this->issueTickets($id, [
            ['passenger_id' => $p[0], 'ticket_number' => '1572100000001', 'airline_pnr' => 'ab12cd'],
            ['passenger_id' => $p[1], 'ticket_number' => '1572100000002', 'airline_pnr' => 'AB12CD'],
        ], ['e_ticket' => $this->eTicketPdf(), 'mark_ticketed' => '1'])->assertCreated();

        // Found by ticket number and by the per-ticket airline PNR.
        $this->getJson('/api/v1/bookings?q=1572100000002')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/bookings?q=AB12CD')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);

        $flight = $booking->flights()->where('flight_number', 'QR167')->first();
        $issue = $this->postJson("/api/v1/bookings/{$id}/issues", [
            'issue_type' => 'schedule_change', 'source' => 'airline', 'summary' => 'QR167 moved 2 hours later', 'booking_flight_id' => $flight->id,
        ])->assertCreated()->json();
        $this->post("/api/v1/bookings/{$id}/documents", ['files' => [$this->eTicketPdf('airline-email.pdf')], 'document_type' => 'airline_email', 'issue_id' => $issue['id']], ['Accept' => 'application/json'])->assertCreated();

        // Itinerary updated after verification; Changed needs a note.
        $this->putJson("/api/v1/booking-flights/{$flight->id}", ['departure_at' => '2026-11-17T10:10', 'status' => 'changed'])->assertOk();
        $this->postJson("/api/v1/bookings/{$id}/status", ['status' => 'changed'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/bookings/{$id}/status", ['status' => 'changed', 'note' => 'QR167 now departs 10:10'])->assertOk()->assertJsonPath('status', 'changed');

        $this->postJson("/api/v1/issues/{$issue['id']}/updates", ['update_text' => 'Customer contacted and accepted', 'status' => 'resolved'])->assertCreated();

        $detail = $this->getJson("/api/v1/issues/{$issue['id']}")->assertOk()->assertJsonPath('status', 'resolved')->assertJsonCount(1, 'documents');
        $change = collect($detail->json('flight_history'))->firstWhere('action', 'updated');
        $this->assertSame('2026-11-17T08:10', $change['before']['departure_at'], 'original schedule kept');
        $this->assertSame('2026-11-17T10:10', $change['after']['departure_at']);
    }

    public function test_scenario6_cancellation_keeps_booking_and_records_refunds(): void
    {
        $this->actingAsRole('owner');
        $id = $this->inquiry()['id'];
        $this->reserve($id);
        $this->postJson("/api/v1/bookings/{$id}/payments", ['direction' => 'customer_receipt', 'amount' => '750000', 'method' => 'Cash', 'transaction_date' => '2026-10-05'])->assertCreated();
        $this->postJson("/api/v1/bookings/{$id}/payments", ['direction' => 'supplier_payment', 'amount' => '690000', 'method' => 'Bank transfer', 'transaction_date' => '2026-10-05'])->assertCreated();

        $this->postJson("/api/v1/bookings/{$id}/status", ['status' => 'cancelled'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/bookings/{$id}/status", [
            'status' => 'cancelled', 'reason' => 'Customer cancelled; airline charges LKR 100,000',
            'selling_total' => '100000', 'service_fee' => '0', 'supplier_total' => '90000',
            'customer_refund_amount' => '650000', 'refund_method' => 'Bank transfer',
        ])->assertOk()->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('finance.refund_pending', '650000.00')
            ->assertJsonPath('finance.payment_status', 'overpaid');
        $this->postJson("/api/v1/bookings/{$id}/payments", ['direction' => 'supplier_refund', 'amount' => '600000', 'method' => 'Bank transfer', 'transaction_date' => '2026-10-06'])->assertCreated();

        $this->assertSame(1, Booking::count());
        $this->assertSame(4, Payment::count(), 'no transaction deleted');
        $refund = Payment::where('direction', 'customer_refund')->sole();
        $this->assertSame('pending', $refund->status->value);

        // Completing the refund settles the customer balance; supplier refund settles the supplier side.
        $this->patchJson("/api/v1/payments/{$refund->id}", ['status' => 'completed'])->assertOk();
        $this->getJson("/api/v1/bookings/{$id}")->assertJsonPath('finance.customer_balance', '0.00')
            ->assertJsonPath('finance.supplier_balance', '0.00')
            ->assertJsonPath('allowed_transitions', []);
    }

    public function test_scenario6b_staff_cannot_record_a_refund_with_cancellation(): void
    {
        $this->actingAsRole('owner');
        $id = $this->inquiry()['id'];
        $this->actingAsRole('staff');
        $this->postJson("/api/v1/bookings/{$id}/status", ['status' => 'cancelled', 'reason' => 'x', 'customer_refund_amount' => '10'])->assertForbidden();
        $this->postJson("/api/v1/bookings/{$id}/status", ['status' => 'cancelled', 'reason' => 'Customer cancelled'])->assertOk();
    }

    public function test_scenario7_unknown_amounts_are_null_not_zero(): void
    {
        $this->actingAsRole('owner');
        $id = $this->inquiry()['id'];
        $this->putJson("/api/v1/bookings/{$id}", ['selling_total' => '500000'])->assertOk()
            ->assertJsonPath('finance.customer_due', '500000.00')
            ->assertJsonPath('finance.supplier_total', null)
            ->assertJsonPath('finance.gross_margin', null);
        $this->putJson("/api/v1/bookings/{$id}", ['supplier_total' => '0'])->assertOk()
            ->assertJsonPath('finance.supplier_total', '0.00')
            ->assertJsonPath('finance.gross_margin', '500000.00');
    }

    public function test_scenario8_permissions_on_workflow_actions(): void
    {
        $this->actingAsRole('owner');
        $id = $this->inquiry()['id'];
        $this->reserve($id);
        $passenger = Booking::find($id)->passengers()->value('id');

        $this->actingAsRole('readonly');
        $this->postJson("/api/v1/bookings/{$id}/status", ['status' => 'on_hold'])->assertForbidden();
        $this->issueTickets($id, [['passenger_id' => $passenger, 'ticket_number' => '1']], ['e_ticket' => $this->eTicketPdf()])->assertForbidden();
        $detail = $this->getJson("/api/v1/bookings/{$id}")->assertOk()->assertJsonMissingPath('supplier_total')->assertJsonPath('allowed_transitions', []);
        $this->assertArrayNotHasKey('gross_margin', $detail->json('finance'));
        $this->assertNotContains('cost', array_column($detail->json('checklist'), 'key'));

        // Next step / to-do never reveal supplier amounts to users without cost access.
        $this->actingAsRole('owner');
        Booking::find($id)->forceFill(['status' => 'ticketed'])->saveQuietly();
        $this->postJson("/api/v1/bookings/{$id}/payments", ['direction' => 'customer_receipt', 'amount' => '750000', 'method' => 'Cash', 'transaction_date' => '2026-10-05'])->assertCreated();
        $this->getJson("/api/v1/bookings/{$id}")->assertJsonPath('next_step', 'Pay the supplier LKR 690,000.00.');
        $this->actingAsRole('readonly');
        $hidden = $this->getJson("/api/v1/bookings/{$id}");
        $this->assertStringNotContainsString('690,000', $hidden->json('next_step').json_encode($hidden->json('checklist')));
    }
}
