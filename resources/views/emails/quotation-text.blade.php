YOUR FLIGHT QUOTATION
=====================

Dear {!! $customerName ?: 'Customer' !!},

Thank you for your enquiry. Please find attached our flight quotation with the available options, prices, baggage allowances and fare conditions.
@if($customerMessage)

{!! $customerMessage !!}
@endif

Quotation reference: {!! $reference !!}
Route: {!! $route !!}
Departure: {!! $outbound !!}
@if($return)
Return: {!! $return !!}
@endif
Passengers: {!! $passengers !!} · {!! $cabin !!}
@if($validUntil)
Valid until: {!! $validUntil !!}
@endif
@if($filename)

Attached: {!! $filename !!}
@endif

Please note: this is a quotation only, not a flight reservation or an issued ticket. Prices and seat availability may change until the booking is confirmed and the ticket is issued. Reply to this email or contact us to book.

Kind regards,
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
