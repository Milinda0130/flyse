<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core business tables for the Flight Ticket Management System.
 * Money columns use DECIMAL(14,2); status columns hold enum values from app/Enums
 * and are validated again at API level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name')->index();
            $table->string('phone', 40)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('address')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 80)->nullable();
            // Encrypted at rest via model cast; never returned in list endpoints.
            $table->text('passport_number')->nullable();
            $table->date('passport_expiry')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('supplier_type', 30)->default('consolidator');
            $table->string('contact_person')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('booking_code_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code', 20)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            // PNRs are not assumed to be globally unique across suppliers.
            $table->string('pnr', 20)->nullable()->index();
            $table->date('booking_date')->index();
            $table->char('currency', 3);
            $table->string('status', 30)->default('inquiry')->index();
            $table->date('payment_due_date')->nullable();
            $table->dateTime('ticketing_deadline')->nullable();
            $table->decimal('selling_total', 14, 2)->default(0);
            $table->decimal('service_fee', 14, 2)->default(0);
            $table->decimal('supplier_total', 14, 2)->default(0);
            $table->string('next_action')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('full_name_as_document')->index();
            $table->string('passenger_type', 10)->default('adult');
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 80)->nullable();
            $table->text('passport_number')->nullable();
            $table->date('passport_expiry')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_flights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('segment_order')->default(1);
            $table->string('airline', 100);
            $table->string('flight_number', 12)->index();
            $table->char('origin_code', 3)->index();
            $table->char('destination_code', 3)->index();
            // Local airport times, interpreted with the timezone columns below.
            $table->dateTime('departure_at')->index();
            $table->dateTime('arrival_at')->nullable();
            $table->string('origin_timezone', 64)->nullable();
            $table->string('destination_timezone', 64)->nullable();
            $table->string('cabin_class', 20)->default('economy');
            $table->string('baggage_allowance', 100)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_flight_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ticket_number', 30)->index();
            $table->date('issued_at')->nullable();
            $table->string('status', 20)->default('issued');
            $table->text('fare_conditions')->nullable();
            $table->timestamps();
        });

        Schema::create('booking_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_flight_id')->nullable()->constrained()->nullOnDelete();
            $table->string('issue_type', 30)->index();
            $table->string('source', 20);
            $table->string('severity', 10)->default('medium');
            $table->string('status', 30)->default('open')->index();
            $table->string('summary');
            $table->text('description')->nullable();
            $table->string('next_action')->nullable();
            $table->dateTime('reported_at');
            $table->dateTime('due_at')->nullable()->index();
            $table->dateTime('resolved_at')->nullable();
            $table->boolean('customer_notified')->default(false);
            $table->text('resolution_notes')->nullable();
            $table->string('refund_reference')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('issue_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('booking_issues')->cascadeOnDelete();
            $table->text('update_text');
            $table->string('status_from', 30)->nullable();
            $table->string('status_to', 30)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            // Nullable so expense receipts can exist without a booking.
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('passenger_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('issue_id')->nullable()->constrained('booking_issues')->nullOnDelete();
            $table->string('document_type', 30);
            $table->string('original_name');
            $table->string('storage_key')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->string('direction', 30)->index();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('method', 40);
            $table->string('transaction_reference')->nullable();
            $table->date('transaction_date')->index();
            $table->string('status', 20)->default('completed');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('expense_date')->index();
            $table->string('category', 60);
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('payment_method', 40)->nullable();
            $table->foreignId('receipt_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity_type', 60);
            $table->string('entity_id', 64);
            // Denormalised so a booking's full activity history is one indexed query.
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->string('action', 30);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'audit_logs', 'expenses', 'payments', 'documents', 'issue_updates', 'booking_issues',
            'tickets', 'booking_flights', 'passengers', 'bookings', 'booking_code_sequences', 'suppliers', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
