<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $subject }}</title></head>
<body style="margin:0;padding:0;background:#f4f4f2;font-family:Inter,-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#111;">
<!-- MARKER-SCHED-PUBLIC -->
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f2;padding:28px 12px;">
<tr><td align="center">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#fff;border-radius:12px;border:1px solid #e6e6e2;">
<tr><td style="padding:26px 28px 8px;">
    <div style="display:inline-block;width:22px;height:22px;border-radius:5px;background:#BEF264;vertical-align:middle;margin-right:8px;"></div>
    <span style="font-weight:700;font-size:16px;vertical-align:middle;">intake</span>
</td></tr>
<tr><td style="padding:10px 28px 0;">
    <h1 style="margin:0 0 8px;font-size:22px;line-height:1.25;letter-spacing:-.01em;">{{ $heading }}</h1>
    @if(!empty($intro))<p style="margin:0 0 16px;font-size:15px;line-height:1.55;color:#444;">{{ $intro }}</p>@endif
</td></tr>
<tr><td style="padding:4px 28px 0;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #eeeeea;">
    @foreach($rows as [$label, $value])
        <tr>
            <td style="padding:10px 0;font-size:12px;color:#777;width:88px;vertical-align:top;border-bottom:1px solid #eeeeea;">{{ $label }}</td>
            <td style="padding:10px 0;font-size:15px;line-height:1.5;vertical-align:top;border-bottom:1px solid #eeeeea;">{!! nl2br(e($value)) !!}</td>
        </tr>
    @endforeach
    </table>
</td></tr>
@if(!empty($cta))
<tr><td style="padding:22px 28px 6px;">
    <a href="{{ $cta['url'] }}" style="display:inline-block;background:#BEF264;color:#0a0a0a;text-decoration:none;font-weight:600;font-size:14px;padding:12px 20px;border-radius:8px;">{{ $cta['label'] }}</a>
</td></tr>
@endif
<tr><td style="padding:14px 28px 26px;font-size:12.5px;line-height:1.5;color:#888;">
    @if(!empty($fine)){{ $fine }}<br>@endif
    Sent by Intake · intake.works
</td></tr>
</table>
</td></tr>
</table>
</body></html>
