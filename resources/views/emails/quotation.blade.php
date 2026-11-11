{{--
  Customer email for a flight quotation (PDF attached). Table layout + inline CSS for Gmail,
  Outlook and Apple Mail; every dynamic value is escaped. No scripts, no tracking.
--}}
@php $font = "font-family:'Segoe UI',Roboto,'Helvetica Neue',Helvetica,Arial,sans-serif;"; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>Your flight quotation {{ $reference }}</title>
<style>
  body { margin:0; padding:0; }
  table { border-collapse:collapse; }
  @media only screen and (max-width: 620px) { .container { width:100% !important; } .px { padding-left:18px !important; padding-right:18px !important; } }
</style>
</head>
<body style="margin:0;padding:0;background-color:#F3F6FB;">
<div style="display:none;max-height:0;overflow:hidden;font-size:1px;line-height:1px;color:#F3F6FB;">Flight quotation {{ $reference }} · {{ $route }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F3F6FB"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">

  <tr><td class="px" bgcolor="#12305A" style="background-color:#12305A;background-image:linear-gradient(135deg,#12305A 0%,#1E4C8F 60%,#2563EB 100%);border-radius:16px 16px 0 0;padding:28px 32px;{{ $font }}">
    @include('emails.partials.logo', ['height' => 56])
    <div style="font-size:15px;font-weight:600;color:#FFFFFF;">{{ $agency['name'] }}</div>
    <h1 style="margin:18px 0 0 0;{{ $font }}font-size:26px;line-height:32px;color:#FFFFFF;">Your Flight Quotation</h1>
    <p style="margin:6px 0 0 0;{{ $font }}font-size:15px;color:#BFE9F2;font-weight:600;">{{ $route }}</p>
  </td></tr>
  <tr><td height="4" bgcolor="#06B6D4" style="background-image:linear-gradient(90deg,#06B6D4,#2563EB,#F97316);font-size:0;line-height:0;">&nbsp;</td></tr>

  <tr><td class="px" bgcolor="#FFFFFF" style="padding:28px 32px 8px 32px;{{ $font }}">
    <p style="margin:0 0 12px 0;font-size:16px;font-weight:600;color:#172033;">Dear {{ $customerName ?: 'Customer' }},</p>
    <p style="margin:0 0 18px 0;font-size:15px;line-height:23px;color:#344054;">Thank you for your enquiry. Please find attached our flight quotation with the available options, prices, baggage allowances and fare conditions.</p>
    @if($customerMessage)<p style="margin:0 0 18px 0;font-size:15px;line-height:23px;color:#344054;">{!! nl2br(e($customerMessage)) !!}</p>@endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#EEF4FF" style="background-color:#EEF4FF;border:1px solid #D6E4FF;border-radius:12px;">
      <tr><td style="padding:16px 20px;{{ $font }}font-size:14px;line-height:22px;color:#344054;">
        <div style="font-size:11px;font-weight:700;letter-spacing:1.2px;color:#2563EB;">QUOTATION REFERENCE</div>
        <div style="font-family:'Courier New',Courier,monospace;font-size:22px;font-weight:700;color:#12305A;margin:2px 0 10px;">{{ $reference }}</div>
        <strong style="color:#172033;">Route:</strong> {{ $route }}<br>
        <strong style="color:#172033;">Departure:</strong> {{ $outbound }}<br>
        @if($return)<strong style="color:#172033;">Return:</strong> {{ $return }}<br>@endif
        <strong style="color:#172033;">Passengers:</strong> {{ $passengers }} · {{ $cabin }}
        @if($validUntil)<br><strong style="color:#172033;">Valid until:</strong> {{ $validUntil }}@endif
      </td></tr>
    </table>

    @if($filename)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #E4E7EC;border-radius:10px;margin-top:18px;">
      <tr>
        <td width="52" style="padding:12px 0 12px 14px;"><div style="width:38px;height:38px;line-height:38px;text-align:center;background-color:#FFF1E8;border:1px solid #FDBA8C;border-radius:8px;{{ $font }}font-size:11px;font-weight:800;color:#C2410C;">PDF</div></td>
        <td style="padding:12px 14px 12px 10px;{{ $font }}"><div style="font-size:14px;font-weight:600;color:#172033;word-break:break-all;">{{ $filename }}</div><div style="font-size:12px;color:#667085;">Your quotation is attached to this email.</div></td>
      </tr>
    </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFF7ED" style="background-color:#FFF7ED;border:1px solid #FED7AA;border-left:4px solid #F97316;border-radius:10px;margin-top:20px;">
      <tr><td style="padding:14px 16px;{{ $font }}font-size:13px;line-height:20px;color:#344054;">
        <strong style="color:#9A3412;">Please note:</strong> this is a quotation only, not a flight reservation or an issued ticket. Prices and seat availability may change until the booking is confirmed and the ticket is issued. Reply to this email or contact us to book.
      </td></tr>
    </table>

    <p style="margin:22px 0 22px 0;{{ $font }}font-size:14px;line-height:22px;color:#344054;">
      Kind regards,<br><strong style="color:#12305A;">{{ $agency['name'] }}</strong>
      @if($agency['phone'])<br>Phone: {{ $agency['phone'] }}@endif
      @if($agency['email'])<br>Email: <a href="mailto:{{ $agency['email'] }}" style="color:#2563EB;text-decoration:none;">{{ $agency['email'] }}</a>@endif
      @if($agency['website'])<br>Website: <a href="{{ $agency['website'] }}" style="color:#2563EB;text-decoration:none;">{{ preg_replace('#^https?://#', '', $agency['website']) }}</a>@endif
    </p>
  </td></tr>

  <tr><td class="px" bgcolor="#12305A" style="background-color:#12305A;border-radius:0 0 16px 16px;padding:18px 32px;{{ $font }}font-size:12px;line-height:19px;color:#C7D2E3;">
    <strong style="color:#FFFFFF;">{{ $agency['name'] }}</strong> · Flight quotation {{ $reference }}
    @if($agency['address'])<br>{{ preg_replace('/\s*\n\s*/', ', ', $agency['address']) }}@endif
  </td></tr>
</table>
</td></tr></table>
</body>
</html>
