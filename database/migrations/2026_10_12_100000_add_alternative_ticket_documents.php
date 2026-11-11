<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alternative ticket PDFs (customer-provided etc.) and which workflow sent each ticket email.
 * Additive only: existing documents keep null source/verification (= official agency documents),
 * existing emails become "official".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('source_type', 30)->nullable()->after('document_type');
            $table->string('source_description', 255)->nullable()->after('source_type');
            $table->string('verification_status', 20)->nullable()->after('source_description');
            $table->foreignId('verified_by')->nullable()->after('verification_status')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->string('verification_note', 500)->nullable()->after('verified_at');
            $table->index(['booking_id', 'document_type', 'verification_status'], 'documents_booking_type_verification_index');
        });

        Schema::table('booking_emails', function (Blueprint $table) {
            $table->string('email_type', 20)->default('official')->after('booking_id');
            $table->foreignId('customer_id')->nullable()->after('email_type')->constrained()->nullOnDelete();
            // What the sender confirmed: they reviewed the document; the official ticket is still pending.
            $table->boolean('reviewed_confirmed')->default(false)->after('recipient_is_override');
            $table->boolean('official_pending')->default(false)->after('reviewed_confirmed');
            $table->index(['booking_id', 'email_type']);
        });
    }

    public function down(): void
    {
        Schema::table('booking_emails', function (Blueprint $table) {
            $table->dropIndex(['booking_id', 'email_type']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['email_type', 'reviewed_confirmed', 'official_pending']);
        });
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex('documents_booking_type_verification_index');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['source_type', 'source_description', 'verification_status', 'verified_at', 'verification_note']);
        });
    }
};
