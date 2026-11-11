<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History of ticket emails sent to customers: who sent what to whom, and the outcome.
 * Stores attachment metadata only (never file contents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('recipient');
            $table->boolean('recipient_is_override')->default(false);
            $table->string('subject');
            // Snapshot: [{document_id, name, size_bytes, passengers, tickets}]
            $table->json('attachments');
            $table->unsignedSmallInteger('attachment_count');
            $table->unsignedBigInteger('total_bytes');
            // queued -> sending -> sent | failed
            $table->string('status', 20)->default('queued')->index();
            $table->string('mailer', 30)->nullable();
            $table->string('message_id')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            // Same key from the same dialog = same send (protects against double clicks / retried requests).
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
        Schema::dropIfExists('booking_emails');
    }
};
