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
        {{-- MARKER-PLATFORM-MSG-COMPLETE — the real mark, not the word. An
             absolute URL because a mail client has no site to be relative to,
             and alt text so a blocked image still reads as Intake. --}}
        <tr><td style="padding:26px 32px 0;">
          <img src="{{ url('/icon.svg') }}" alt="Intake" width="30" height="30"
               style="display:block;border:0;outline:none;border-radius:7px;">
        </td></tr>
        <tr><td style="padding:18px 32px 28px;font-size:15px;line-height:1.65;color:#333;">
          {!! $bodyHtml !!}
        </td></tr>
        <tr><td style="padding:16px 32px 26px;border-top:1px solid #eee;font-size:11.5px;color:#888;line-height:1.6;">
          {{-- MARKER-PLATFORM-CHROME-GLUE — one expression, no directive against
               a word character. `Intake@if(...)` did not compile and its @endif
               fataled the view. --}}
          {{ trim('Intake' . ($postal ? ' · ' . $postal : '')) }}
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
