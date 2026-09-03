{{-- MARKER-BILLING-NOTICE-MAIL — platform chrome for anything Intake sends a
     shop directly. Deliberately plain: a billing email that looks like a
     marketing email gets deleted, and one with no identity at all looks like
     a phishing attempt. --}}
<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $subject }}</title></head>
<body style="margin:0;padding:0;background:#f4f4f2;font-family:Inter,-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#111;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f2;padding:28px 12px;">
<tr><td align="center">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#fff;border-radius:12px;border:1px solid #e6e6e2;">

  <tr><td style="padding:24px 28px 6px;">
    <img src="{{ $logoUrl }}" alt="Intake" width="26" height="26" style="display:inline-block;vertical-align:middle;border:0">
    <span style="font-weight:700;font-size:16px;vertical-align:middle;margin-left:8px;">intake</span>
  </td></tr>

  <tr><td style="padding:8px 28px 0;">
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;letter-spacing:-.01em;">{{ $subject }}</h1>
    <div style="font-size:15px;line-height:1.6;color:#333;white-space:pre-line;">{{ $bodyText }}</div>
  </td></tr>

  @if($link)
  <tr><td style="padding:20px 28px 4px;">
    <a href="{{ $link }}" style="display:inline-block;background:#111;color:#fff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 20px;border-radius:8px;">{{ $linkLabel }}</a>
  </td></tr>
  @endif

  <tr><td style="padding:18px 28px 24px;font-size:12.5px;line-height:1.55;color:#888;border-top:1px solid #eeeeea;margin-top:12px;">
    Sent to {{ $shopName }} about your Intake account. This is a billing notice, not marketing —
    it isn't affected by your email preferences.<br>
    Intake · intake.works
  </td></tr>

</table>
</td></tr>
</table>
</body></html>
