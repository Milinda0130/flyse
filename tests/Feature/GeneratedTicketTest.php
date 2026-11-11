<?php

namespace Tests\Feature;

use App\Mail\TicketEmail;
use App\Models\Airline;
use App\Models\Booking;
use App\Models\BookingEmail;
use App\Models\Document;
use App\Services\AirlineLogoService;
use App\Services\GeneratedTicketService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The agency ticket PDF created from booking records while the official e-ticket has not arrived. */
class GeneratedTicketTest extends TestCase
{
    use RefreshDatabase;

    /** Entered by staff when creating the PDF (different codes, to prove both are printed). */
    private const CODES = ['reservation_code' => '9VKNT2', 'airline_pnr' => 'EYX4Q7'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    /** The round trip from the sample ticket: CMB-AUH-CPH and back, Etihad, one passenger. */
    private function sampleBooking(bool $withTicket = true): Booking
    {
        $this->actingAsRole('staff');
        app(SettingsService::class)->update(['agency_name' => 'Flyse Travels AB', 'agency_address' => "Lärdomsgatan 9, Göteborg, Västra Götaland\nCounty, 417 56, Sweden",
            'agency_phone' => '+46735265341', 'agency_email' => 'tickets@example.com']);
        $booking = $this->createBooking(['status' => 'confirmed', 'pnr' => '9VKNT2']);
        $booking->flights()->delete();
        $booking->passengers()->delete();
        $p = $booking->passengers()->create(['full_name_as_document' => 'SESATHPURA D KARUNARATHNA MR']);
        foreach ([
            ['EY391', 'CMB', 'AUH', '2026-07-21 16:20', '2026-07-21 19:20', 'Asia/Colombo', 'Asia/Dubai'],
            ['EY177', 'AUH', 'CPH', '2026-07-22 02:15', '2026-07-22 07:00', 'Asia/Dubai', 'Europe/Copenhagen'],
            ['EY178', 'CPH', 'AUH', '2026-10-07 11:10', '2026-10-07 19:25', 'Europe/Copenhagen', 'Asia/Dubai'],
            ['EY394', 'AUH', 'CMB', '2026-10-07 20:50', '2026-10-08 03:00', 'Asia/Dubai', 'Asia/Colombo'],
        ] as $i => [$no, $from, $to, $dep, $arr, $tzFrom, $tzTo]) {
            $booking->flights()->create(['segment_order' => $i + 1, 'airline' => 'Etihad Airways', 'flight_number' => $no, 'origin_code' => $from,
                'destination_code' => $to, 'departure_at' => $dep, 'arrival_at' => $arr, 'origin_timezone' => $tzFrom, 'destination_timezone' => $tzTo,
                'cabin_class' => 'economy', 'baggage_allowance' => '25kg']);
        }
        if ($withTicket) {
            $booking->tickets()->create(['passenger_id' => $p->id, 'ticket_number' => '6072415804988', 'airline_pnr' => '9VKNT2', 'issued_at' => '2026-05-28']);
        }

        return $booking->fresh();
    }

    public function test_template_shows_the_booking_records_like_the_agency_ticket(): void
    {
        $booking = $this->sampleBooking();
        $service = app(GeneratedTicketService::class);
        $data = $service->viewData($booking, $booking->passengers->first(), self::CODES);

        $this->assertSame('9VKNT2', $data['reservationCode']);
        $this->assertSame('EYX4Q7', $data['airlinePnr']);
        $this->assertSame('2026-05-28', $data['issueDate']);
        $this->assertSame(['EY 391', 'EY 177', 'EY 178', 'EY 394'], array_column($data['segments'], 'flight'));
        // Durations from the recorded airport timezones.
        $this->assertSame(['04h 30m', '06h 45m', '06h 15m', '04h 40m'], array_column($data['segments'], 'duration'));
        $this->assertSame('Tuesday, 21 Jul 2026', $data['segments'][0]['date']);
        $this->assertSame('Kastrup', $data['segments'][1]['to']['name']);
        $this->assertSame('HK', $data['segments'][0]['status']);

        $html = view('pdf.ticket', $data)->render();
        // The ticket number is never printed.
        $this->assertStringNotContainsString('6072415804988', $html);
        $this->assertStringNotContainsStringIgnoringCase('Ticket number', $html);
        foreach (['E-TICKET', 'SESATHPURA D KARUNARATHNA MR', 'Reservation code', '9VKNT2', 'Airline PNR', 'EYX4Q7', 'Etihad Airways', 'Checked Baggage', '25kg', 'IMPORTANT', 'Lärdomsgatan 9'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        // Internal data never appears.
        foreach (['690', 'supplier', 'N1234567', 'margin'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, strip_tags(preg_replace('#<style.*?</style>#s', '', $html)), $secret);
        }

        $pdf = $service->render($booking, $booking->passengers->first(), self::CODES);
        $this->assertStringStartsWith('%PDF-', $pdf);
        if ($out = env('TICKET_PDF_SAMPLE_OUT')) {
            file_put_contents($out, $pdf);
        }
    }

    public function test_without_a_ticket_number_it_is_still_an_e_ticket_and_nothing_is_invented(): void
    {
        $booking = $this->sampleBooking(withTicket: false);
        $booking->flights()->update(['origin_timezone' => null, 'baggage_allowance' => null]);
        $data = app(GeneratedTicketService::class)->viewData($booking->fresh(), $booking->passengers->first(), self::CODES);

        $this->assertSame(now()->format('Y-m-d'), $data['issueDate'], 'date the document is issued');
        $this->assertSame([null, null, null, null], array_column($data['segments'], 'duration'));
        $html = view('pdf.ticket', $data)->render();
        $this->assertStringContainsString('E-TICKET', $html);
        $this->assertStringNotContainsString('ITINERARY', $html);
        $this->assertStringContainsString('EYX4Q7', $html);
        // The internal booking reference is not printed.
        $this->assertStringNotContainsString($booking->booking_code, $html);
        $this->assertStringNotContainsString('Checked Baggage', $html);
    }

    public function test_airline_logo_is_printed_with_the_airline_name_on_each_flight(): void
    {
        $booking = $this->sampleBooking();
        $etihad = Airline::create(['name' => 'Etihad Airways', 'iata_code' => 'EY']);
        app(AirlineLogoService::class)->store($etihad, UploadedFile::fake()->image('EY.png', 300, 80));
        Airline::create(['name' => 'Qatar Airways', 'iata_code' => 'QR']);
        [, $byCode, $noLogo, $unknown] = $booking->flights->sortBy('segment_order')->values()->all();
        $byCode->update(['airline' => 'Etihad']);
        $noLogo->update(['airline' => 'Qatar Airways', 'flight_number' => 'QR655']);
        $unknown->update(['airline' => 'Unknown Air', 'flight_number' => 'ZZ123']);

        $data = app(GeneratedTicketService::class)->viewData($booking->fresh(), $booking->passengers->first(), self::CODES);
        [$name, $code, $plain, $none] = $data['segments'];

        $this->assertStringStartsWith('data:image/png;base64,', $name['logo'], 'matched by airline name');
        $this->assertSame($name['logo'], $code['logo'], 'matched by the flight number designator');
        $this->assertSame('Etihad', $code['airline'], 'the recorded airline name is printed');
        $this->assertNull($plain['logo'], 'airline has no uploaded logo');
        $this->assertNull($none['logo'], 'airline is not in the list');

        $html = view('pdf.ticket', $data)->render();
        $this->assertMatchesRegularExpression('#<img src="data:image/png;base64,[^"]+" alt="Etihad Airways"[^>]*>\s*<div[^>]*>EY 391</div>\s*<div[^>]*>Etihad Airways</div>#', $html);
        $this->assertSame(2, substr_count($html, 'data:image/png;base64,'), 'no agency logo is set, so only the two Etihad flights carry one');
        $this->assertStringContainsString('Unknown Air', $html);

        $pdf = app(GeneratedTicketService::class)->render($booking->fresh(), $booking->passengers->first(), self::CODES);
        $this->assertStringStartsWith('%PDF-', $pdf);
        if ($out = env('TICKET_LOGO_PDF_SAMPLE_OUT')) {
            file_put_contents($out, $pdf);
        }
    }

    public function test_generate_store_verify_and_email_without_changing_the_booking(): void
    {
        Mail::fake();
        $booking = $this->sampleBooking();
        $booking->customer->update(['email' => 'sesath@example.com']);

        $preview = "/api/v1/bookings/{$booking->id}/generated-tickets/preview?passenger_id={$booking->passengers->first()->id}";
        $this->getJson($preview)->assertStatus(422)->assertJsonValidationErrors(['reservation_code', 'airline_pnr']);
        $this->get($preview.'&'.http_build_query(self::CODES))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(0, Document::count(), 'preview saves nothing');

        $this->postJson("/api/v1/bookings/{$booking->id}/generated-tickets", ['reservation_code' => '9VK-NT2!', 'airline_pnr' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['reservation_code', 'airline_pnr']);
        $doc = $this->postJson("/api/v1/bookings/{$booking->id}/generated-tickets", ['reservation_code' => ' 9vknt2 ', 'airline_pnr' => 'eyx4q7'])->assertCreated()
            ->assertJsonCount(1)
            ->assertJsonPath('0.document_type', 'alternative_ticket')
            ->assertJsonPath('0.source_type', 'agency_generated')
            ->assertJsonPath('0.verification_status', 'verified')
            ->assertJsonPath('0.verified_by.id', auth()->id())
            ->assertJsonPath('0.original_name', 'ticket-EYX4Q7-sesathpura-d-karunarathna-mr.pdf')
            ->json('0');
        Storage::disk('documents')->assertExists(Document::find($doc['id'])->storage_key);

        // Created by staff from the booking records: can be emailed straight away.
        $send = fn () => $this->postJson("/api/v1/bookings/{$booking->id}/ticket-email", ['email_type' => 'alternative', 'document_ids' => [$doc['id']],
            'confirm_reviewed' => true, 'idempotency_key' => (string) Str::uuid()]);
        $send()->assertCreated()->assertJsonPath('status', 'sent');
        // A rejected one can no longer be sent.
        $this->patchJson("/api/v1/documents/{$doc['id']}/verification", ['status' => 'rejected', 'note' => 'Wrong flight'])->assertOk();
        $send()->assertStatus(422)->assertJsonValidationErrors('document_ids');
        Mail::assertSent(TicketEmail::class, fn ($m) => $m->hasTo('sesath@example.com') && count($m->attachments()) === 1);
        $this->assertSame(1, BookingEmail::count());

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status->value);
        $this->assertSame(1, $booking->tickets()->count(), 'no ticket records created');
    }

    public function test_uploaded_logo_is_printed_on_the_pdf_and_only_the_owner_manages_it(): void
    {
        $booking = $this->sampleBooking();
        $png = UploadedFile::fake()->image('logo.png', 240, 160);

        $this->post('/api/v1/settings/logo', ['logo' => $png], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAsRole('owner');
        $this->post('/api/v1/settings/logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('logo');
        $key = $this->post('/api/v1/settings/logo', ['logo' => $png], ['Accept' => 'application/json'])->assertOk()->json('agency_logo_file');
        Storage::disk('documents')->assertExists($key);
        $this->get('/api/v1/settings/logo')->assertOk();
        // The settings form cannot point the logo at another file.
        $this->putJson('/api/v1/settings', ['agency_name' => 'Flyse Travels AB', 'agency_logo_file' => '../../.env', 'default_currency' => 'LKR', 'currencies' => ['LKR'], 'payment_methods' => ['Cash'], 'expense_categories' => ['Rent']])->assertOk()
            ->assertJsonPath('agency_logo_file', $key);

        $data = app(GeneratedTicketService::class)->viewData($booking, $booking->passengers->first(), self::CODES);
        $this->assertStringStartsWith('data:image/png;base64,', $data['agency']['logo']);
        $pdf = app(GeneratedTicketService::class)->render($booking, $booking->passengers->first(), self::CODES);
        $this->assertLessThan(300 * 1024, strlen($pdf), 'small enough to email (fonts subset)');

        $this->deleteJson('/api/v1/settings/logo')->assertOk()->assertJsonPath('agency_logo_file', '');
        Storage::disk('documents')->assertMissing($key);
    }

    public function test_generation_rules_and_permissions(): void
    {
        $booking = $this->sampleBooking();
        $other = $this->createBooking(['status' => 'confirmed']);

        $this->postJson("/api/v1/bookings/{$booking->id}/generated-tickets", ['passenger_ids' => [$other->passengers->first()->id], ...self::CODES])
            ->assertStatus(422)->assertJsonValidationErrors('passenger_ids');
        // The source cannot be claimed for an uploaded file.
        $this->post("/api/v1/bookings/{$booking->id}/documents", ['files' => [$this->eTicketPdf()], 'document_type' => 'alternative_ticket', 'source_type' => 'agency_generated'],
            ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('source_type');

        $booking->flights()->delete();
        $this->postJson("/api/v1/bookings/{$booking->id}/generated-tickets", self::CODES)->assertStatus(422)->assertJsonValidationErrors('booking');

        $inquiry = $this->createBooking(['status' => 'inquiry']);
        $this->postJson("/api/v1/bookings/{$inquiry->id}/generated-tickets", self::CODES)->assertStatus(422)
            ->assertJsonFragment(['This booking is still an inquiry. Confirm the reservation first.']);

        $this->actingAsRole('readonly');
        $this->postJson("/api/v1/bookings/{$other->id}/generated-tickets", self::CODES)->assertForbidden();
        $this->get("/api/v1/bookings/{$other->id}/generated-tickets/preview?passenger_id={$other->passengers->first()->id}", ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(0, Document::count());
    }
}
