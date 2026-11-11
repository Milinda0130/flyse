<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual flight quotations (no live search, no reservations): requirements, flight options with
 * segments, generated PDF versions and quotation emails, plus airlines with uploaded logos.
 * New tables only; existing data is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airlines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('iata_code', 3)->nullable()->index();
            $table->string('icao_code', 4)->nullable()->index();
            // Normalised PNG in private storage (re-encoded on upload).
            $table->string('logo_key')->nullable();
            $table->timestamp('logo_updated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('quotation_code_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('flight_quotations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->string('trip_type', 20)->default('round_trip');
            $table->string('origin_country', 80)->nullable();
            $table->string('origin_city', 120);
            $table->string('origin_code', 3)->nullable();
            $table->string('destination_country', 80)->nullable();
            $table->string('destination_city', 120);
            $table->string('destination_code', 3)->nullable();
            $table->date('outbound_date')->index();
            $table->date('return_date')->nullable();
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->unsignedTinyInteger('infants')->default(0);
            $table->string('cabin_class', 20)->default('economy');
            $table->char('currency', 3);
            $table->timestamp('valid_until')->nullable();
            // Optional preferences from the customer.
            $table->string('requested_airline', 120)->nullable();
            $table->string('preferred_departure_time', 60)->nullable();
            $table->unsignedTinyInteger('max_stops')->nullable();
            $table->string('requested_baggage', 120)->nullable();
            $table->text('customer_requirements')->nullable();
            // Shown on the PDF / email when filled in.
            $table->text('message_to_customer')->nullable();
            // Staff only.
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('accepted_option_id')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_id', 'status']);
        });

        Schema::create('flight_quotation_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('label', 60);
            $table->string('fare_family', 80)->nullable();
            $table->string('cabin_class', 20)->nullable();
            $table->char('currency', 3);
            // Per-person prices by passenger type (customer-facing). Null = not entered.
            $table->decimal('adult_fare', 14, 2)->nullable();
            $table->decimal('adult_tax', 14, 2)->nullable();
            $table->decimal('child_fare', 14, 2)->nullable();
            $table->decimal('child_tax', 14, 2)->nullable();
            $table->decimal('infant_fare', 14, 2)->nullable();
            $table->decimal('infant_tax', 14, 2)->nullable();
            $table->decimal('other_charges', 14, 2)->nullable();
            // Internal only: what the agency pays for the whole party.
            $table->decimal('supplier_cost', 14, 2)->nullable();
            // Baggage: included / not_included / unknown (+ allowance text).
            $table->string('checked_bag_status', 20)->default('unknown');
            $table->string('checked_bag_allowance', 60)->nullable();
            $table->string('cabin_bag_status', 20)->default('unknown');
            $table->string('cabin_bag_allowance', 60)->nullable();
            $table->string('personal_item_status', 20)->default('unknown');
            $table->string('personal_item_allowance', 60)->nullable();
            $table->string('baggage_notes', 500)->nullable();
            // Fare conditions, as entered from the fare rules (unknown = "please confirm").
            $table->string('refundability', 30)->default('unknown');
            $table->string('change_permitted', 10)->default('unknown');
            $table->decimal('change_fee', 14, 2)->nullable();
            $table->string('fare_difference', 10)->default('unknown');
            $table->text('cancellation_conditions')->nullable();
            $table->text('no_show_conditions')->nullable();
            $table->text('other_conditions')->nullable();
            // Optional paid extras: [{title, amount, details}] (never added to the total).
            $table->json('extras')->nullable();
            $table->timestamp('price_valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('flight_quotation_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_quotation_option_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10)->default('outbound');
            $table->unsignedSmallInteger('segment_order')->default(1);
            $table->foreignId('airline_id')->nullable()->constrained()->nullOnDelete();
            $table->string('airline_name', 120);
            $table->foreignId('operating_airline_id')->nullable()->constrained('airlines')->nullOnDelete();
            $table->string('operating_airline_name', 120)->nullable();
            $table->string('flight_number', 10);
            $table->string('from_code', 3);
            $table->string('from_name', 120)->nullable();
            $table->string('to_code', 3);
            $table->string('to_name', 120)->nullable();
            // Local airport times; optional IANA timezones allow exact durations.
            $table->dateTime('departure_at');
            $table->dateTime('arrival_at')->nullable();
            $table->string('departure_tz', 64)->nullable();
            $table->string('arrival_tz', 64)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('departure_terminal', 20)->nullable();
            $table->string('arrival_terminal', 20)->nullable();
            $table->string('aircraft', 60)->nullable();
            $table->string('cabin_class', 20)->nullable();
            $table->string('baggage_note', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->index(['flight_quotation_option_id', 'direction', 'segment_order'], 'fq_segments_order_index');
        });

        Schema::create('flight_quotation_pdfs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('storage_key');
            $table->unsignedInteger('size_bytes');
            // Hash of the quotation content; a new version is made only when the content changed.
            $table->char('content_hash', 64);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['flight_quotation_id', 'version']);
        });

        Schema::create('quotation_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flight_quotation_pdf_id')->constrained()->restrictOnDelete();
            $table->string('recipient');
            $table->boolean('recipient_is_override')->default(false);
            $table->string('subject');
            $table->string('attachment_name');
            $table->unsignedInteger('size_bytes');
            $table->string('status', 20)->default('queued')->index();
            $table->string('mailer', 30)->nullable();
            $table->string('message_id')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['quotation_emails', 'flight_quotation_pdfs', 'flight_quotation_segments', 'flight_quotation_options', 'flight_quotations', 'quotation_code_sequences', 'airlines'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
