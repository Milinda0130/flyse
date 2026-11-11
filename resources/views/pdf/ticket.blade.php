{{--
  Agency ticket PDF (dompdf: tables + simple CSS only). One passenger per document.
  Every value comes from the booking records and is escaped; missing values are left out.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>E-ticket · {{ $passengerName }}</title>
<style>
  @page { margin: 34px 38px; }
  * { font-family: 'DejaVu Sans', sans-serif; }
  body { margin: 0; color: #1f2937; font-size: 10px; }
  table { border-collapse: collapse; width: 100%; }
  td { vertical-align: top; }
  .sheet { border: 1px solid #cfd6e0; border-bottom: 4px solid #0f1a29; }
  .mono { font-family: 'DejaVu Sans Mono', monospace; }
  .label { font-size: 7px; letter-spacing: 1.2px; color: #8a96a8; text-transform: uppercase; }
  .muted { color: #7b8798; }
  .head { background: #f5f7fa; }
  .band { background: #0f1a29; color: #fff; }
  .bar { background: #1b365d; color: #fff; }
  .gold { color: #d4a83a; }
  .seg { page-break-inside: avoid; }
  .segfoot { background: #f4f8fc; border-top: 1px solid #e3e8ef; }
  .important { background: #fef9e7; border-top: 1px solid #ece3c2; }
</style>
</head>
<body>
<div class="sheet">

  {{-- Agency header --}}
  <table class="head">
    <tr>
      <td style="padding:20px 22px; width:45%;">
        @if($agency['logo'])
          <img src="{{ $agency['logo'] }}" alt="{{ $agency['name'] }}" style="max-height:78px; max-width:170px;">
        @else
          <div class="gold" style="font-size:22px; font-weight:bold; letter-spacing:2px;">{{ mb_strtoupper($agency['name']) }}</div>
        @endif
      </td>
      <td style="padding:20px 22px; text-align:right; line-height:1.75; color:#4b5563; font-size:9px;">
        {{ mb_strtoupper($agency['name']) }}<br>
        @if($agency['address']){!! nl2br(e($agency['address'])) !!}<br>@endif
        @if($agency['phone'])Tel: {{ $agency['phone'] }}<br>@endif
        @if($agency['email'])Email: {{ $agency['email'] }}@endif
      </td>
    </tr>
  </table>

  {{-- Title band --}}
  <table class="band">
    <tr>
      <td style="padding:22px 22px 20px;">
        <div style="font-size:18px; font-weight:bold; letter-spacing:4px;">E-TICKET</div>
        <div style="width:28px; height:2px; background:#d4a83a; margin:7px 0 12px;"></div>
        <div style="font-size:12px; font-weight:bold; color:#e5e9f0;">{{ mb_strtoupper($passengerName) }}</div>
      </td>
      <td style="padding:18px 22px; text-align:right; width:40%;">
        <div class="label" style="color:#9aa6b8;">Reservation code</div>
        <div class="mono" style="font-size:13px; font-weight:bold; letter-spacing:1.5px; margin:3px 0 10px;">{{ $reservationCode }}</div>
        <div class="label" style="color:#9aa6b8;">Airline PNR</div>
        <div class="mono" style="font-size:13px; font-weight:bold; letter-spacing:1.5px; margin-top:3px;">{{ $airlinePnr }}</div>
      </td>
    </tr>
  </table>

  {{-- Reservation code / airline PNR / date of issue (no ticket number, no internal booking reference) --}}
  <table>
    <tr>
      <td style="padding:13px 22px; width:33%; border-right:1px solid #e3e8ef;">
        <div class="label" style="font-weight:bold;">Reservation code</div>
        <div class="mono" style="font-size:12px; font-weight:bold; letter-spacing:1px; margin-top:5px;">{{ $reservationCode }}</div>
      </td>
      <td style="padding:13px 22px; width:33%; border-right:1px solid #e3e8ef;">
        <div class="label" style="font-weight:bold;">Airline PNR</div>
        <div class="mono" style="font-size:12px; font-weight:bold; letter-spacing:1px; margin-top:5px;">{{ $airlinePnr }}</div>
      </td>
      <td style="padding:13px 22px;">
        <div class="label" style="font-weight:bold;">Date of issue</div>
        <div style="font-size:12px; font-weight:bold; margin-top:5px;">{{ $issueDate }}</div>
      </td>
    </tr>
  </table>

  {{-- Flight segments --}}
  @foreach($segments as $s)
    <div class="seg">
      <table class="bar">
        <tr>
          <td style="padding:10px 22px; font-size:10.5px; font-weight:bold;">{{ $s['date'] }}</td>
          <td style="padding:10px 22px; text-align:right; font-size:10px; font-weight:bold; letter-spacing:1px;" class="gold">{{ $s['cabin'] ? mb_strtoupper($s['cabin']) : '' }}</td>
        </tr>
      </table>
      <table>
        <tr>
          <td style="padding:16px 0 16px 22px; width:20%;">
            @if($s['logo'])<img src="{{ $s['logo'] }}" alt="{{ $s['airline'] }}" style="max-height:26px; max-width:100px; margin-bottom:6px;">@endif
            <div style="font-size:14px; font-weight:bold; color:#1b365d;">{{ $s['flight'] }}</div>
            @if($s['airline'])<div style="font-size:8.5px; font-weight:bold; color:#111827; margin-top:4px;">{{ $s['airline'] }}</div>@endif
          </td>
          <td style="padding:16px 8px; width:28%;">
            <div style="font-size:17px; font-weight:bold; color:#0f1a29;">{{ $s['from']['code'] }}</div>
            @if($s['from']['name'])<div style="font-size:8.5px; color:#4b5563; margin-top:4px;">{{ $s['from']['name'] }}</div>@endif
            @if($s['from']['city'])<div class="muted" style="font-size:7.5px; margin-top:3px;">{{ $s['from']['city'] }}</div>@endif
            <div style="font-size:10.5px; font-weight:bold; color:#1b365d; margin-top:8px;">{{ $s['departure'] }}</div>
          </td>
          <td style="padding:30px 8px 0; width:16%; text-align:center;">
            @if($s['duration'])
              <div class="label">Duration</div>
              <div style="border-top:1px solid #c5cfdc; margin:4px 10px 6px;"></div>
              <div style="font-size:10px; font-weight:bold; color:#1b365d;">{{ $s['duration'] }}</div>
            @else
              <div style="border-top:1px solid #c5cfdc; margin:10px 10px 0;"></div>
            @endif
          </td>
          <td style="padding:16px 22px 16px 8px; text-align:right;">
            <div style="font-size:17px; font-weight:bold; color:#0f1a29;">{{ $s['to']['code'] }}</div>
            @if($s['to']['name'])<div style="font-size:8.5px; color:#4b5563; margin-top:4px;">{{ $s['to']['name'] }}</div>@endif
            @if($s['to']['city'])<div class="muted" style="font-size:7.5px; margin-top:3px;">{{ $s['to']['city'] }}</div>@endif
            @if($s['arrival'])<div style="font-size:10.5px; font-weight:bold; color:#1b365d; margin-top:8px;">{{ $s['arrival'] }}</div>@endif
          </td>
        </tr>
      </table>
      <table class="segfoot">
        <tr>
          <td style="padding:11px 22px; line-height:1.9;">
            <div class="muted" style="font-size:8.5px;">
              Status: <strong style="color:#111827;">{{ $s['status'] }}</strong>
              @if($s['baggage'])<span style="color:#cfd6e0;">&nbsp;|&nbsp;</span> Checked Baggage: <strong style="color:#111827;">{{ $s['baggage'] }}</strong>@endif
            </div>
          </td>
        </tr>
      </table>
    </div>
  @endforeach

  {{-- Important --}}
  <table class="important">
    <tr>
      <td style="padding:13px 22px;">
        <div style="font-size:8.5px; font-weight:bold; letter-spacing:1.5px; color:#9a3412;">IMPORTANT</div>
        <div style="font-size:8px; color:#57534e; margin-top:5px; line-height:1.6;">
          All times shown are local airport times. Please reconfirm your flight schedule with the airline at least 72 hours before departure.
        </div>
      </td>
    </tr>
  </table>

  <table>
    <tr>
      <td style="padding:12px 22px 14px; font-size:7px; color:#9aa6b8; line-height:1.7; border-top:1px solid #e3e8ef;">
        This document was generated on {{ $generatedAt }} (UTC). Information is subject to change. Please verify with your travel agent or airline before departure.
      </td>
    </tr>
  </table>
</div>
</body>
</html>
