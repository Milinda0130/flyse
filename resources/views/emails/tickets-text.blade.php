{!! $heading !!}
{!! str_repeat('=', mb_strlen($heading)) !!}

Dear {!! $customerName ?: 'Customer' !!},

@if($alternative)
Please find the attached ticket document for your reference.@if($officialPending) The official ticket document normally provided through our company's standard process is still pending.@endif Our team has attached the available document for your review.

Please note: the attached document was not issued through our agency's standard ticketing process.@if($officialPending) We will send you the official ticket document when we receive it.@endif Please check your booking details with the airline before you travel.
@elseif($issued)
Thank you for booking with us. Your flight ticket documents are attached to this email. Please review the details and keep the attached PDFs for your journey.
@else
Thank you for booking with us. The travel documents for your booking are attached to this email. Please review them and keep them for your records.
@endif

Booking reference (our agency): {!! $bookingCode !!}
@if($pnr)
Airline PNR: {!! $pnr !!}
@endif
@if($statusLabel)
Status: {!! $statusLabel !!}
@endif
Attached: {!! count($attachmentsList) !!} PDF {!! count($attachmentsList) === 1 ? 'file' : 'files' !!}
@if(count($segments))

YOUR FLIGHT ITINERARY
@foreach($segments as $i => $s)
{!! $i + 1 !!}. {!! $s['airline'] !!} {!! $s['flight_number'] !!}: {!! $s['origin'] !!} -> {!! $s['destination'] !!}@if($s['cancelled']) (CANCELLED)@endif

   Departs {!! $s['departure'] ?? 'time to be confirmed' !!}@if($s['arrival']) | Arrives {!! $s['arrival'] !!}@endif

@if($s['cabin'] || $s['baggage'])
   @if($s['cabin'])Cabin: {!! $s['cabin'] !!}@endif @if($s['baggage'])Baggage: {!! $s['baggage'] !!}@endif

@endif
@endforeach
Times are local airport times. @if($alternative)Please check the attached document and the airline's current itinerary.@else Your ticket PDF is the authoritative record.@endif
@elseif($requestedRoute)

Requested trip: {!! $requestedRoute !!}
@endif
@if(count($passengers))

PASSENGERS
@foreach($passengers as $i => $name)
{!! $i + 1 !!}. {!! $name !!}
@endforeach
@endif

{!! $alternative ? 'ATTACHED TICKET DOCUMENT' : 'YOUR TICKET DOCUMENTS (attached)' !!}
@foreach($attachmentsList as $a)
- {!! $a['name'] !!}@if(count($a['passengers'])) ({!! implode(', ', $a['passengers']) !!})@endif

@endforeach

BEFORE YOUR JOURNEY
- Please review the attached ticket details carefully.
@if($alternative)
- Check your itinerary and travel requirements with the airline before departure.
@endif
- Verify the passenger names and travel dates against your travel documents.
- Check the airline's current baggage allowance and airport check-in requirements.
- Please contact us if you notice any incorrect information.

If you have any questions about your booking, please contact our team.
{!! $agency['name'] !!}
@if($agency['phone'])
Phone: {!! $agency['phone'] !!}
@endif
@if($agency['email'])
Email: {!! $agency['email'] !!}
@endif
@if($agency['website'])
Website: {!! $agency['website'] !!}
@endif

This email was sent regarding your flight booking {!! $bookingCode !!}.
