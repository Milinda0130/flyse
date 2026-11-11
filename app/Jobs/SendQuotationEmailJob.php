<?php

namespace App\Jobs;

use App\Models\QuotationEmail;
use App\Services\QuotationEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Sends one quotation email. No automatic retries (a person retries failed sends deliberately). */
class SendQuotationEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $quotationEmailId) {}

    public function handle(QuotationEmailService $service): void
    {
        $service->deliver($this->quotationEmailId);
    }

    public function failed(?Throwable $e): void
    {
        $email = QuotationEmail::find($this->quotationEmailId);
        if ($email && in_array($email->status->value, ['queued', 'sending'], true)) {
            app(QuotationEmailService::class)->markFailed($email, $e ?? new \RuntimeException('Queue job failed'));
        }
    }
}
