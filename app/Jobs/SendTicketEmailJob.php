<?php

namespace App\Jobs;

use App\Models\BookingEmail;
use App\Services\TicketEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends one ticket email. Carries only the email record id; booking, recipient and files are
 * re-read and re-checked when the job runs. No automatic retries (tries = 1) so a slow provider
 * can never cause a second copy; failed sends are retried deliberately by a user.
 */
class SendTicketEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $bookingEmailId) {}

    public function handle(TicketEmailService $service): void
    {
        $service->deliver($this->bookingEmailId);
    }

    /** The worker itself failed (timeout, crash): record it so the UI never shows "Sending" forever. */
    public function failed(?Throwable $e): void
    {
        $email = BookingEmail::find($this->bookingEmailId);
        if ($email && in_array($email->status->value, ['queued', 'sending'], true)) {
            app(TicketEmailService::class)->markFailed($email, $e ?? new \RuntimeException('Queue job failed'));
        }
    }
}
