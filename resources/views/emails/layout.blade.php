@php
  $accent     = $tenant->accent_color ?? '#BEF264';
  $accentText = \App\Support\ColorHelper::accentTextColor($accent);
  $shopName   = $tenant->name;
  $logo       = $tenant->logo_url;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{{ $subject ?? $shopName }}</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style>
  body{margin:0;padding:0;background:#f4f4f2;font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif}
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
      <td style="background:#111111;padding:24px 32px;text-align:center;border-radius:8px 8px 0 0">
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
      <td style="background:#f8f8f6;padding:20px 32px;text-align:center;border-radius:0 0 8px 8px;border:1px solid #e8e8e4;border-top:none">
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
