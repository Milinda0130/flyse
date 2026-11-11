<?php

namespace Tests\Feature;

use App\Jobs\SendTicketEmailJob;
use App\Mail\TicketEmail;
use App\Models\Booking;
use App\Models\BookingEmail;
use App\Models\Customer;
use App\Models\Document;
use App\Services\TicketEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Emailing issued ticket PDFs to the customer (feature spec section 13). */
class TicketEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    /** A ticketed booking with 2 passengers, 2 segments and one e-ticket PDF per passenger. */
    private function ticketedBooking(array $customer = []): array
    {
        $this->actingAsRole('owner');
        $booking = $this->createBooking(['status' => 'ticketed', 'pnr' => 'QX7K2M']);
        $booking->customer->update(['email' => 'saman@example.com', ...$customer]);
        $docs = [];
        foreach ($booking->passengers as $i => $p) {
            $key = 'tests/'.Str::uuid().'.pdf';
            Storage::disk('documents')->put($key, "%PDF-1.4\n% passenger ".($i + 1)."\n%%EOF\n");
            $doc = Document::create(['booking_id' => $booking->id, 'passenger_id' => $p->id, 'document_type' => 'e_ticket',
                'original_name' => 'ticket-passenger-'.($i + 1).'.pdf', 'storage_key' => $key, 'mime_type' => 'application/pdf', 'size_bytes' => 40]);
            $booking->tickets()->create(['passenger_id' => $p->id, 'ticket_number' => '157210000000'.($i + 1), 'e_ticket_document_id' => $doc->id]);
            $docs[] = $doc;
        }

        return [$booking->fresh(), $docs];
    }

    private function send(Booking $booking, array $docIds, array $extra = [])
    {
        return $this->postJson("/api/v1/bookings/{$booking->id}/ticket-email", ['document_ids' => $docIds, 'idempotency_key' => (string) Str::uuid(), ...$extra]);
    }

    public function test_two_pdfs_are_sent_in_one_email_to_the_registered_customer(): void
    {
        Mail::fake();
        [$booking, $docs] = $this->ticketedBooking();

        $this->getJson("/api/v1/bookings/{$booking->id}/ticket-email")->assertOk()
            ->assertJsonPath('customer.email', 'saman@example.com')
            ->assertJsonCount(2, 'documents')
            ->assertJsonPath('documents.0.readable', true)
            ->assertJsonPath('documents.0.passengers.0', 'SAMAN PERERA');

        // Queue is "sync" in tests, so the job runs and the status is final.
        $this->send($booking, [$docs[0]->id, $docs[1]->id])->assertCreated()
            ->assertJsonPath('status', 'sent')->assertJsonPath('attachment_count', 2)
            ->assertJsonPath('recipient', 'saman@example.com')->assertJsonPath('recipient_is_override', false);

        Mail::assertSentCount(1);
        Mail::assertSent(TicketEmail::class, function (TicketEmail $mail) {
            $files = collect($mail->attachments())->map(fn ($a) => [$a->as, $a->mime])->all();

            return $files === [['ticket-passenger-1.pdf', 'application/pdf'], ['ticket-passenger-2.pdf', 'application/pdf']]
                && $mail->hasTo('saman@example.com')
                && $mail->envelope()->subject === 'Your Flight Tickets – Booking '.$mail->booking->booking_code;
        });

        $email = BookingEmail::sole();
        $this->assertSame('sent', $email->status->value);
        $this->assertNotNull($email->sent_at);
        // Originals are untouched.
        Storage::disk('documents')->assertExists([$docs[0]->storage_key, $docs[1]->storage_key]);
        // The attempt appears in the booking's activity history.
        $this->getJson("/api/v1/bookings/{$booking->id}/activity")->assertOk()->assertJsonFragment(['entity_type' => 'BookingEmail']);
    }

    public function test_real_mime_message_contains_both_pdf_attachments(): void
    {
        // No fake: the "array" transport builds and keeps the real MIME message.
        [$booking, $docs] = $this->ticketedBooking();
        $this->send($booking, [$docs[0]->id, $docs[1]->id])->assertCreated()->assertJsonPath('status', 'sent');

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages, 'exactly one email');
        $message = $messages->first()->getOriginalMessage();
        $attachments = $message->getAttachments();
        $this->assertCount(2, $attachments);
        $this->assertSame(['ticket-passenger-1.pdf', 'ticket-passenger-2.pdf'], array_map(fn ($a) => $a->getFilename(), $attachments));
        foreach ($attachments as $a) {
            $this->assertSame('application/pdf', $a->getContentType());
            $this->assertStringStartsWith('%PDF-1.4', $a->getBody());
        }
        $this->assertSame('saman@example.com', $message->getTo()[0]->getAddress());
        $this->assertNotNull($message->getTextBody(), 'plain-text alternative present');
        $this->assertStringContainsString('Dear Saman Perera,', $message->getHtmlBody());
    }

    public function test_email_content_is_accurate_and_has_no_internal_data(): void
    {
        [$booking, $docs] = $this->ticketedBooking();
        $snapshot = [['document_id' => $docs[0]->id, 'name' => 'ticket-passenger-1.pdf', 'size_bytes' => 40, 'passengers' => ['SAMAN PERERA'], 'tickets' => ['1572100000001']]];
        $html = (new TicketEmail($booking, 'Your Flight Tickets – Booking '.$booking->booking_code, $snapshot))->render();

        $this->assertStringContainsString('Dear Saman Perera,', $html);
        $this->assertStringContainsString('Your Flight Tickets Are Ready', $html);
        $this->assertStringContainsString($booking->booking_code, $html);
        $this->assertStringContainsString('QX7K2M', $html);
        $this->assertStringContainsString('QR655', $html);
        $this->assertStringContainsString('QR167', $html);
        $this->assertStringContainsString('CMB → DOH → ARN', $html);
        $this->assertStringContainsString('NIROSHA PERERA', $html);
        $this->assertStringContainsString('ticket-passenger-1.pdf', $html);
        // Never internal data: costs, margins, passport numbers, notes.
        // ("margin" alone would match the CSS property, so check the visible labels.)
        foreach (['690,000', '690000', '60,000', 'gross margin', 'Est. margin', 'supplier', 'N1234567', 'passport'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, strip_tags(preg_replace('#<style.*?</style>#s', '', $html)), $secret);
        }
    }

    public function test_template_handles_missing_fields_long_values_and_escapes_html(): void
    {
        $this->actingAsRole('owner');
        $customer = Customer::create(['full_name' => '<script>alert(1)</script> Wickramasinghe Arachchige Don Pathirana Mudiyanselage', 'email' => 'x@example.com']);
        $booking = $this->createBooking(['status' => 'confirmed', 'pnr' => null, 'flights' => []]);
        $booking->update(['customer_id' => $customer->id, 'pnr' => null]);
        $booking->flights()->delete();
        $longName = str_repeat('Very_Long_Ticket_File_Name_', 6).'.pdf';
        $html = (new TicketEmail($booking->fresh(), 'Your Travel Documents – Booking '.$booking->booking_code, [
            ['document_id' => 1, 'name' => $longName, 'size_bytes' => 40, 'passengers' => [], 'tickets' => []],
        ]))->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Your Travel Documents', $html, 'not described as issued tickets before ticketing');
        $this->assertStringNotContainsString('AIRLINE PNR', $html, 'missing PNR is hidden, not faked');
        $this->assertStringNotContainsString('Your flight itinerary', $html);
        $this->assertStringContainsString('Requested trip', $html);
        $this->assertStringContainsString($longName, $html);
    }

    public function test_recipient_rules(): void
    {
        Mail::fake();
        [$booking, $docs] = $this->ticketedBooking(['email' => null]);

        $this->send($booking, [$docs[0]->id])->assertStatus(422)->assertJsonValidationErrors('recipient');
        $this->send($booking, [$docs[0]->id], ['recipient' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('recipient');

        // A one-off address is allowed, flagged, and does not change the customer record.
        $this->send($booking, [$docs[0]->id], ['recipient' => 'family@example.org'])->assertCreated()
            ->assertJsonPath('recipient_is_override', true);
        $this->assertNull($booking->customer->fresh()->email);
        Mail::assertSent(TicketEmail::class, fn ($m) => $m->hasTo('family@example.org'));
    }

    public function test_attachment_selection_rules(): void
    {
        Mail::fake();
        [$booking, $docs] = $this->ticketedBooking();
        [$other, $otherDocs] = $this->ticketedBooking();

        $this->send($booking, [])->assertStatus(422)->assertJsonValidationErrors('document_ids');
        $this->send($booking, [$otherDocs[0]->id])->assertStatus(422)->assertJsonValidationErrors('document_ids');

        // Only e-ticket PDFs qualify: an invoice of the same booking is refused.
        Storage::disk('documents')->put('tests/invoice.pdf', '%PDF-1.4');
        $invoice = Document::create(['booking_id' => $booking->id, 'document_type' => 'customer_invoice', 'original_name' => 'invoice.pdf',
            'storage_key' => 'tests/invoice.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 8]);
        $this->send($booking, [$invoice->id])->assertStatus(422);
        $this->getJson("/api/v1/bookings/{$booking->id}/ticket-email")->assertJsonCount(2, 'documents');

        // An e-ticket uploaded under Documents (not linked to any ticket yet) is still eligible.
        Storage::disk('documents')->put('tests/loose.pdf', '%PDF-1.4 loose');
        Document::create(['booking_id' => $booking->id, 'passenger_id' => $booking->passengers->first()->id, 'document_type' => 'e_ticket',
            'original_name' => 'loose.pdf', 'storage_key' => 'tests/loose.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 14]);
        $this->getJson("/api/v1/bookings/{$booking->id}/ticket-email")->assertOk()->assertJsonCount(3, 'documents')
            ->assertJsonPath('documents.2.passengers', ['SAMAN PERERA'])->assertJsonPath('documents.2.tickets', []);

        // Missing file.
        Storage::disk('documents')->delete($docs[1]->storage_key);
        $this->send($booking, [$docs[0]->id, $docs[1]->id])->assertStatus(422)
            ->assertJsonFragment(['ticket-passenger-2.pdf is missing from storage or cannot be read. Upload it again.']);

        // Size limit.
        config(['ticket_email.max_total_mb' => 0]);
        $this->send($booking, [$docs[0]->id])->assertStatus(422)->assertJsonValidationErrors('document_ids');

        Mail::assertNothingSent();
        $this->assertSame(0, BookingEmail::count());
    }

    public function test_unauthorized_users_cannot_send_or_view(): void
    {
        Mail::fake();
        [$booking, $docs] = $this->ticketedBooking();
        $this->actingAsRole('readonly');
        $this->getJson("/api/v1/bookings/{$booking->id}/ticket-email")->assertForbidden();
        $this->send($booking, [$docs[0]->id])->assertForbidden();
        $this->getJson("/api/v1/bookings/{$booking->id}/emails")->assertForbidden();
        $this->postJson("/api/v1/bookings/{$booking->id}/ticket-email/preview", ['document_ids' => [$docs[0]->id]])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_cancelled_booking_and_missing_mail_config_block_sending(): void
    {
        Mail::fake();
        [$booking, $docs] = $this->ticketedBooking();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => null]);
        $this->send($booking, [$docs[0]->id])->assertStatus(422)->assertJsonValidationErrors('mail');
        config(['mail.default' => 'array']);

        $booking->forceFill(['status' => 'cancelled'])->saveQuietly();
        $this->send($booking, [$docs[0]->id])->assertStatus(422)->assertJsonValidationErrors('booking');
        Mail::assertNothingSent();
    }

    public function test_duplicate_protection_and_deliberate_resend(): void
    {
        Mail::fake();
        [$booking, $docs] = $this->ticketedBooking();
        $key = (string) Str::uuid();
        $ids = [$docs[0]->id, $docs[1]->id];

        // Same request twice (double click / network retry): one email.
        $this->send($booking, $ids, ['idempotency_key' => $key])->assertCreated();
        $this->send($booking, $ids, ['idempotency_key' => $key])->assertOk();
        Mail::assertSentCount(1);

        // A new request for the same tickets needs explicit confirmation.
        $this->send($booking, $ids)->assertStatus(422)->assertJsonValidationErrors('confirm_resend');
        $this->send($booking, $ids, ['confirm_resend' => true])->assertCreated();
        Mail::assertSentCount(2);
        $this->assertSame(2, BookingEmail::count());

        // A duplicated queue job cannot send a second copy.
        app(TicketEmailService::class)->deliver(BookingEmail::first()->id);
        Mail::assertSentCount(2);
    }

    public function test_queued_status_then_failure_is_recorded_and_retry_succeeds(): void
    {
        Mail::fake();
        Queue::fake();
        [$booking, $docs] = $this->ticketedBooking();

        $this->send($booking, [$docs[0]->id])->assertCreated()->assertJsonPath('status', 'queued');
        Queue::assertPushed(SendTicketEmailJob::class, 1);
        Mail::assertNothingSent();

        // The file disappears before the worker runs: recorded as failed with a readable reason.
        $content = Storage::disk('documents')->get($docs[0]->storage_key);
        Storage::disk('documents')->delete($docs[0]->storage_key);
        $email = BookingEmail::sole();
        (new SendTicketEmailJob($email->id))->handle(app(TicketEmailService::class));
        $email->refresh();
        $this->assertSame('failed', $email->status->value);
        $this->assertStringContainsString('missing from storage', $email->failure_reason);
        $this->assertStringNotContainsString('Exception', $email->failure_reason);
        Mail::assertNothingSent();

        // Restore the file and retry deliberately.
        Storage::disk('documents')->put($docs[0]->storage_key, $content);
        $this->postJson("/api/v1/booking-emails/{$email->id}/retry")->assertOk()->assertJsonPath('status', 'queued');
        (new SendTicketEmailJob($email->id))->handle(app(TicketEmailService::class));
        $this->assertSame('sent', $email->refresh()->status->value);
        $this->assertSame(2, $email->attempts);
        Mail::assertSentCount(1);

        $this->getJson("/api/v1/bookings/{$booking->id}/emails")->assertOk()
            ->assertJsonPath('0.status', 'sent')->assertJsonPath('0.attempts', 2)->assertJsonPath('0.delivered_by_provider', false);
    }

    public function test_preview_renders_the_real_template_without_sending(): void
    {
        Mail::fake();
        [$booking, $docs] = $this->ticketedBooking();
        $r = $this->postJson("/api/v1/bookings/{$booking->id}/ticket-email/preview", ['document_ids' => [$docs[0]->id, $docs[1]->id]])->assertOk()
            ->assertJsonPath('recipient', 'saman@example.com')
            ->assertJsonPath('total_bytes', 80);
        $this->assertStringContainsString('Your Flight Tickets Are Ready', $r->json('html'));
        $this->assertStringContainsString('ticket-passenger-2.pdf', $r->json('html'));
        Mail::assertNothingSent();
        $this->assertSame(0, BookingEmail::count());
    }
}
