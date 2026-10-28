@php
  $accent     = $tenant->accent_color ?? '#BEF264';
  $accentText = \App\Support\ColorHelper::accentTextColor($accent);
  $shopName   = $tenant->name;
  $logo       = $tenant->emailLogoUrl(); // (411 logo url; was a Blade comment inside @php)
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{{ $subject ?? $shopName }}</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style>
  body{margin:0;padding:0;background:#f4f4f2;font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif}
  /* MARKER-EMAIL-DARK: Apple Mail and Outlook honour this; Gmail ignores it and recolours on its own,
     which is why the header and buttons also carry a gradient background-image (Gmail leaves those alone). */
  @media (prefers-color-scheme: dark){
    body,.email-wrapper{background:#0d0d0d!important}
    .email-body{background:#1c1c1c!important;color:#f0f0f0!important;border-color:rgba(255,255,255,.13)!important}
    .email-foot{background:#161616!important;border-color:rgba(255,255,255,.13)!important}
    .email-body p{color:#d8d8d8!important}
    .email-body .email-pw{color:#f0f0f0!important;border-color:rgba(255,255,255,.2)!important}
    .email-body .email-dim{color:#8a8a85!important}
    .email-body a:not([style*="background"]){color:#BEF264}
  }
  @media(max-width:620px){.email-wrapper{padding:0!important}.email-body{padding:24px!important}}
</style>
</head>
<body>
<div class="email-wrapper" style="background:#f4f4f2;padding:32px 0">
  <table width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr><td align="center">
  <table class="email-card" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">

    {{-- Header --}}
    <tr>
      <td style="background:#111111;background-color:#111111;background-image:linear-gradient(#111111,#111111);padding:24px 32px;text-align:center;border-radius:8px 8px 0 0">
        @if($logo)
          <img src="{{ $logo }}" alt="{{ $shopName }}" height="36"
            style="display:block;margin:0 auto;border:0;max-height:36px;width:auto">
        @else
          <div style="font-size:20px;font-weight:700;color:#f0f0f0;letter-spacing:-.01em">
            {{ $shopName }}
          </div>
        @endif
      </td>
    </tr>

    {{-- Body --}}
    <tr>
      <td class="email-body"
        style="background:#ffffff;padding:36px 40px;border-left:1px solid #e8e8e4;border-right:1px solid #e8e8e4;font-size:15px;line-height:1.7;color:#111111">
        @yield('body')
      </td>
    </tr>

    {{-- Footer --}}
    <tr>
      <td class="email-foot" style="background:#f8f8f6;padding:20px 32px;text-align:center;border-radius:0 0 8px 8px;border:1px solid #e8e8e4;border-top:none">
        <p style="font-size:12px;color:#888888;margin:0;line-height:1.6">
          This email was sent by {{ $shopName }}.
          If you have questions, reply to this email.
        </p>
      </td>
    </tr>

  </table>
  </td></tr>
  </table>
</div>
</body>
</html>
