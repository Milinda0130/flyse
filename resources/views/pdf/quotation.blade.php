{{--
  Flight quotation PDF (dompdf: tables + simple CSS). Customer-facing values only; every value is
  escaped and comes from the saved quotation. Unknown values are shown as "Not confirmed", never as
  "Not included".
--}}
@php
  $svg = fn (string $body, string $color = '#1b3a6b', int $size = 24) => 'data:image/svg+xml;base64,'.base64_encode(
      '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="'.$color.'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.$body.'</svg>');
  $icons = [
      'calendar' => $svg('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'),
      'users' => $svg('<circle cx="9" cy="7" r="4"/><path d="M1 21v-2a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v2"/><path d="M17 3.1a4 4 0 0 1 0 7.8"/><path d="M23 21v-2a4 4 0 0 0-3-3.9"/>'),
      'doc' => $svg('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="14" y2="17"/>'),
      'cabin' => $svg('<path d="M4 18v-6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6"/><path d="M6 10V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v4"/><line x1="2" y1="18" x2="22" y2="18"/>'),
      'plane' => $svg('<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>', '#1b3a6b', 18),
      'bag' => $svg('<rect x="6" y="7" width="12" height="14" rx="2"/><path d="M9 7V4h6v3"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>'),
      'carry' => $svg('<rect x="7" y="6" width="10" height="15" rx="2"/><path d="M10 6V3h4v3"/><line x1="9" y1="21" x2="9" y2="22"/><line x1="15" y1="21" x2="15" y2="22"/>'),
      'personal' => $svg('<path d="M5 10h14l-1 11H6z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/>'),
      'included' => $svg('<circle cx="12" cy="12" r="10" fill="#16a34a" stroke="#16a34a"/><polyline points="7 12.5 10.5 16 17 9" stroke="#ffffff" stroke-width="2.6"/>', '#16a34a', 14),
      'not_included' => $svg('<circle cx="12" cy="12" r="10" fill="#dc2626" stroke="#dc2626"/><line x1="8" y1="8" x2="16" y2="16" stroke="#ffffff" stroke-width="2.6"/><line x1="16" y1="8" x2="8" y2="16" stroke="#ffffff" stroke-width="2.6"/>', '#dc2626', 14),
      'unknown' => $svg('<circle cx="12" cy="12" r="10" fill="#94a3b8" stroke="#94a3b8"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6" stroke="#ffffff" stroke-width="2.4"/><circle cx="12" cy="17.3" r="0.6" fill="#ffffff" stroke="#ffffff"/>', '#94a3b8', 14),
      'info' => $svg('<circle cx="12" cy="12" r="10" fill="#b7791f" stroke="#b7791f"/><line x1="12" y1="11" x2="12" y2="17" stroke="#ffffff" stroke-width="2.6"/><circle cx="12" cy="7.5" r="0.8" fill="#ffffff" stroke="#ffffff"/>', '#b7791f', 22),
      'refund_ok' => $svg('<circle cx="12" cy="12" r="10" fill="#16a34a" stroke="#16a34a"/><polyline points="7 12.5 10.5 16 17 9" stroke="#ffffff" stroke-width="2.6"/>', '#16a34a', 22),
      'refund_no' => $svg('<circle cx="12" cy="12" r="10" fill="#dc2626" stroke="#dc2626"/><line x1="8" y1="8" x2="16" y2="16" stroke="#ffffff" stroke-width="2.6"/><line x1="16" y1="8" x2="8" y2="16" stroke="#ffffff" stroke-width="2.6"/>', '#dc2626', 22),
      'refund_partial' => $svg('<circle cx="12" cy="12" r="10" fill="#d97706" stroke="#d97706"/><line x1="7" y1="12" x2="17" y2="12" stroke="#ffffff" stroke-width="2.6"/>', '#d97706', 22),
      'refund_unknown' => $svg('<circle cx="12" cy="12" r="10" fill="#94a3b8" stroke="#94a3b8"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6" stroke="#ffffff" stroke-width="2.4"/><circle cx="12" cy="17.3" r="0.6" fill="#ffffff" stroke="#ffffff"/>', '#94a3b8', 22),
      'change' => $svg('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 15 11 17 15 13"/>', '#b91c1c', 22),
  ];
  $bagIcon = ['Checked baggage' => 'bag', 'Cabin baggage' => 'carry', 'Personal item' => 'personal'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Flight Quotation {{ $reference }}</title>
<style>
  @page { margin: 30px 34px 64px; }
  * { font-family: 'DejaVu Sans', sans-serif; }
  body { margin: 0; color: #1f2a44; font-size: 9.5px; }
  table { border-collapse: collapse; width: 100%; }
  td { vertical-align: top; }
  .navy { color: #12305a; }
  .muted { color: #5b6b85; }
  .small { font-size: 8px; }
  .card { border: 1px solid #dbe3ef; border-radius: 6px; margin-bottom: 12px; page-break-inside: avoid; }
  .card-head { background: #eef3fa; border-bottom: 1px solid #dbe3ef; }
  .panel-blue { background: #eef3fa; border: 1px solid #dbe3ef; border-radius: 6px; }
  .panel-red { background: #fdf0f1; border: 1px solid #f3d3d7; border-radius: 6px; }
  .panel-amber { background: #fff8e8; border: 1px solid #f3e1b5; border-radius: 6px; }
  .bags { background: #f3f6fa; border-radius: 5px; }
  .h2 { font-size: 13px; font-weight: bold; color: #12305a; letter-spacing: .3px; }
  .footer { position: fixed; bottom: -46px; left: 0; right: 0; height: 34px; border-top: 1px solid #c9d5e6; font-size: 8.5px; color: #1f2a44; }
</style>
</head>
<body>
@if(! empty($watermark))
  <div style="position:fixed; top:-30px; left:-34px; width:794px; height:1123px; z-index:-1000;"><img src="{{ $watermark }}" alt="" style="width:794px; height:1123px;"></div>
@endif

{{-- Footer on every page --}}
<div class="footer">
  <table><tr>
    <td style="padding-top:10px; width:30%;">@if($agency['phone'])<strong>Tel:</strong> {{ $agency['phone'] }}@endif</td>
    <td style="padding-top:10px; width:30%; text-align:center;">@if($agency['email'])<strong>Email:</strong> {{ $agency['email'] }}@endif</td>
    <td style="padding-top:10px; width:25%; text-align:center;">@if($agency['website']){{ preg_replace('#^https?://#', '', $agency['website']) }}@endif</td>
    <td style="padding-top:10px; width:15%;"></td>
  </tr></table>
</div>

{{-- Header --}}
<table>
  <tr>
    <td style="width:55%; padding-bottom:6px;">
      @if($agency['logo'])
        <img src="{{ $agency['logo'] }}" alt="{{ $agency['name'] }}" style="max-height:70px; max-width:230px;">
      @else
        <div style="font-size:24px; font-weight:bold; color:#12305a; letter-spacing:1px;">{{ mb_strtoupper($agency['name']) }}</div>
      @endif
    </td>
    <td style="padding-left:14px; border-left:2px solid #12305a;">
      <div style="font-size:13px; font-weight:bold; color:#12305a;">{{ mb_strtoupper($agency['name']) }}</div>
      @if($agency['address'])<div style="font-size:10px; margin-top:3px; line-height:1.45;">{!! nl2br(e($agency['address'])) !!}</div>@endif
    </td>
  </tr>
</table>

<div style="margin-top:14px; font-size:26px; font-weight:bold; color:#12305a; letter-spacing:.5px;">FLIGHT QUOTATION</div>
<div style="font-size:15px; color:#3b4a63; margin-top:2px;">{{ $route }}</div>
<div class="muted" style="margin-top:4px;">Reference <strong class="navy">{{ $reference }}</strong>@if($customerName) · Prepared for <strong class="navy">{{ $customerName }}</strong>@endif</div>

{{-- Summary --}}
<table style="margin-top:12px;">
  <tr>
    <td style="width:{{ $headline ? '68%' : '100%' }};">
      <table>
        <tr>
          <td style="width:{{ $isReturn ? '25%' : '33%' }}; padding:6px 8px 6px 0;">
            <table><tr>
              <td style="width:28px;"><img src="{{ $icons['calendar'] }}" style="width:22px; height:22px;"></td>
              <td><div class="navy" style="font-weight:bold;">{{ $isReturn ? 'Departure' : 'Travel date' }}</div><div style="font-size:10.5px;">{{ $outboundDate }}</div><div class="muted small">({{ $outboundDay }})</div></td>
            </tr></table>
          </td>
          @if($isReturn && $returnDate)
          <td style="width:25%; padding:6px 8px; border-left:1px solid #dbe3ef;">
            <table><tr>
              <td style="width:28px;"><img src="{{ $icons['calendar'] }}" style="width:22px; height:22px;"></td>
              <td><div class="navy" style="font-weight:bold;">Return</div><div style="font-size:10.5px;">{{ $returnDate }}</div><div class="muted small">({{ $returnDay }})</div></td>
            </tr></table>
          </td>
          @endif
          <td style="padding:6px 8px; border-left:1px solid #dbe3ef;">
            <table><tr>
              <td style="width:30px;"><img src="{{ $icons['users'] }}" style="width:22px; height:22px;"></td>
              <td><div class="navy" style="font-weight:bold;">Passengers</div><div style="font-size:10.5px;">{{ $passengers }}</div><div class="muted small">{{ $cabin }}</div></td>
            </tr></table>
          </td>
          <td style="padding:6px 0 6px 8px; border-left:1px solid #dbe3ef;">
            <table><tr>
              <td style="width:28px;"><img src="{{ $icons['doc'] }}" style="width:22px; height:22px;"></td>
              <td><div class="navy" style="font-weight:bold; white-space:nowrap;">Quotation date</div><div style="font-size:10.5px;">{{ $quotationDate }}</div>@if($validUntil)<div class="muted small">Valid until {{ $validUntil }}</div>@endif</td>
            </tr></table>
          </td>
        </tr>
      </table>
    </td>
    @if($headline)
    <td style="padding-left:10px;">
      <div class="panel-blue" style="padding:10px 8px; text-align:center;">
        <div style="font-size:9.5px;">{{ $headline['prefix'] ? 'Price from (incl. tax)' : 'Total price (incl. tax)' }}</div>
        <div style="font-size:21px; font-weight:bold; color:#12305a; margin:3px 0;">{{ $headline['amount'] }}</div>
      </div>
    </td>
    @endif
  </tr>
</table>

@if($message)
  <div style="margin-top:10px; font-size:10px; line-height:1.5;">{!! nl2br(e($message)) !!}</div>
@endif

<div style="border-top:1.5px solid #9fb3d1; margin:12px 0 10px;"></div>
<div class="h2" style="margin-bottom:8px;">FLIGHT DETAILS</div>

@foreach($options as $o)
  @foreach($o['legs'] as $leg)
    <div style="page-break-inside:avoid;">
      @if($multiple && $loop->first)
    <table style="margin:{{ $loop->parent->first ? '0' : '10px' }} 0 8px; background:#12305a; color:#ffffff;">
      <tr>
        <td style="padding:7px 10px; font-size:11.5px; font-weight:bold;">{{ $o['label'] }}@if($o['fareFamily']) <span style="font-weight:normal; color:#c7d4ea;">· {{ $o['fareFamily'] }}</span>@endif</td>
        <td style="padding:7px 10px; text-align:right; font-size:10.5px;">@if($o['price']['party_total']){{ $o['price']['party_total'] }}@endif</td>
      </tr>
    </table>
  @endif
    <div class="card">
      <table class="card-head">
        <tr>
          <td style="padding:7px 10px; width:62%;">
            <img src="{{ $icons['plane'] }}" style="width:14px; height:14px; vertical-align:middle;">
            <span style="font-size:11.5px; font-weight:bold; color:#12305a; vertical-align:middle;">&nbsp;{{ $leg['title'] }}</span>
            <span class="muted" style="vertical-align:middle;">&nbsp;|&nbsp; {{ $leg['date'] }}</span>
          </td>
          <td style="padding:7px 10px; text-align:right; color:#12305a;">
            {{ $leg['stops'] }}@if($leg['travelTime']) &nbsp;·&nbsp; Travel time: <strong>{{ $leg['travelTime'] }}</strong>@endif
          </td>
        </tr>
      </table>

      @foreach($leg['segments'] as $s)
        @if(! $loop->first)
          <div style="margin:0 10px; padding:5px 8px; background:#fff8e8; border:1px dashed #e8cf93; border-radius:4px; font-size:8.5px; color:#8a5a00;">
            Connection in {{ $s['layoverAt'] }}@if($s['layover']) · layover {{ $s['layover'] }}@endif
          </div>
        @endif
        <table>
          <tr>
            <td style="padding:10px 0 8px 12px; width:24%;">
              <div style="font-size:15px; font-weight:bold; color:#12305a;">{{ $s['depTime'] }}</div>
              <div style="font-weight:bold; font-size:10px;">{{ $s['depPlace']['city'] }} ({{ $s['depPlace']['code'] }})</div>
              @if($s['depPlace']['name'])<div class="muted small">{{ $s['depPlace']['name'] }}@if($s['depTerminal']) · T{{ $s['depTerminal'] }}@endif</div>@elseif($s['depTerminal'])<div class="muted small">Terminal {{ $s['depTerminal'] }}</div>@endif
              <div class="muted" style="font-size:9px;">{{ $s['depDate'] }}</div>
            </td>
            <td style="padding:16px 4px 0; width:16%; text-align:center;">
              <img src="{{ $icons['plane'] }}" style="width:14px; height:14px;">
              <div style="border-top:1px solid #12305a; margin:2px 4px 3px;"></div>
              <div class="small">{{ $s['duration'] ?? '' }}</div>
            </td>
            <td style="padding:10px 6px 8px 6px; width:24%;">
              <div style="font-size:15px; font-weight:bold; color:#12305a;">{{ $s['arrTime'] ?? '—' }}@if($s['nextDay'])<span style="font-size:8px; color:#b45309;"> +1</span>@endif</div>
              <div style="font-weight:bold; font-size:10px;">{{ $s['arrPlace']['city'] }} ({{ $s['arrPlace']['code'] }})</div>
              @if($s['arrPlace']['name'])<div class="muted small">{{ $s['arrPlace']['name'] }}@if($s['arrTerminal']) · T{{ $s['arrTerminal'] }}@endif</div>@elseif($s['arrTerminal'])<div class="muted small">Terminal {{ $s['arrTerminal'] }}</div>@endif
              @if($s['arrDate'])<div class="muted" style="font-size:9px;">{{ $s['arrDate'] }}</div>@endif
            </td>
            <td style="padding:10px 12px 8px; border-left:1px solid #dbe3ef;">
              @if($s['logo'])
                <img src="{{ $s['logo'] }}" alt="{{ $s['airline'] }}" style="max-height:24px; max-width:130px;">
              @endif
              <div style="font-size:11px; font-weight:bold; color:#12305a;@if($s['logo']) margin-top:3px;@endif">{{ $s['airline'] }}</div>
              <div style="margin-top:4px; font-size:9.5px;">{{ $s['flight'] }}@if($s['cabin']) · {{ $s['cabin'] }}@endif @if($s['aircraft']) · {{ $s['aircraft'] }}@endif</div>
              @if($s['operatedBy'])<div class="muted small">Operated by {{ $s['operatedBy'] }}</div>@endif
              @if($s['baggage'])<div class="muted small">Baggage: {{ $s['baggage'] }}</div>@endif
              @if($s['notes'])<div class="muted small">{{ $s['notes'] }}</div>@endif
            </td>
          </tr>
        </table>
      @endforeach

      <table class="bags" style="margin:0 8px 8px; width:97%;">
        <tr>
          @foreach($o['baggage'] as $b)
            <td style="padding:6px 10px; width:33%;">
              <table><tr>
                <td style="width:36px; white-space:nowrap;"><img src="{{ $icons[$bagIcon[$b['label']]] }}" style="width:20px; height:20px;"><img src="{{ $icons[$b['status']] }}" style="width:11px; height:11px; margin-left:-5px;"></td>
                <td><div style="font-weight:bold; font-size:8.5px;">{{ $b['label'] }}</div><div style="font-size:8.5px; color:{{ $b['status'] === 'included' ? '#166534' : ($b['status'] === 'not_included' ? '#991b1b' : '#5b6b85') }};">{{ $b['text'] }}</div></td>
              </tr></table>
            </td>
          @endforeach
        </tr>
      </table>
    </div>
    </div>
  @endforeach
  @if($o['baggageNotes'])<div class="muted small" style="margin:-6px 0 8px;">Baggage: {{ $o['baggageNotes'] }}</div>@endif
  @if($o['notes'])<div style="margin:-4px 0 8px; font-size:9px;">{!! nl2br(e($o['notes'])) !!}</div>@endif

  {{-- Price summary + ticket conditions --}}
  <table style="page-break-inside:avoid; margin-bottom:8px;">
    <tr>
      <td style="width:50%; padding-right:6px;">
        <div class="panel-blue">
          <div class="h2" style="padding:9px 12px 6px;">PRICE SUMMARY</div>
          <table>
            <tr>
              <td style="padding:9px 12px; font-size:11px; font-weight:bold; color:#12305a;">Total price <span style="font-weight:normal; font-size:9px;">(incl. tax &amp; charges)</span></td>
              <td style="padding:9px 12px; text-align:right;">
                <div style="font-size:15px; font-weight:bold; color:#12305a;">{{ $o['price']['party_total'] ?? 'Not confirmed' }}</div>
              </td>
            </tr>
          </table>
        </div>
        @if($o['priceValidUntil'])<div class="muted small" style="margin-top:3px;">Price valid until {{ $o['priceValidUntil'] }}</div>@endif
      </td>
      <td style="padding-left:6px;">
        <div class="panel-red" style="padding:9px 12px 10px;">
          <div class="h2" style="color:#9b1c1c; margin-bottom:6px;">TICKET CONDITIONS</div>
          @foreach($o['extras'] as $e)
            <table style="margin-bottom:6px;"><tr>
              <td style="width:28px;"><img src="{{ $icons['change'] }}" style="width:20px; height:20px;"></td>
              <td>
                <div style="font-weight:bold; color:#9b1c1c; font-size:10px;">{{ $e['title'] }}</div>
                @if($e['amount'])<div style="font-weight:bold; font-size:9px;">+{{ $e['amount'] }} per person</div>@endif
                @if($e['details'])<ul style="margin:3px 0 0 12px; padding:0; font-size:8.5px;">@foreach($e['details'] as $d)<li>{{ $d }}</li>@endforeach</ul>@endif
              </td>
            </tr></table>
          @endforeach
          @php $c = $o['conditions']; @endphp
          <table style="margin-bottom:5px;"><tr>
            <td style="width:28px;"><img src="{{ $icons['refund_'.match($c['refund']['state']) { 'refundable' => 'ok', 'non_refundable' => 'no', 'partially_refundable' => 'partial', default => 'unknown' }] }}" style="width:18px; height:18px;"></td>
            <td>
              <div style="font-weight:bold; color:#9b1c1c; font-size:10px;">{{ $c['refund']['label'] }}</div>
              <div style="font-size:8.5px;">@switch($c['refund']['state'])
                @case('refundable') This ticket is refundable according to the fare rules. @break
                @case('non_refundable') This ticket is non-refundable. @break
                @case('partially_refundable') This ticket is partially refundable according to the fare rules. @break
                @default Please confirm the refund conditions with the agency.
              @endswitch</div>
              @if($c['cancellation'])<div style="font-size:8.5px;">{{ $c['cancellation'] }}</div>@endif
            </td>
          </tr></table>
          <table style="margin-bottom:5px;"><tr>
            <td style="width:28px;"><img src="{{ $icons['refund_'.match($c['change']['state']) { 'yes' => 'ok', 'no' => 'no', default => 'unknown' }] }}" style="width:18px; height:18px;"></td>
            <td>
              <div style="font-weight:bold; color:#9b1c1c; font-size:10px;">{{ $c['change']['state'] === 'yes' ? 'Date changes permitted' : ($c['change']['state'] === 'no' ? 'Changes not permitted' : 'Date changes') }}</div>
              <div style="font-size:8.5px;">
                @if($c['change']['state'] === 'yes')
                  @if($c['change']['fee'])Change fee {{ $c['change']['fee'] }} per person.@elseif($c['change']['feeIsFree'])No change fee.@endif
                  @if($c['change']['fareDifference'] === 'yes') Any fare difference applies.@elseif($c['change']['fareDifference'] === 'no') No fare difference is charged.@endif
                @elseif($c['change']['state'] === 'no')
                  Dates and times cannot be changed after the ticket is issued.
                @else
                  Please confirm the change conditions with the agency.
                @endif
              </div>
            </td>
          </tr></table>
          @if($c['noShow'])<div style="font-size:8.5px; margin-top:2px;"><strong>No-show:</strong> {{ $c['noShow'] }}</div>@endif
          @if($c['other'])<div style="font-size:8.5px; margin-top:2px;">{!! nl2br(e($c['other'])) !!}</div>@endif
        </div>
      </td>
    </tr>
  </table>
@endforeach

{{-- Important information --}}
<div class="panel-amber" style="padding:9px 12px 10px; margin-top:4px; page-break-inside:avoid;">
  <table><tr>
    <td style="width:30px;"><img src="{{ $icons['info'] }}" style="width:20px; height:20px;"></td>
    <td><div style="font-size:12px; font-weight:bold; color:#a16207; padding-top:2px;">IMPORTANT INFORMATION</div></td>
  </tr></table>
  <ul style="margin:5px 0 0 14px; padding:0; font-size:8.8px; line-height:1.55;">
    <li>This quotation is based on the flight and fare information entered by the agency. All times are local airport times.</li>
    <li>Prices are shown in the currency stated and include taxes and charges as listed in the price summary.</li>
    <li>Airfares and seat availability are subject to change and are not guaranteed until the booking is confirmed and the ticket is issued.</li>
    <li>This is a quotation only and does not constitute a flight reservation or ticket.</li>
    @if($validUntil)<li>This quotation is valid until {{ $validUntil }}.</li>@endif
    <li>Please check the latest price and availability with us at the time of booking.</li>
  </ul>
</div>

</body>
</html>
