<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FlightQuotation;
use App\Services\GeneratedTicketService;
use App\Services\QuotationPdfService;
use Database\Seeders\FlyseBrandingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** FLYSE TRAVELS AB details and logo on the PDFs, in customer emails and in the app. */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_agency_details_and_logo_appear_on_pdfs_and_emails(): void
    {
        Storage::fake('documents');
        $this->seed(FlyseBrandingSeeder::class);
        $this->actingAsRole('owner');

        // Quotation PDF + email.
        $customer = Customer::create(['full_name' => 'Anna Svensson', 'email' => 'anna@example.com']);
        $q = $this->postJson('/api/v1/flight-quotations', [
            'customer_id' => $customer->id, 'trip_type' => 'one_way', 'origin_city' => 'Stockholm', 'origin_code' => 'ARN',
            'destination_city' => 'Colombo', 'destination_code' => 'CMB', 'outbound_date' => '2026-12-10', 'adults' => 1,
            'cabin_class' => 'economy', 'currency' => 'SEK',
            'options' => [['label' => 'Option A', 'adult_fare' => '5200', 'adult_tax' => '1300', 'segments' => [
                ['direction' => 'outbound', 'airline_name' => 'Qatar Airways', 'flight_number' => 'QR168', 'from_code' => 'ARN', 'to_code' => 'DOH', 'departure_at' => '2026-12-10T15:30', 'arrival_at' => '2026-12-10T23:00'],
                ['direction' => 'outbound', 'airline_name' => 'Qatar Airways', 'flight_number' => 'QR668', 'from_code' => 'DOH', 'to_code' => 'CMB', 'departure_at' => '2026-12-11T02:00', 'arrival_at' => '2026-12-11T08:55'],
            ]]],
        ])->assertCreated()->json();

        $data = app(QuotationPdfService::class)->viewData(FlightQuotation::find($q['id']));
        $this->assertSame('FLYSE TRAVELS AB', $data['agency']['name']);
        $this->assertStringStartsWith('data:image/png;base64,', $data['agency']['logo']);
        $html = view('pdf.quotation', $data)->render();
        foreach (['FLYSE TRAVELS AB', 'Lärdomsgatan 9, Göteborg, Västra Götaland County', '417 56, Sweden', '+46735265341', 'geethnsl@gmail.com'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        if ($out = env('BRAND_QUOTE_OUT')) {
            file_put_contents($out, app(QuotationPdfService::class)->render(FlightQuotation::find($q['id'])));
        }

        $this->postJson("/api/v1/flight-quotations/{$q['id']}/email", ['idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('status', 'sent');
        $message = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertStringContainsString('cid:', $message->getHtmlBody(), 'logo embedded in the email');
        $inline = collect($message->getAttachments())->filter(fn ($a) => $a->getDisposition() === 'inline');
        $this->assertCount(1, $inline);
        $this->assertSame('FLYSE TRAVELS AB', $message->getFrom()[0]->getName());
        $this->assertSame('geethnsl@gmail.com', $message->getReplyTo()[0]->getAddress());

        // E-ticket PDF built from a booking.
        $booking = $this->createBooking(['status' => 'confirmed', 'pnr' => '9VKNT2']);
        $ticket = app(GeneratedTicketService::class);
        $codes = ['reservation_code' => '9VKNT2', 'airline_pnr' => '9VKNT2'];
        $tdata = $ticket->viewData($booking, $booking->passengers->first(), $codes);
        $this->assertStringStartsWith('data:image/png;base64,', $tdata['agency']['logo']);
        $this->assertStringContainsString('Lärdomsgatan 9', view('pdf.ticket', $tdata)->render());
        if ($out = env('BRAND_TICKET_OUT')) {
            file_put_contents($out, $ticket->render($booking, $booking->passengers->first(), $codes));
        }

        // The app shows the agency name.
        $this->getJson('/api/v1/meta')->assertJsonPath('agency_name', 'FLYSE TRAVELS AB');
    }
}
