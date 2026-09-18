{{-- MARKER-PLATFORM-TEMPLATES — chrome around a customised platform email.
     Deliberately plain: a table shell, the mark, the body, a footer with the
     postal address. The editor writes text, not HTML, so nothing here can be
     broken by a paste from a word processor. --}}
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;color:#111;">
  <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f5f5;padding:32px 16px;">
    <tr><td align="center">
      <table cellpadding="0" cellspacing="0" border="0" width="560" style="background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e5e5;">
        <tr><td style="padding:28px 32px 0;">
          <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#888;">Intake</div>
        </td></tr>
        <tr><td style="padding:18px 32px 28px;font-size:15px;line-height:1.65;color:#333;">
          {!! $bodyHtml !!}
        </td></tr>
        <tr><td style="padding:16px 32px 26px;border-top:1px solid #eee;font-size:11.5px;color:#888;line-height:1.6;">
          Intake@if($postal) · {{ $postal }}@endif
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
