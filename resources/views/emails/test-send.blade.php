{{-- MARKER-PATCH-143 — Test email body --}}
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Test email — {{ $shopName }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;color:#111;">
  <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f5f5;padding:32px 16px;">
    <tr>
      <td align="center">
        <table cellpadding="0" cellspacing="0" border="0" width="540" style="background:#fff;border-radius:12px;border:1px solid #e5e5e5;">
          <tr>
            <td style="padding:24px 28px;">
              <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#888;">{{ $shopName }} — test send</div>
              <h2 style="margin:8px 0 16px;font-size:18px;font-weight:600;color:#111;">
                If you're reading this, your email is wired up.
              </h2>
              <p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#333;">
                This is a test message sent from your shop's configured sender details.
                Below is what your customers will see when you send them an email.
              </p>
              <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#fafafa;border:1px solid #e5e5e5;border-radius:6px;margin-top:16px;">
                <tr>
                  <td style="padding:12px 14px;font-family:'JetBrains Mono',ui-monospace,monospace;font-size:12px;line-height:1.7;color:#444;">
                    <div><span style="color:#888;">From:</span> {{ $fromName }} &lt;{{ $fromEmail }}&gt;</div>
                    @if($replyTo)<div><span style="color:#888;">Reply-To:</span> {{ $replyTo }}</div>@endif
                  </td>
                </tr>
              </table>
              <p style="margin:18px 0 0;font-size:12px;color:#888;line-height:1.5;">
                Try replying to this email — it should go to your reply-to address (if set) or your From address.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
