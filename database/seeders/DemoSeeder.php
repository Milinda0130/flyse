<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BookingCodeService;
use App\Services\DocumentStorageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Non-sensitive sample data for local testing and owner acceptance testing.
 * Run with: php artisan db:seed --class=DemoSeeder
 * All names, PNRs and ticket numbers are fictitious.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder is disabled in production.');

            return;
        }

        $this->call(DatabaseSeeder::class);
        $owner = User::where('role', UserRole::Owner)->firstOrFail();
        auth()->setUser($owner);

        User::firstOrCreate(['email' => 'staff@agency.test'], ['name' => 'Nimal Staff', 'password' => 'StaffPass123', 'role' => UserRole::Staff]);
        User::firstOrCreate(['email' => 'viewer@agency.test'], ['name' => 'Read Only', 'password' => 'ViewerPass123', 'role' => UserRole::Readonly]);

        if (Booking::exists()) {
            $this->command?->info('Bookings already exist; skipping demo bookings.');

            return;
        }

        $codes = app(BookingCodeService::class);
        $consolidator = Supplier::create(['name' => 'Lanka Air Consolidators', 'supplier_type' => 'consolidator', 'phone' => '+94 11 000 0000', 'email' => 'desk@consolidator.test']);
        $airline = Supplier::create(['name' => 'Qatar Airways (direct)', 'supplier_type' => 'airline']);

        $saman = Customer::create(['full_name' => 'Saman Perera', 'phone' => '+94 77 123 4567', 'email' => 'saman@example.test', 'nationality' => 'Sri Lankan']);
        $dilani = Customer::create(['full_name' => 'Dilani Fernando', 'phone' => '+94 71 555 1212', 'email' => 'dilani@example.test']);
        $kasun = Customer::create(['full_name' => 'Kasun Jayasinghe', 'phone' => '+94 76 987 6543']);

        $month = now()->addMonth()->startOfMonth();

        DB::transaction(function () use ($codes, $consolidator, $saman, $month, $owner) {
            // Plan example: two Economy passengers CMB → ARN on the 17th.
            $b = Booking::create([
                'booking_code' => $codes->next(), 'customer_id' => $saman->id, 'supplier_id' => $consolidator->id,
                'pnr' => 'QX7K2M', 'booking_date' => now()->subDays(5)->toDateString(), 'currency' => 'LKR',
                'origin' => 'CMB', 'destination' => 'ARN', 'departure_date' => $month->copy()->day(17)->toDateString(), 'passenger_count' => 2, 'cabin_class' => 'economy',
                'status' => 'ticketed', 'payment_due_date' => now()->addDays(2)->toDateString(),
                'selling_total' => 740000, 'service_fee' => 10000, 'supplier_total' => 690000,
                'next_action' => 'Collect balance before departure', 'created_by' => $owner->id,
            ]);
            $p1 = $b->passengers()->create(['full_name_as_document' => 'SAMAN PERERA', 'passenger_type' => 'adult']);
            $p2 = $b->passengers()->create(['full_name_as_document' => 'NIROSHA PERERA', 'passenger_type' => 'adult']);
            $out1 = $b->flights()->create(['segment_order' => 1, 'airline' => 'Qatar Airways', 'flight_number' => 'QR655', 'origin_code' => 'CMB', 'destination_code' => 'DOH',
                'departure_at' => $month->copy()->day(17)->setTime(4, 5), 'arrival_at' => $month->copy()->day(17)->setTime(6, 55),
                'origin_timezone' => 'Asia/Colombo', 'destination_timezone' => 'Asia/Qatar', 'cabin_class' => 'economy', 'baggage_allowance' => 'As per ticket']);
            $b->flights()->create(['segment_order' => 2, 'airline' => 'Qatar Airways', 'flight_number' => 'QR167', 'origin_code' => 'DOH', 'destination_code' => 'ARN',
                'departure_at' => $month->copy()->day(17)->setTime(8, 10), 'arrival_at' => $month->copy()->day(17)->setTime(13, 50),
                'origin_timezone' => 'Asia/Qatar', 'destination_timezone' => 'Europe/Stockholm', 'cabin_class' => 'economy', 'baggage_allowance' => 'As per ticket']);
            // One e-ticket PDF covering both passengers (sample file, stored privately).
            $key = 'demo/'.$b->booking_code.'-e-ticket.pdf';
            Storage::disk(DocumentStorageService::DISK)->put($key, "%PDF-1.4
% Sample e-ticket for demo booking {$b->booking_code}
%%EOF
");
            $eTicket = Document::create(['booking_id' => $b->id, 'document_type' => 'e_ticket', 'original_name' => 'e-ticket-QX7K2M.pdf',
                'storage_key' => $key, 'mime_type' => 'application/pdf', 'size_bytes' => 64, 'uploaded_by' => $owner->id]);
            $b->tickets()->create(['passenger_id' => $p1->id, 'ticket_number' => '1572100000001', 'issued_at' => now()->subDays(4)->toDateString(), 'e_ticket_document_id' => $eTicket->id]);
            $b->tickets()->create(['passenger_id' => $p2->id, 'ticket_number' => '1572100000002', 'issued_at' => now()->subDays(4)->toDateString(), 'e_ticket_document_id' => $eTicket->id]);
            $b->payments()->create(['direction' => 'customer_receipt', 'amount' => 400000, 'currency' => 'LKR', 'method' => 'Bank transfer', 'transaction_reference' => 'DEMO-RCPT-1', 'transaction_date' => now()->subDays(5)->toDateString(), 'created_by' => $owner->id]);
            $b->payments()->create(['direction' => 'supplier_payment', 'amount' => 690000, 'currency' => 'LKR', 'method' => 'Bank transfer', 'transaction_reference' => 'DEMO-SUP-1', 'transaction_date' => now()->subDays(4)->toDateString(), 'created_by' => $owner->id]);
            $issue = $b->issues()->create(['issue_type' => 'schedule_change', 'source' => 'airline', 'severity' => 'medium', 'status' => 'action_required',
                'summary' => 'QR655 departure moved by 45 minutes', 'description' => 'Airline email: schedule change on first segment. Connection time still valid.',
                'next_action' => 'Inform customer and confirm acceptance', 'reported_at' => now()->subDay(), 'due_at' => now()->addDay(),
                'booking_flight_id' => $out1->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id]);
            $issue->updates()->create(['update_text' => 'Issue created from airline email.', 'status_to' => 'open', 'created_by' => $owner->id]);
            $issue->updates()->create(['update_text' => 'Checked new connection time; customer must be informed.', 'status_from' => 'open', 'status_to' => 'action_required', 'created_by' => $owner->id]);
        });

        DB::transaction(function () use ($codes, $dilani, $consolidator, $owner) {
            $b = Booking::create([
                'booking_code' => $codes->next(), 'customer_id' => $dilani->id, 'supplier_id' => $consolidator->id,
                'pnr' => 'LM4P9Z', 'booking_date' => now()->subMonth()->toDateString(), 'currency' => 'USD',
                'origin' => 'CMB', 'destination' => 'DXB', 'departure_date' => now()->addDays(10)->toDateString(), 'passenger_count' => 1, 'cabin_class' => 'economy',
                'cancellation_reason' => 'Airline cancelled EK651; customer chose a refund', 'cancelled_at' => now()->subDays(6),
                'status' => 'cancelled', 'selling_total' => 250, 'service_fee' => 25, 'supplier_total' => 190, 'created_by' => $owner->id,
                'notes' => 'Original fare USD 850 + fee 25 (cost 790). After cancellation totals were reduced to the amounts retained; refunds recorded as transactions.',
            ]);
            $p = $b->passengers()->create(['full_name_as_document' => 'DILANI FERNANDO']);
            $b->flights()->create(['segment_order' => 1, 'airline' => 'Emirates', 'flight_number' => 'EK651', 'origin_code' => 'CMB', 'destination_code' => 'DXB',
                'departure_at' => now()->addDays(10)->setTime(22, 0), 'cabin_class' => 'economy', 'status' => 'cancelled']);
            $b->tickets()->create(['passenger_id' => $p->id, 'ticket_number' => '1762100000003', 'status' => 'refunded']);
            $b->payments()->create(['direction' => 'customer_receipt', 'amount' => 875, 'currency' => 'USD', 'method' => 'Card', 'transaction_date' => now()->subMonth()->toDateString(), 'created_by' => $owner->id]);
            $b->payments()->create(['direction' => 'supplier_payment', 'amount' => 790, 'currency' => 'USD', 'method' => 'Bank transfer', 'transaction_date' => now()->subMonth()->toDateString(), 'created_by' => $owner->id]);
            $b->payments()->create(['direction' => 'supplier_refund', 'amount' => 600, 'currency' => 'USD', 'method' => 'Bank transfer', 'transaction_date' => now()->subDays(3)->toDateString(), 'created_by' => $owner->id]);
            $b->payments()->create(['direction' => 'customer_refund', 'amount' => 600, 'currency' => 'USD', 'method' => 'Card', 'status' => 'pending', 'transaction_date' => now()->toDateString(), 'created_by' => $owner->id]);
            $issue = $b->issues()->create(['issue_type' => 'airline_cancellation', 'source' => 'airline', 'severity' => 'high', 'status' => 'waiting_airline',
                'summary' => 'EK651 cancelled — customer chose refund', 'next_action' => 'Wait for supplier refund confirmation', 'reported_at' => now()->subDays(6),
                'due_at' => now()->subDay(), 'customer_notified' => true, 'refund_reference' => 'RF-DEMO-77', 'created_by' => $owner->id]);
            $issue->updates()->create(['update_text' => 'Airline cancelled the flight; refund requested.', 'status_to' => 'open', 'created_by' => $owner->id]);
        });

        DB::transaction(function () use ($codes, $kasun, $owner) {
            $b = Booking::create([
                'booking_code' => $codes->next(), 'customer_id' => $kasun->id, 'booking_date' => now()->toDateString(), 'currency' => 'LKR',
                'status' => 'inquiry', 'origin' => 'CMB', 'destination' => 'DXB', 'departure_date' => now()->addDays(5)->toDateString(),
                'trip_type' => 'round_trip', 'return_date' => now()->addDays(19)->toDateString(), 'passenger_count' => 1, 'cabin_class' => 'economy',
                'ticketing_deadline' => now()->addDays(2)->setTime(18, 0),
                'next_action' => 'Send quote and confirm dates', 'created_by' => $owner->id,
            ]);
            $b->passengers()->create(['full_name_as_document' => 'KASUN JAYASINGHE']);
            $b->flights()->create(['segment_order' => 1, 'airline' => 'SriLankan Airlines', 'flight_number' => 'UL225', 'origin_code' => 'CMB', 'destination_code' => 'DXB',
                'departure_at' => now()->addDays(5)->setTime(9, 30), 'cabin_class' => 'economy', 'baggage_allowance' => '30 kg']);
        });

        Expense::create(['expense_date' => now()->startOfMonth()->toDateString(), 'category' => 'Rent', 'description' => 'Office rent', 'amount' => 75000, 'currency' => 'LKR', 'payment_method' => 'Bank transfer', 'created_by' => $owner->id]);

        $this->command?->info('Demo data created. Demo logins: staff@agency.test / StaffPass123, viewer@agency.test / ViewerPass123');
    }
}
