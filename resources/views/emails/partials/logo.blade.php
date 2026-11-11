{{--
  Agency logo for emails. Uses the public logo URL when set; otherwise the uploaded logo is
  embedded in the message (CID), which Gmail, Outlook and Apple Mail show without a download.
  In the in-app preview (no mail message) it is shown inline.
--}}
@php
    $logoSrc = $agency['logo'] ?? null;
    if (! $logoSrc && ! empty($agency['logo_png'])) {
        $logoSrc = isset($message) && $message instanceof \Illuminate\Mail\Message
            ? $message->embedData($agency['logo_png'], 'logo.png', 'image/png')
            : 'data:image/png;base64,'.base64_encode($agency['logo_png']);
    }
@endphp
@if($logoSrc)
  <img src="{{ $logoSrc }}" alt="{{ $agency['name'] }}" height="{{ $height ?? 56 }}" style="display:block;height:{{ $height ?? 56 }}px;width:auto;max-width:200px;margin-bottom:12px;border:0;">
@endif
