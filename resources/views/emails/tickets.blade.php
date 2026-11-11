{{--
  Customer ticket email. Table layout + inline CSS for Gmail, Outlook and Apple Mail.
  All dynamic values use {{ }} (escaped). No scripts, no external CSS, no images required.
  Palette: navy #12305A, blue #2563EB, cyan #06B6D4, orange #F97316, background #F3F6FB.
--}}
@php
    $font = "font-family:'Segoe UI',Roboto,'Helvetica Neue',Helvetica,Arial,sans-serif;";
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $heading }}</title>
<style>
  body { margin:0; padding:0; width:100% !important; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
  table { border-collapse:collapse; mso-table-lspace:0; mso-table-rspace:0; }
  img { border:0; line-height:100%; outline:none; text-decoration:none; }
  a { color:#2563EB; }
  @media only screen and (max-width: 620px) {
    .container { width:100% !important; }
    .px { padding-left:18px !important; padding-right:18px !important; }
    .stack { display:block !important; width:100% !important; text-align:left !important; }
    .stack-gap { padding-top:10px !important; }
    .h1 { font-size:24px !important; line-height:30px !important; }
    .code { font-size:24px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#F3F6FB;">
{{-- Inbox preview line (hidden in the email body) --}}
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F3F6FB;">
  {{ $heading }} · Booking {{ $bookingCode }}@if($routeSummary) · {{ $routeSummary }}@endif
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F3F6FB" style="background-color:#F3F6FB;">
<tr><td align="center" style="padding:24px 12px;">

<table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">

  {{-- A. Header --}}
  <tr>
    <td class="px" bgcolor="#12305A" style="background-color:#12305A;background-image:linear-gradient(135deg,#12305A 0%,#1E4C8F 55%,#2563EB 100%);border-radius:16px 16px 0 0;padding:28px 32px 30px 32px;{{ $font }}">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td style="{{ $font }}">
            @include('emails.partials.logo', ['height' => 56])
            <span style="{{ $font }}font-size:15px;font-weight:600;color:#FFFFFF;letter-spacing:0.3px;">{{ $agency['name'] }}</span>
          </td>
          <td align="right" style="{{ $font }}">
            <span style="display:inline-block;background-color:#F97316;color:#FFFFFF;font-size:11px;font-weight:700;letter-spacing:1.2px;padding:5px 10px;border-radius:999px;">{{ $alternative ? 'TICKET DOCUMENT' : ($issued ? 'E-TICKET' : 'TRAVEL DOCUMENTS') }}</span>
          </td>
        </tr>
        <tr>
          <td colspan="2" style="padding-top:22px;{{ $font }}">
            <h1 class="h1" style="margin:0;{{ $font }}font-size:28px;line-height:34px;font-weight:700;color:#FFFFFF;">{{ $heading }}</h1>
            @if($routeSummary)
              <p style="margin:8px 0 0 0;{{ $font }}font-size:15px;line-height:22px;color:#BFE9F2;font-weight:600;letter-spacing:0.5px;word-break:break-word;">{{ $routeSummary }}</p>
            @endif
          </td>
        </tr>
      </table>
    </td>
  </tr>
  {{-- Accent strip --}}
  <tr><td height="4" bgcolor="#06B6D4" style="background-color:#06B6D4;background-image:linear-gradient(90deg,#06B6D4,#2563EB,#F97316);font-size:0;line-height:0;">&nbsp;</td></tr>

  {{-- Body card --}}
  <tr>
    <td class="px" bgcolor="#FFFFFF" style="background-color:#FFFFFF;padding:28px 32px 8px 32px;{{ $font }}">

      {{-- B. Greeting --}}
      <p style="margin:0 0 12px 0;{{ $font }}font-size:16px;line-height:24px;color:#172033;font-weight:600;word-break:break-word;">Dear {{ $customerName ?: 'Customer' }},</p>
      @if($alternative)
        <p style="margin:0 0 16px 0;{{ $font }}font-size:15px;line-height:23px;color:#344054;">Please find the attached ticket document for your reference.@if($officialPending) The official ticket document normally provided through our company's standard process is still pending.@endif Our team has attached the available document for your review.</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ECFEFF" style="background-color:#ECFEFF;border:1px solid #A5F3FC;border-left:4px solid #06B6D4;border-radius:10px;margin-bottom:22px;">
          <tr>
            <td style="padding:14px 16px;{{ $font }}font-size:13px;line-height:20px;color:#164E63;">
              <strong style="color:#12305A;">Please note:</strong> the attached document was not issued through our agency's standard ticketing process.@if($officialPending) We will send you the official ticket document when we receive it.@endif Please check your booking details with the airline before you travel.
            </td>
          </tr>
        </table>
      @elseif($issued)
        <p style="margin:0 0 22px 0;{{ $font }}font-size:15px;line-height:23px;color:#344054;">Thank you for booking with us. Your flight ticket documents are attached to this email. Please review the details and keep the attached PDFs for your journey.</p>
      @else
        <p style="margin:0 0 22px 0;{{ $font }}font-size:15px;line-height:23px;color:#344054;">Thank you for booking with us. The travel documents for your booking are attached to this email. Please review them and keep them for your records. We will contact you if anything else is needed.</p>
      @endif

      {{-- C. Booking reference card --}}
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#EEF4FF" style="background-color:#EEF4FF;border:1px solid #D6E4FF;border-radius:12px;">
        <tr>
          <td style="padding:18px 20px;{{ $font }}">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td class="stack" valign="top" style="{{ $font }}">
                  <div style="font-size:11px;font-weight:700;letter-spacing:1.2px;color:#2563EB;">BOOKING REFERENCE</div>
                  <div class="code" style="margin-top:4px;font-family:'Courier New',Courier,monospace;font-size:26px;font-weight:700;color:#12305A;letter-spacing:1px;">{{ $bookingCode }}</div>
                  <div style="margin-top:2px;font-size:12px;color:#667085;">Our agency reference</div>
                </td>
                @if($pnr)
                <td class="stack stack-gap" valign="top" align="right" style="{{ $font }}text-align:right;">
                  <div style="font-size:11px;font-weight:700;letter-spacing:1.2px;color:#2563EB;">AIRLINE PNR</div>
                  <div style="margin-top:4px;font-family:'Courier New',Courier,monospace;font-size:22px;font-weight:700;color:#12305A;letter-spacing:1px;">{{ $pnr }}</div>
                  <div style="margin-top:2px;font-size:12px;color:#667085;">Use this with the airline</div>
                </td>
                @endif
              </tr>
              <tr>
                <td colspan="2" style="padding-top:14px;border-top:1px solid #D6E4FF;{{ $font }}font-size:13px;line-height:20px;color:#344054;">
                  @if($statusLabel)<span style="display:inline-block;margin:6px 14px 0 0;"><strong style="color:#172033;">Status:</strong> {{ $statusLabel }}</span>@endif
                  <span style="display:inline-block;margin:6px 14px 0 0;"><strong style="color:#172033;">Attached:</strong> {{ count($attachmentsList) }} PDF {{ count($attachmentsList) === 1 ? 'file' : 'files' }}</span>
                  <span style="display:inline-block;margin:6px 0 0 0;"><strong style="color:#172033;">Date:</strong> {{ $emailDate }}</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      {{-- D. Itinerary (confirmed segments only) --}}
      @if(count($segments))
        <h2 style="margin:28px 0 12px 0;{{ $font }}font-size:17px;line-height:24px;color:#12305A;font-weight:700;">Your flight itinerary</h2>
        @foreach($segments as $i => $s)
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #E4E7EC;border-left:4px solid {{ $s['cancelled'] ? '#98A2B3' : '#06B6D4' }};border-radius:10px;margin-bottom:10px;">
            <tr>
              <td style="padding:14px 16px;{{ $font }}">
                <div style="font-size:12px;color:#667085;font-weight:600;letter-spacing:0.4px;word-break:break-word;">
                  FLIGHT {{ $i + 1 }} · {{ $s['airline'] }} · {{ $s['flight_number'] }}@if($s['cancelled']) · <span style="color:#B42318;">CANCELLED</span>@endif
                </div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">
                  <tr>
                    <td class="stack" width="50%" valign="top" style="{{ $font }}">
                      <div style="font-size:22px;font-weight:700;color:#12305A;letter-spacing:1px;">{{ $s['origin'] }}</div>
                      <div style="font-size:13px;color:#344054;">Departs {{ $s['departure'] ?? 'time to be confirmed' }}</div>
                    </td>
                    <td class="stack stack-gap" width="50%" valign="top" style="{{ $font }}">
                      <div style="font-size:22px;font-weight:700;color:#12305A;letter-spacing:1px;"><span style="color:#F97316;">&rarr;</span> {{ $s['destination'] }}</div>
                      <div style="font-size:13px;color:#344054;">@if($s['arrival'])Arrives {{ $s['arrival'] }}@else&nbsp;@endif</div>
                    </td>
                  </tr>
                </table>
                @if($s['cabin'] || $s['baggage'])
                  <div style="margin-top:8px;font-size:12px;color:#667085;">
                    @if($s['cabin'])Cabin: {{ $s['cabin'] }}@endif
                    @if($s['cabin'] && $s['baggage']) · @endif
                    @if($s['baggage'])Baggage: {{ $s['baggage'] }}@endif
                  </div>
                @endif
              </td>
            </tr>
          </table>
        @endforeach
        <p style="margin:4px 0 0 0;{{ $font }}font-size:12px;color:#667085;">Times are local airport times. @if($alternative)Please check the attached document and the airline's current itinerary.@else Your ticket PDF is the authoritative record.@endif</p>
      @elseif($requestedRoute)
        <p style="margin:22px 0 0 0;{{ $font }}font-size:14px;color:#344054;"><strong style="color:#12305A;">Requested trip:</strong> {{ $requestedRoute }} <span style="color:#667085;">(flight details are in the attached documents)</span></p>
      @endif

      {{-- E. Passengers --}}
      @if(count($passengers))
        <h2 style="margin:26px 0 10px 0;{{ $font }}font-size:17px;line-height:24px;color:#12305A;font-weight:700;">Passengers</h2>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          @foreach($passengers as $i => $name)
            <tr>
              <td style="padding:6px 0;border-bottom:1px solid #F2F4F7;{{ $font }}font-size:14px;color:#172033;word-break:break-word;">
                <span style="display:inline-block;min-width:22px;color:#2563EB;font-weight:700;">{{ $i + 1 }}.</span> {{ $name }}
              </td>
            </tr>
          @endforeach
        </table>
      @endif

      {{-- F. Attached ticket documents --}}
      <h2 style="margin:26px 0 6px 0;{{ $font }}font-size:17px;line-height:24px;color:#12305A;font-weight:700;">{{ $alternative ? 'Attached ticket document' : 'Your ticket documents' }}</h2>
      <p style="margin:0 0 12px 0;{{ $font }}font-size:14px;color:#344054;">{{ count($attachmentsList) }} PDF {{ count($attachmentsList) === 1 ? 'file is' : 'files are' }} attached to this email. Please open the attached PDF to view {{ $alternative ? 'the document' : 'your ticket' }}.</p>
      @foreach($attachmentsList as $a)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #E4E7EC;border-radius:10px;margin-bottom:8px;">
          <tr>
            <td width="52" valign="middle" style="padding:12px 0 12px 14px;">
              <div style="width:38px;height:38px;line-height:38px;text-align:center;background-color:#FFF1E8;border:1px solid #FDBA8C;border-radius:8px;{{ $font }}font-size:11px;font-weight:800;color:#C2410C;">PDF</div>
            </td>
            <td valign="middle" style="padding:12px 14px 12px 10px;{{ $font }}">
              <div style="font-size:14px;font-weight:600;color:#172033;word-break:break-all;">{{ $a['name'] }}</div>
              @if(count($a['passengers']) || count($a['tickets']))
                <div style="margin-top:2px;font-size:12px;color:#667085;word-break:break-word;">
                  @if(count($a['passengers'])){{ implode(', ', $a['passengers']) }}@endif
                  @if(count($a['passengers']) && count($a['tickets'])) · @endif
                  @if(count($a['tickets']))Ticket {{ implode(', ', $a['tickets']) }}@endif
                </div>
              @endif
            </td>
          </tr>
        </table>
      @endforeach

      {{-- G. Reminder --}}
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFF7ED" style="background-color:#FFF7ED;border:1px solid #FED7AA;border-left:4px solid #F97316;border-radius:10px;margin-top:20px;">
        <tr>
          <td style="padding:16px 18px;{{ $font }}">
            <div style="font-size:15px;font-weight:700;color:#9A3412;">Before your journey</div>
            <ul style="margin:8px 0 0 0;padding-left:18px;font-size:13px;line-height:21px;color:#344054;">
              <li>Please review the attached ticket details carefully.</li>
              @if($alternative)<li>Check your itinerary and travel requirements with the airline before departure.</li>@endif
              <li>Verify the passenger names and travel dates against your travel documents.</li>
              <li>Check the airline's current baggage allowance and airport check-in requirements.</li>
              <li>Please contact us if you notice any incorrect information.</li>
            </ul>
          </td>
        </tr>
      </table>

      {{-- H. Contact --}}
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:22px;border-top:1px solid #E4E7EC;">
        <tr>
          <td style="padding:18px 0 22px 0;{{ $font }}font-size:14px;line-height:22px;color:#344054;">
            If you have any questions about your booking, please contact our team.
            @if($agency['email'] || $agency['phone'] || $agency['website'])
              <div style="margin-top:8px;">
                <strong style="color:#12305A;">{{ $agency['name'] }}</strong><br>
                @if($agency['phone'])Phone: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $agency['phone']) }}" style="color:#2563EB;text-decoration:none;">{{ $agency['phone'] }}</a><br>@endif
                @if($agency['email'])Email: <a href="mailto:{{ $agency['email'] }}" style="color:#2563EB;text-decoration:none;">{{ $agency['email'] }}</a><br>@endif
                @if($agency['website'])Website: <a href="{{ $agency['website'] }}" style="color:#2563EB;text-decoration:none;word-break:break-all;">{{ preg_replace('#^https?://#', '', $agency['website']) }}</a>@endif
              </div>
            @endif
          </td>
        </tr>
      </table>
    </td>
  </tr>

  {{-- I. Footer --}}
  <tr>
    <td class="px" bgcolor="#12305A" style="background-color:#12305A;border-radius:0 0 16px 16px;padding:20px 32px;{{ $font }}font-size:12px;line-height:19px;color:#C7D2E3;">
      <strong style="color:#FFFFFF;">{{ $agency['name'] }}</strong> · Thank you for travelling with us.<br>
      This email was sent regarding your flight booking {{ $bookingCode }}.
      @if($agency['address'])<br>{{ $agency['address'] }}@endif
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
