<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the inquiry -> reservation -> ticketed workflow.
 *
 * Additive and safe for existing data: no columns or rows are dropped.
 * - bookings: requested trip (what the customer asked for, before an itinerary exists),
 *   cancellation details, and nullable prices so "not entered yet" differs from 0.
 * - tickets: airline PNR per ticket and a link to the stored e-ticket document
 *   (several tickets may share one PDF).
 * - documents: optional link to a flight segment, and notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('trip_type', 20)->default('one_way')->after('status');
            $table->string('origin', 60)->nullable()->after('trip_type');
            $table->string('destination', 60)->nullable()->after('origin');
            $table->date('departure_date')->nullable()->after('destination')->index();
            $table->date('return_date')->nullable()->after('departure_date');
            $table->string('cabin_class', 20)->nullable()->after('return_date');
            $table->unsignedSmallInteger('passenger_count')->default(1)->after('cabin_class');
            $table->text('cancellation_reason')->nullable()->after('notes');
            $table->dateTime('cancelled_at')->nullable()->after('cancellation_reason');
        });

        // Unknown prices are NULL from now on; existing values (including 0) are kept as they are.
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('selling_total', 14, 2)->nullable()->default(null)->change();
            $table->decimal('service_fee', 14, 2)->nullable()->default(null)->change();
            $table->decimal('supplier_total', 14, 2)->nullable()->default(null)->change();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('airline_pnr', 20)->nullable()->after('ticket_number')->index();
            $table->foreignId('e_ticket_document_id')->nullable()->after('fare_conditions')->constrained('documents')->nullOnDelete();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('booking_flight_id')->nullable()->after('issue_id')->constrained('booking_flights')->nullOnDelete();
            $table->text('notes')->nullable()->after('size_bytes');
        });

        $this->backfillRequestedTrips();
    }

    /** Fill the new trip summary for existing bookings from their passengers and flight segments. */
    private function backfillRequestedTrips(): void
    {
        DB::table('bookings')->orderBy('id')->chunkById(200, function ($bookings) {
            foreach ($bookings as $booking) {
                $flights = DB::table('booking_flights')->where('booking_id', $booking->id)
                    ->orderBy('segment_order')->orderBy('departure_at')->get();
                $passengers = DB::table('passengers')->where('booking_id', $booking->id)->count();

                $update = ['passenger_count' => max(1, $passengers)];
                if ($flights->isNotEmpty()) {
                    $first = $flights->first();
                    $last = $flights->last();
                    $roundTrip = $flights->count() > 1 && $last->destination_code === $first->origin_code;
                    // For a round trip the destination is the furthest point: the end of the outbound leg.
                    $outboundEnd = $roundTrip
                        ? ($flights->firstWhere('origin_code', '!=', $first->origin_code)?->origin_code ?? $first->destination_code)
                        : $last->destination_code;
                    $update += [
                        'origin' => $first->origin_code,
                        'destination' => $outboundEnd,
                        'departure_date' => substr((string) $first->departure_at, 0, 10),
                        'return_date' => $roundTrip ? substr((string) $last->departure_at, 0, 10) : null,
                        'trip_type' => $roundTrip ? 'round_trip' : 'one_way',
                        'cabin_class' => $first->cabin_class,
                    ];
                }
                DB::table('bookings')->where('id', $booking->id)->update($update);
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_flight_id');
            $table->dropColumn('notes');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('e_ticket_document_id');
            $table->dropIndex(['airline_pnr']);
            $table->dropColumn('airline_pnr');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['departure_date']);
            $table->dropColumn(['trip_type', 'origin', 'destination', 'departure_date', 'return_date', 'cabin_class',
                'passenger_count', 'cancellation_reason', 'cancelled_at']);
        });
        // Price columns stay nullable: turning NULL ("not entered") back into 0 would invent values.
    }
};
