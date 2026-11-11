<?php

namespace Tests\Feature;

use App\Jobs\SendTicketEmailJob;
use App\Mail\TicketEmail;
use App\Models\Booking;
use App\Models\BookingEmail;
use App\Models\Document;
use App\Services\BookingWorkflowService;
use App\Services\TicketEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Sending an alternative (e.g. customer-provided) ticket PDF while the agency's official
 * e-ticket is not available. Complements TicketEmailTest, which covers the official workflow.
 */
class AlternativeTicketEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    /** A confirmed booking with no official e-ticket at all. */
    private function bookingWithoutTicket(): Booking
    {
        $this->actingAsRole('staff');
        $booking = $this->createBooking(['status' => 'confirmed', 'pnr' => 'QX7K2M']);
        $booking->customer->update(['email' => 'saman@example.com']);

        return $booking->fresh();
    }

    private function pdf(string $name = 'customer-ticket.pdf', int $kb = 2): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('x', $kb * 1024)."\n%%EOF");
    }

    private function upload(Booking $booking, array $extra = [], ?UploadedFile $file = null)
    {
        return $this->post("/api/v1/bookings/{$booking->id}/documents", [
            'files' => [$file ?? $this->pdf()],
            'document_type' => 'alternative_ticket',
            'source_type' => 'customer_provided',
            ...$extra,
        ], ['Accept' => 'application/json']);
    }

    private function send(Booking $booking, array $docIds, array $extra = [])
    {
        return $this->postJson("/api/v1/bookings/{$booking->id}/ticket-email", [
            'email_type' => 'alternative', 'document_ids' => $docIds, 'confirm_reviewed' => true,
            'idempotency_key' => (string) Str::uuid(), ...$extra,
        ]);
    }

    /** Scenarios 2, 3, 5, 6, 16: upload + verify + send with no official ticket; booking stays as it was. */
    public function test_alternative_pdf_is_uploaded_verified_and_sent_without_an_official_ticket(): void
    {
        Mail::fake();
        $booking = $this->bookingWithoutTicket();
        $passenger = $booking->passengers->first();

        $doc = $this->upload($booking, ['passenger_id' => $passenger->id, 'source_description' => 'Forwarded by the customer', 'notes' => 'INTERNAL: customer booked directly'])
            ->assertCreated()
            ->assertJsonPath('0.document_type', 'alternative_ticket')
            ->assertJsonPath('0.source_type', 'customer_provided')
            ->assertJsonPath('0.verification_status', 'pending')
            ->assertJsonPath('0.booking_id', $booking->id)
            ->json('0');

        $info = $this->getJson("/api/v1/bookings/{$booking->id}/ticket-email")->assertOk()
            ->assertJsonCount(0, 'documents')
            ->assertJsonCount(1, 'alternative_documents')
            ->assertJsonPath('alternative_documents.0.sendable', false);
        $this->assertSame('Your Ticket Document – Booking '.$booking->booking_code, $info->json('subjects.alternative'));

        // Pending verification: cannot be sent.
        $this->send($booking, [$doc['id']])->assertStatus(422)->assertJsonValidationErrors('document_ids');

        $this->patchJson("/api/v1/documents/{$doc['id']}/verification", ['status' => 'verified'])->assertOk()
            ->assertJsonPath('verification_status', 'verified')->assertJsonPath('verified_by.id', auth()->id());

        $this->send($booking, [$doc['id']], ['official_pending' => true])->assertCreated()
            ->assertJsonPath('status', 'sent')->assertJsonPath('email_type', 'alternative')
            ->assertJsonPath('recipient', 'saman@example.com')->assertJsonPath('reviewed_confirmed', true)
            ->assertJsonPath('attachments.0.source_type', 'customer_provided');

        Mail::assertSent(TicketEmail::class, function (TicketEmail $mail) use ($booking) {
            $files = collect($mail->attachments())->map(fn ($a) => [$a->as, $a->mime])->all();

            return $mail->hasTo('saman@example.com')
                && $files === [['customer-ticket.pdf', 'application/pdf']]
                && $mail->envelope()->subject === 'Your Ticket Document – Booking '.$booking->booking_code;
        });

        $email = BookingEmail::sole();
        $this->assertSame($booking->customer_id, $email->customer_id);
        $this->assertTrue($email->official_pending);

        // Sending never changes the booking or creates ticket records.
        $booking->refresh();
        $this->assertSame('confirmed', $booking->status->value);
        $this->assertSame(0, $booking->tickets()->count());
        $this->assertNotEmpty(app(BookingWorkflowService::class)->ticketingProblems($booking));
    }

    /** Scenarios 6, 18, 19: wording, no internal data, plain-text alternative. */
    public function test_alternative_email_wording_and_privacy(): void
    {
        $booking = $this->bookingWithoutTicket();
        $id = $this->upload($booking, ['reviewed' => true, 'source_type' => 'other_authorized', 'source_description' => 'SECRET-SOURCE travel desk', 'notes' => 'INTERNAL-NOTE-123'])
            ->assertJsonPath('0.verification_status', 'verified')->json('0.id');

        // No fake: the array transport keeps the real MIME message.
        $this->send($booking, [$id], ['official_pending' => true])->assertCreated()->assertJsonPath('status', 'sent');
        $message = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $html = $message->getHtmlBody();
        $text = $message->getTextBody();

        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Please find the attached ticket document for your reference.', $body);
            $this->assertStringContainsString('still pending', $body);
            $this->assertStringContainsString('not issued through our agency', $body);
            $this->assertStringContainsString($booking->booking_code, $body);
            $this->assertStringNotContainsString('Tickets Are Ready', $body);
            $visible = strip_tags(preg_replace('#<style.*?</style>#s', '', $body));
            foreach (['INTERNAL-NOTE-123', 'SECRET-SOURCE', 'other_authorized', 'Other authorized', 'customer_provided', 'verified', '690,000', '60,000', 'supplier', 'passport'] as $secret) {
                $this->assertStringNotContainsStringIgnoringCase($secret, $visible, $secret);
            }
        }
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame('application/pdf', $message->getAttachments()[0]->getContentType());

        // Without the staff confirmation, the email does not claim the official ticket is pending.
        $preview = $this->postJson("/api/v1/bookings/{$booking->id}/ticket-email/preview", ['email_type' => 'alternative', 'document_ids' => [$id]])
            ->assertOk()->json('html');
        $this->assertStringNotContainsString('still pending', $preview);
        $this->assertStringContainsString('Your Ticket Document', $preview);
    }

    /** Scenarios 9, 10, 11: authorization, cross-booking, workflow separation, rejected documents. */
    public function test_authorization_and_document_eligibility_rules(): void
    {
        Mail::fake();
        $booking = $this->bookingWithoutTicket();
        $other = $this->createBooking(['status' => 'confirmed']);
        $otherDoc = $this->upload($other, ['reviewed' => true])->json('0.id');
        $doc = $this->upload($booking)->json('0.id');

        // Another booking's document is never attachable.
        $this->send($booking, [$otherDoc])->assertStatus(422)->assertJsonValidationErrors('document_ids');

        // An alternative document cannot travel through the official workflow, and vice versa.
        $this->send($booking, [$doc], ['email_type' => 'official'])->assertStatus(422)->assertJsonValidationErrors('document_ids');

        // Rejected (with a reason) cannot be sent.
        $this->patchJson("/api/v1/documents/{$doc}/verification", ['status' => 'rejected'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->patchJson("/api/v1/documents/{$doc}/verification", ['status' => 'rejected', 'note' => 'Wrong travel date'])->assertOk();
        $this->send($booking, [$doc])->assertStatus(422)->assertJsonFragment(['customer-ticket.pdf was rejected during review and cannot be sent.']);

        // Verified, but the sender did not confirm the review.
        $this->patchJson("/api/v1/documents/{$doc}/verification", ['status' => 'verified'])->assertOk();
        $this->send($booking, [$doc], ['confirm_reviewed' => false])->assertStatus(422)->assertJsonValidationErrors('confirm_reviewed');

        // Official agency documents have no verification step.
        Storage::disk('documents')->put('tests/inv.pdf', '%PDF-1.4');
        $invoice = Document::create(['booking_id' => $booking->id, 'document_type' => 'customer_invoice', 'original_name' => 'invoice.pdf',
            'storage_key' => 'tests/inv.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 8]);
        $this->patchJson("/api/v1/documents/{$invoice->id}/verification", ['status' => 'verified'])->assertStatus(422);
        $this->send($booking, [$invoice->id])->assertStatus(422);

        // Read-only users cannot upload, verify or send.
        $this->actingAsRole('readonly');
        $this->upload($booking)->assertForbidden();
        $this->patchJson("/api/v1/documents/{$doc}/verification", ['status' => 'verified'])->assertForbidden();
        $this->send($booking, [$doc])->assertForbidden();

        Mail::assertNothingSent();
        $this->assertSame(0, BookingEmail::count());
    }

    /** Scenarios 7, 8, 12, and cancelled bookings. */
    public function test_validation_failures(): void
    {
        Mail::fake();
        $booking = $this->bookingWithoutTicket();

        // Upload rules: PDF only (by content), source required, description required for "other".
        // A real file (fake uploads take their MIME type from the name): PNG content named .pdf.
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $this->upload($booking, [], new UploadedFile($path, 'ticket.pdf', null, null, true))
            ->assertStatus(422)->assertJsonValidationErrors('files');
        $this->upload($booking, ['source_type' => null])->assertStatus(422)->assertJsonValidationErrors('source_type');
        $this->upload($booking, ['source_type' => 'other_authorized'])->assertStatus(422)->assertJsonValidationErrors('source_description');
        $this->upload($booking, [], $this->pdf('huge.pdf', 10300))->assertStatus(422)->assertJsonValidationErrors('files.0');
        // A source cannot be put on an ordinary document.
        $this->upload($booking, ['document_type' => 'e_ticket'])->assertStatus(422)->assertJsonValidationErrors('source_type');

        $doc = $this->upload($booking, ['reviewed' => true])->assertCreated()->json('0.id');

        // Attachment size limit.
        config(['ticket_email.max_total_mb' => 0]);
        $this->send($booking, [$doc])->assertStatus(422)->assertJsonValidationErrors('document_ids');
        config(['ticket_email.max_total_mb' => 18]);

        // Missing file: no silent substitution, no empty email.
        $key = Document::find($doc)->storage_key;
        $content = Storage::disk('documents')->get($key);
        Storage::disk('documents')->delete($key);
        $this->send($booking, [$doc])->assertStatus(422)->assertJsonFragment(['customer-ticket.pdf is missing from storage or cannot be read. Upload it again.']);
        Storage::disk('documents')->put($key, $content);

        // No customer email.
        $booking->customer->update(['email' => null]);
        $this->send($booking, [$doc])->assertStatus(422)->assertJsonValidationErrors('recipient');
        $booking->customer->update(['email' => 'saman@example.com']);

        // Cancelled booking.
        $booking->forceFill(['status' => 'cancelled'])->saveQuietly();
        $this->send($booking, [$doc])->assertStatus(422)->assertJsonValidationErrors('booking');

        Mail::assertNothingSent();
        $this->assertSame(0, BookingEmail::count());
    }

    /** Scenarios 13, 14, 15: queued is not "sent", provider failure is recorded, duplicates are prevented. */
    public function test_queue_failure_and_duplicate_protection(): void
    {
        Mail::fake();
        Queue::fake();
        $booking = $this->bookingWithoutTicket();
        $doc = $this->upload($booking, ['reviewed' => true])->json('0.id');
        $key = (string) Str::uuid();

        $this->send($booking, [$doc], ['idempotency_key' => $key])->assertCreated()->assertJsonPath('status', 'queued')->assertJsonPath('sent_at', null);
        $this->send($booking, [$doc], ['idempotency_key' => $key])->assertOk();
        Queue::assertPushed(SendTicketEmailJob::class, 1);
        $this->send($booking, [$doc])->assertStatus(422)->assertJsonValidationErrors('confirm_resend');
        Mail::assertNothingSent();

        // The mail provider rejects the message: recorded as failed with a safe reason.
        $email = BookingEmail::sole();
        Mail::shouldReceive('to')->andThrow(new TransportException('535 Authentication failed for secret-user'));
        app(TicketEmailService::class)->deliver($email->id);
        $email->refresh();
        $this->assertSame('failed', $email->status->value);
        $this->assertStringContainsString('mail server could not send', $email->failure_reason);
        $this->assertStringNotContainsString('secret-user', $email->failure_reason);
        $this->assertNull($email->sent_at);
    }

    /** Scenario 17 (+20): the official ticket arrives later; nothing earlier is lost. */
    public function test_official_ticket_can_be_added_later_without_losing_history(): void
    {
        Mail::fake();
        $booking = $this->bookingWithoutTicket();
        $alt = $this->upload($booking, ['reviewed' => true])->json('0.id');
        $this->send($booking, [$alt])->assertCreated();

        // Official e-ticket arrives: issue tickets the normal way (this is what marks a booking Ticketed).
        $passenger = $booking->passengers->first();
        $this->post("/api/v1/bookings/{$booking->id}/documents", ['files' => [$this->pdf('official-eticket.pdf')], 'document_type' => 'e_ticket', 'passenger_id' => $passenger->id], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('0.verification_status', null)->assertJsonPath('0.source_type', null);

        $info = $this->getJson("/api/v1/bookings/{$booking->id}/ticket-email")->assertOk()
            ->assertJsonCount(1, 'documents')->assertJsonCount(1, 'alternative_documents')->assertJsonCount(1, 'history');
        $official = $info->json('documents.0.id');

        // An alternative PDF can't be linked to a ticket as its e-ticket.
        $this->postJson("/api/v1/bookings/{$booking->id}/tickets", ['passenger_id' => $passenger->id, 'ticket_number' => '1572100000001', 'e_ticket_document_id' => $alt])
            ->assertStatus(422);

        $this->postJson("/api/v1/bookings/{$booking->id}/ticket-email", ['document_ids' => [$official], 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('email_type', 'official');

        $this->assertSame(['alternative', 'official'], BookingEmail::orderBy('id')->get()->map(fn ($e) => $e->email_type->value)->all());
        $this->assertNotNull(Document::find($alt));
        Mail::assertSentCount(2);
    }
}
