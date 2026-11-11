<?php

namespace Tests\Feature;

use App\Mail\QuotationMail;
use App\Models\Airline;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FlightQuotation;
use App\Models\QuotationEmail;
use App\Services\QuotationPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** Manual flight quotations: prices, itinerary, PDF versions, emails and airline logos. */
class FlightQuotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function customer(array $attrs = []): Customer
    {
        return Customer::create(['full_name' => 'Anna Svensson', 'email' => 'anna@example.com', 'phone' => '+46 70 123 4567', ...$attrs]);
    }

    private function airline(string $name, string $iata, bool $logo = true): Airline
    {
        $airline = Airline::create(['name' => $name, 'iata_code' => $iata]);
        if ($logo) {
            $this->post("/api/v1/airlines/{$airline->id}/logo", ['logo' => UploadedFile::fake()->image("$iata.png", 300, 80)], ['Accept' => 'application/json'])->assertOk();
        }

        return $airline->fresh();
    }

    /** The sample: Stockholm ⇄ Oslo, Norwegian out, SAS back, 1 adult, SEK. */
    private function samplePayload(Customer $customer, array $overrides = []): array
    {
        $dy = Airline::where('iata_code', 'DY')->first();
        $sk = Airline::where('iata_code', 'SK')->first();

        return array_replace_recursive([
            'customer_id' => $customer->id, 'trip_type' => 'round_trip',
            'origin_country' => 'Sweden', 'origin_city' => 'Stockholm', 'origin_code' => 'arn',
            'destination_country' => 'Norway', 'destination_city' => 'Oslo', 'destination_code' => 'OSL',
            'outbound_date' => '2026-10-17', 'return_date' => '2026-10-17', 'adults' => 1, 'children' => 0, 'infants' => 0,
            'cabin_class' => 'economy', 'currency' => 'SEK', 'internal_notes' => 'SECRET-INTERNAL-NOTE',
            'options' => [[
                'label' => 'Option A', 'currency' => 'SEK', 'adult_fare' => '1845', 'adult_tax' => '544', 'supplier_cost' => '2100',
                'internal_notes' => 'OPTION-INTERNAL', 'checked_bag_status' => 'not_included', 'cabin_bag_status' => 'not_included',
                'personal_item_status' => 'included', 'personal_item_allowance' => '1 personal item', 'refundability' => 'non_refundable',
                'extras' => [['title' => 'Rebookable (Optional)', 'amount' => '300', 'details' => "Rebook the date and time up to 24 hours before departure.\nValid for your departure, return trip or both."]],
                'segments' => [
                    ['direction' => 'outbound', 'airline_id' => $dy?->id, 'airline_name' => 'Norwegian', 'operating_airline_name' => 'Norwegian Air International', 'flight_number' => 'dy805', 'from_code' => 'ARN', 'to_code' => 'OSL',
                        'departure_at' => '2026-10-17T09:10', 'arrival_at' => '2026-10-17T10:10', 'departure_tz' => 'Europe/Stockholm', 'arrival_tz' => 'Europe/Oslo', 'aircraft' => '7M8'],
                    ['direction' => 'return', 'airline_id' => $sk?->id, 'airline_name' => 'SAS', 'flight_number' => 'SK886', 'from_code' => 'OSL', 'to_code' => 'ARN',
                        'departure_at' => '2026-10-17T19:50', 'arrival_at' => '2026-10-17T20:50', 'duration_minutes' => 60, 'aircraft' => 'CR9'],
                ],
            ]],
        ], $overrides);
    }

    public function test_return_quotation_prices_itinerary_and_customer_facing_pdf(): void
    {
        $this->actingAsRole('staff');
        $this->airline('Norwegian', 'DY');
        $this->airline('SAS', 'SK');
        $customer = $this->customer();

        $res = $this->postJson('/api/v1/flight-quotations', $this->samplePayload($customer))->assertCreated()
            ->assertJsonPath('reference', 'FQ-'.now()->year.'-0001')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('origin_code', 'ARN')
            ->assertJsonPath('options.0.pricing.adult_per_person', '2389.00')
            ->assertJsonPath('options.0.pricing.party_total', '2389.00')
            ->assertJsonPath('options.0.pricing.margin', '289.00')
            ->assertJsonPath('options.0.segments.0.flight_number', 'DY805')
            ->assertJsonPath('options.0.segments.0.duration', '1h 00m')
            ->assertJsonPath('options.0.segments.1.duration', '1h 00m')
            ->assertJsonPath('meta.problems', []);
        $id = $res->json('id');

        // Ready, then the official PDF version.
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'ready'])->assertOk()->assertJsonPath('status', 'ready');
        $this->postJson("/api/v1/flight-quotations/$id/pdf")->assertCreated()->assertJsonPath('version', 1)->assertJsonPath('filename', 'Flight-Quotation-FQ-'.now()->year.'-0001.pdf');
        // Unchanged content: same version.
        $this->postJson("/api/v1/flight-quotations/$id/pdf")->assertOk()->assertJsonPath('version', 1);
        $this->get("/api/v1/flight-quotations/$id/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $q = FlightQuotation::find($id);
        $data = app(QuotationPdfService::class)->viewData($q);
        $html = view('pdf.quotation', $data)->render();
        foreach (['FLIGHT QUOTATION', 'Stockholm (ARN) ⇄ Oslo (OSL)', '17 Oct 2026', 'Outbound', 'Return', 'DY805', 'SK886', 'Total price', '2,389 SEK', 'Non-refundable', 'Rebookable (Optional)', '+300 SEK per person', 'Not included', '1 personal item', 'does not constitute a flight reservation or ticket', 'Operated by Norwegian Air International'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        foreach (['Fare per person', 'Tax &amp; carrier charges per person', '1,845 SEK', '544 SEK', 'per adult', 'for all passengers'] as $text) {
            $this->assertStringNotContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('data:image/png;base64,', $html, 'airline logos embedded');
        $visible = strip_tags(preg_replace('#<style.*?</style>#s', '', $html));
        foreach (['SECRET-INTERNAL-NOTE', 'OPTION-INTERNAL', '2,100', '2100', '289', 'margin', 'supplier'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $visible, $secret);
        }
        $pdf = app(QuotationPdfService::class)->render($q);
        $this->assertStringStartsWith('%PDF-', $pdf);
        if ($out = env('QUOTATION_PDF_SAMPLE_OUT')) {
            file_put_contents($out, $pdf);
        }

        // Read-only users see no internal costs.
        $this->actingAsRole('readonly');
        $this->getJson("/api/v1/flight-quotations/$id")->assertOk()->assertJsonMissingPath('internal_notes')
            ->assertJsonMissingPath('options.0.supplier_cost')->assertJsonMissingPath('options.0.pricing.margin');
    }

    public function test_one_way_connecting_multi_option_multi_passenger_and_currencies(): void
    {
        $this->actingAsRole('owner');
        $qr = $this->airline('Qatar Airways', 'QR');
        $ek = $this->airline('Emirates', 'EK', logo: false);
        $customer = $this->customer();
        $payload = [
            'customer_id' => $customer->id, 'trip_type' => 'one_way', 'origin_country' => 'Sri Lanka', 'origin_city' => 'Colombo', 'origin_code' => 'CMB',
            'destination_country' => 'United Kingdom', 'destination_city' => 'London', 'destination_code' => 'LHR', 'outbound_date' => '2026-10-16',
            'return_date' => '2026-10-30', 'adults' => 2, 'children' => 1, 'infants' => 1, 'cabin_class' => 'economy', 'currency' => 'LKR',
            'options' => [
                ['label' => 'Option A – via Doha', 'adult_fare' => '150000', 'adult_tax' => '45000.50', 'child_fare' => '120000', 'child_tax' => '45000.50', 'infant_fare' => '15000', 'infant_tax' => '5000',
                    'checked_bag_status' => 'included', 'checked_bag_allowance' => '30 kg', 'segments' => [
                        ['direction' => 'outbound', 'airline_id' => $qr->id, 'flight_number' => 'QR655', 'from_code' => 'CMB', 'to_code' => 'DOH', 'departure_at' => '2026-10-16T04:05', 'arrival_at' => '2026-10-16T06:55', 'departure_tz' => 'Asia/Colombo', 'arrival_tz' => 'Asia/Qatar'],
                        // Different airline on the second segment.
                        ['direction' => 'outbound', 'airline_id' => $ek->id, 'flight_number' => 'EK5', 'from_code' => 'DOH', 'to_code' => 'LHR', 'departure_at' => '2026-10-16T08:10', 'arrival_at' => '2026-10-16T13:20', 'departure_tz' => 'Asia/Qatar', 'arrival_tz' => 'Europe/London'],
                    ]],
                ['label' => 'Option B – priced in GBP', 'currency' => 'GBP', 'adult_fare' => '600', 'segments' => [
                    ['direction' => 'outbound', 'airline_name' => 'SriLankan Airlines', 'flight_number' => 'UL503', 'from_code' => 'CMB', 'to_code' => 'LHR', 'departure_at' => '2026-10-16T13:00', 'arrival_at' => '2026-10-16T19:50'],
                ]],
            ],
        ];
        $res = $this->postJson('/api/v1/flight-quotations', $payload)->assertCreated()
            ->assertJsonPath('return_date', null)
            // 2 x 195000.50 + 1 x 165000.50 + 1 x 20000
            ->assertJsonPath('options.0.pricing.party_total', '575001.50')
            ->assertJsonPath('options.0.segments.1.airline_name', 'Emirates')
            ->assertJsonPath('options.0.segments.1.layover', '1h 15m')
            ->assertJsonPath('options.0.legs.outbound.travel_time', '13h 45m')
            ->assertJsonPath('options.1.currency', 'GBP')
            // Child and infant fares missing: total unknown, never 0.
            ->assertJsonPath('options.1.pricing.party_total', null);
        $this->assertContains('Option B – priced in GBP: enter the child fare.', $res->json('meta.problems'));

        // Mixed currencies: no combined range in the list.
        $this->getJson('/api/v1/flight-quotations')->assertOk()->assertJsonPath('data.0.price_range', null)->assertJsonPath('data.0.price_range_mixed', true);

        // Cannot be marked ready while incomplete.
        $id = $res->json('id');
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'ready'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/flight-quotations/$id/pdf")->assertStatus(422);

        // A long itinerary still renders (the preview works for drafts).
        $payload['options'][1] = [...$payload['options'][1], 'child_fare' => '500', 'infant_fare' => '60'];
        for ($i = 2; $i < 5; $i++) {
            $payload['options'][$i] = [...$payload['options'][0], 'label' => 'Option '.chr(65 + $i)];
        }
        $this->putJson("/api/v1/flight-quotations/$id", $payload)->assertOk()->assertJsonPath('meta.problems', []);
        $pdf = $this->get("/api/v1/flight-quotations/$id/preview")->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        if ($out = env('QUOTATION_PDF_LONG_OUT')) {
            file_put_contents($out, $pdf);
        }
        $html = view('pdf.quotation', app(QuotationPdfService::class)->viewData(FlightQuotation::find($id)))->render();
        $this->assertStringContainsString('575,001.50 LKR', $html);
        $this->assertStringNotContainsString('per adult', $html);
        $this->assertStringNotContainsString('Fare per person', $html);
        $this->assertStringNotContainsString('Return</div>', $html);
        $this->assertStringContainsString('Emirates', $html, 'text fallback when an airline has no logo');
        $this->assertStringContainsString('Connection in Doha (DOH)', $html);
    }

    public function test_quotation_without_a_customer(): void
    {
        Mail::fake();
        $this->actingAsRole('staff');
        $payload = $this->samplePayload($this->customer());
        $payload['customer_id'] = null;

        $res = $this->postJson('/api/v1/flight-quotations', $payload)->assertCreated()
            ->assertJsonPath('customer_id', null)->assertJsonPath('customer', null)->assertJsonPath('meta.problems', []);
        $id = $res->json('id');
        $this->getJson('/api/v1/flight-quotations')->assertOk()->assertJsonPath('data.0.customer', null);
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'ready'])->assertOk();

        // No saved address: the email must be typed.
        $this->postJson("/api/v1/flight-quotations/$id/email", ['idempotency_key' => (string) Str::uuid()])->assertStatus(422)
            ->assertJsonFragment(['Enter the email address to send this quotation to.']);
        $this->postJson("/api/v1/flight-quotations/$id/email", ['recipient' => 'traveller@example.org', 'idempotency_key' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('status', 'sent')->assertJsonPath('recipient_is_override', true);
        Mail::assertSent(QuotationMail::class, fn ($m) => $m->hasTo('traveller@example.org'));

        $html = view('pdf.quotation', app(QuotationPdfService::class)->viewData(FlightQuotation::find($id)))->render();
        $this->assertStringNotContainsString('Prepared for', $html);
        $this->assertStringContainsString('FLIGHT QUOTATION', $html);

        // Any booking can be linked when no customer is set.
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'accepted', 'accepted_option_id' => $res->json('options.0.id')])->assertOk();
        $booking = $this->createBooking(['status' => 'confirmed']);
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'converted', 'booking_id' => $booking->id])->assertOk();
    }

    public function test_validation_rules(): void
    {
        $this->actingAsRole('staff');
        $customer = $this->customer();
        $base = $this->samplePayload($customer);

        $this->postJson('/api/v1/flight-quotations', [...$base, 'return_date' => '2026-10-10'])->assertStatus(422)->assertJsonValidationErrors('return_date');
        $this->postJson('/api/v1/flight-quotations', [...$base, 'adults' => 0])->assertStatus(422)->assertJsonValidationErrors('adults');
        $this->postJson('/api/v1/flight-quotations', [...$base, 'adults' => 1, 'infants' => 2])->assertStatus(422)->assertJsonValidationErrors('infants');
        $this->postJson('/api/v1/flight-quotations', [...$base, 'currency' => 'XYZ'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $bad = $base;
        $bad['options'][0]['segments'][0]['flight_number'] = 'not a flight';
        $bad['options'][0]['segments'][0]['to_code'] = 'ARN';
        $bad['options'][0]['adult_fare'] = '-5';
        $this->postJson('/api/v1/flight-quotations', $bad)->assertStatus(422)
            ->assertJsonValidationErrors(['options.0.segments.0.flight_number', 'options.0.segments.0.to_code', 'options.0.adult_fare']);
        $oneWay = [...$base, 'trip_type' => 'one_way'];
        $this->postJson('/api/v1/flight-quotations', $oneWay)->assertStatus(422)->assertJsonValidationErrors('options.0.segments.1.direction');

        // Inconsistent itinerary: saved, with warnings (nothing is corrected automatically).
        $odd = $base;
        $odd['options'][0]['segments'][0]['departure_at'] = '2026-10-18T09:10';
        $odd['options'][0]['segments'][0]['arrival_at'] = '2026-10-18T10:10';
        $warnings = $this->postJson('/api/v1/flight-quotations', $odd)->assertCreated()->json('meta.warnings');
        $this->assertNotEmpty(collect($warnings)->filter(fn ($w) => str_contains($w, 'requested date')));
        $this->assertNotEmpty(collect($warnings)->filter(fn ($w) => str_contains($w, 'return flight departs before')));
    }

    public function test_email_sends_the_stored_pdf_version_and_records_history(): void
    {
        Mail::fake();
        $this->actingAsRole('staff');
        $customer = $this->customer();
        $id = $this->postJson('/api/v1/flight-quotations', $this->samplePayload($customer))->json('id');

        // A complete draft can be emailed directly (no separate "ready" step needed).

        $html = $this->postJson("/api/v1/flight-quotations/$id/email/preview")->assertOk()
            ->assertJsonPath('subject', 'Your Flight Quotation – FQ-'.now()->year.'-0001 – ARN to OSL')->json('html');
        $this->assertStringContainsString('Dear Anna Svensson,', $html);
        $this->assertStringNotContainsString('SECRET-INTERNAL-NOTE', $html);
        Mail::assertNothingSent();

        $key = (string) Str::uuid();
        $this->postJson("/api/v1/flight-quotations/$id/email", ['idempotency_key' => $key])->assertCreated()
            ->assertJsonPath('status', 'sent')->assertJsonPath('pdf_version', 1)->assertJsonPath('recipient', 'anna@example.com');
        $this->postJson("/api/v1/flight-quotations/$id/email", ['idempotency_key' => $key])->assertOk();
        Mail::assertSentCount(1);
        Mail::assertSent(QuotationMail::class, function (QuotationMail $m) {
            $a = $m->attachments();

            return $m->hasTo('anna@example.com') && count($a) === 1 && $a[0]->as === 'Flight-Quotation-FQ-'.now()->year.'-0001.pdf' && $a[0]->mime === 'application/pdf';
        });
        $this->getJson("/api/v1/flight-quotations/$id")->assertJsonPath('status', 'sent');

        // Same version again needs confirmation; after an edit a new version is created and sent.
        $this->postJson("/api/v1/flight-quotations/$id/email", ['idempotency_key' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('confirm_resend');
        $this->putJson("/api/v1/flight-quotations/$id", $this->samplePayload($customer, ['message_to_customer' => 'Updated prices for you.']))->assertOk();
        $this->postJson("/api/v1/flight-quotations/$id/email", ['idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('pdf_version', 2);
        $this->assertSame([1, 2], QuotationEmail::orderBy('id')->with('pdf')->get()->map(fn ($e) => $e->pdf->version)->all(), 'each email keeps its exact version');
        $this->get("/api/v1/flight-quotations/$id/pdfs/".QuotationEmail::first()->flight_quotation_pdf_id)->assertOk();

        // Several versions and emails at once (lazy loading is disabled outside production).
        $this->getJson("/api/v1/flight-quotations/$id")->assertOk()->assertJsonCount(2, 'meta.pdfs')
            ->assertJsonPath('meta.pdfs.0.filename', 'Flight-Quotation-FQ-'.now()->year.'-0001-v2.pdf');
        $this->getJson("/api/v1/flight-quotations/$id/email")->assertOk()->assertJsonCount(2, 'history')->assertJsonPath('latest_pdf.version', 2);
        $this->getJson("/api/v1/flight-quotations/$id/emails")->assertOk();
        $this->getJson('/api/v1/flight-quotations')->assertOk();
        $this->get("/api/v1/flight-quotations/$id/pdf")->assertOk();

        // No booking or reservation is created by quoting or emailing.
        $this->assertSame(0, Booking::count());
    }

    public function test_real_mime_message_carries_the_pdf_and_a_text_part(): void
    {
        // No fake: the "array" transport keeps the real message.
        $this->actingAsRole('staff');
        $id = $this->postJson('/api/v1/flight-quotations', $this->samplePayload($this->customer()))->json('id');
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'ready'])->assertOk();
        $this->postJson("/api/v1/flight-quotations/$id/email", ['idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('status', 'sent');

        $message = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertCount(1, $message->getAttachments());
        $this->assertSame('application/pdf', $message->getAttachments()[0]->getContentType());
        $this->assertStringStartsWith('%PDF-', $message->getAttachments()[0]->getBody());
        $this->assertStringContainsString('Dear Anna Svensson,', $message->getTextBody());
        $this->assertStringContainsString('not a flight reservation', $message->getHtmlBody());
    }

    public function test_email_failures_and_invalid_recipient(): void
    {
        $this->actingAsRole('staff');
        $customer = $this->customer(['email' => null]);
        $id = $this->postJson('/api/v1/flight-quotations', $this->samplePayload($customer))->json('id');
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'ready'])->assertOk();

        $this->postJson("/api/v1/flight-quotations/$id/email", ['idempotency_key' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('recipient');
        $this->postJson("/api/v1/flight-quotations/$id/email", ['recipient' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('recipient');

        Mail::shouldReceive('to')->andThrow(new TransportException('535 auth failed for secret-user'));
        $this->postJson("/api/v1/flight-quotations/$id/email", ['recipient' => 'anna@example.com'])->assertCreated()
            ->assertJsonPath('status', 'failed');
        $email = QuotationEmail::sole();
        $this->assertStringNotContainsString('secret-user', $email->failure_reason);
        $this->assertSame('ready', FlightQuotation::find($id)->status->value, 'a failed send does not mark it sent');
    }

    public function test_status_workflow_duplicate_and_permissions(): void
    {
        $this->actingAsRole('staff');
        $customer = $this->customer();
        $res = $this->postJson('/api/v1/flight-quotations', $this->samplePayload($customer))->assertCreated();
        $id = $res->json('id');
        $optionId = $res->json('options.0.id');

        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'accepted', 'accepted_option_id' => $optionId])->assertStatus(422);
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'ready'])->assertOk();
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'accepted'])->assertStatus(422)->assertJsonValidationErrors('accepted_option_id');
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'accepted', 'accepted_option_id' => $optionId])->assertOk()->assertJsonPath('accepted_option_id', $optionId);
        // Accepted cannot be edited; it is never a booking by itself.
        $this->putJson("/api/v1/flight-quotations/$id", $this->samplePayload($customer))->assertStatus(422);
        $this->assertSame(0, Booking::count());

        $booking = $this->createBooking(['status' => 'confirmed']);
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'converted', 'booking_id' => $booking->id])->assertStatus(422)->assertJsonValidationErrors('booking_id');
        $booking->update(['customer_id' => $customer->id]);
        $this->postJson("/api/v1/flight-quotations/$id/status", ['status' => 'converted', 'booking_id' => $booking->id])->assertOk()
            ->assertJsonPath('status', 'converted')->assertJsonPath('booking.id', $booking->id);
        $this->assertSame('confirmed', $booking->fresh()->status->value, 'linking never changes the booking');

        $copy = $this->postJson("/api/v1/flight-quotations/$id/duplicate")->assertCreated()->assertJsonPath('status', 'draft')
            ->assertJsonPath('options.0.segments.1.flight_number', 'SK886')
            ->assertJsonPath('options.0.extras.0.title', 'Rebookable (Optional)')
            ->assertJsonPath('options.0.extras.0.amount', '300')
            ->assertJsonPath('options.0.pricing.adult_per_person', '2389.00')
            ->assertJsonPath('accepted_option_id', null)->assertJsonPath('booking', null)->json();
        $this->assertIsArray($copy['options'][0]['extras']);
        // The copy can be edited and saved again unchanged.
        $this->putJson("/api/v1/flight-quotations/{$copy['id']}", $this->samplePayload($customer))->assertOk()->assertJsonPath('options.0.extras.0.title', 'Rebookable (Optional)');
        $this->assertNotSame($copy['reference'], $res->json('reference'));

        // Filters.
        $this->getJson('/api/v1/flight-quotations?status=converted')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/flight-quotations?q=oslo')->assertJsonCount(2, 'data');

        $this->actingAsRole('readonly');
        $this->getJson("/api/v1/flight-quotations/$id")->assertOk();
        $this->postJson('/api/v1/flight-quotations', $this->samplePayload($customer))->assertForbidden();
        $this->putJson("/api/v1/flight-quotations/{$copy['id']}", $this->samplePayload($customer))->assertForbidden();
        $this->postJson("/api/v1/flight-quotations/$id/duplicate")->assertForbidden();
        $this->postJson("/api/v1/flight-quotations/{$copy['id']}/email")->assertForbidden();
        $this->postJson('/api/v1/airlines', ['name' => 'Test Air'])->assertForbidden();
    }

    public function test_airline_logos(): void
    {
        $this->actingAsRole('staff');
        $id = $this->postJson('/api/v1/airlines', ['name' => 'Norwegian', 'iata_code' => 'dy', 'icao_code' => 'nax'])->assertCreated()
            ->assertJsonPath('iata_code', 'DY')->assertJsonPath('has_logo', false)->json('id');
        $this->postJson('/api/v1/airlines', ['name' => 'Copy', 'iata_code' => 'DY'])->assertStatus(422)->assertJsonValidationErrors('iata_code');

        // Only images; SVG and other files are refused.
        $this->post("/api/v1/airlines/$id/logo", ['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('logo');
        $this->post("/api/v1/airlines/$id/logo", ['logo' => UploadedFile::fake()->create('logo.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);

        // A large image is re-encoded as a small PNG (aspect ratio kept).
        $this->post("/api/v1/airlines/$id/logo", ['logo' => UploadedFile::fake()->image('logo.jpg', 1200, 300)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('has_logo', true);
        $png = $this->get("/api/v1/airlines/$id/logo")->assertOk()->assertHeader('Content-Type', 'image/png')->streamedContent();
        [$w, $h] = getimagesizefromstring($png);
        $this->assertSame([600, 150], [$w, $h]);

        // In use: cannot be deleted (owner), only deactivated.
        $customer = $this->customer();
        $this->postJson('/api/v1/flight-quotations', $this->samplePayload($customer))->assertCreated();
        $this->putJson("/api/v1/airlines/$id", ['name' => 'Norwegian', 'iata_code' => 'DY', 'is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->deleteJson("/api/v1/airlines/$id")->assertForbidden();
        $this->actingAsRole('owner');
        $this->deleteJson("/api/v1/airlines/$id")->assertStatus(422);
        $unused = Airline::create(['name' => 'Unused Air']);
        $this->deleteJson("/api/v1/airlines/{$unused->id}")->assertNoContent();
    }
}
