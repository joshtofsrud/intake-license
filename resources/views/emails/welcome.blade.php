{{-- MARKER-PATCH-143 — Welcome email body --}}
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Welcome to Intake</title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,'Segoe UI',Roboto,sans-serif;color:#111;">
  <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f5f5;padding:32px 16px;">
    <tr>
      <td align="center">
        <table cellpadding="0" cellspacing="0" border="0" width="560" style="background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e5e5;">
          <tr>
            <td style="padding:28px 32px 0;">
              <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#888;">Intake</div>
              <h1 style="margin:8px 0 0;font-size:22px;font-weight:600;line-height:1.3;color:#111;">
                Welcome, {{ $user->name }}.
              </h1>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 32px;font-size:15px;line-height:1.6;color:#333;">
              <p style="margin:0 0 16px;">
                Your shop <strong>{{ $tenant->name }}</strong> is ready on Intake.
                The link below will take you to your admin where you can set up services,
                staff, and your calendar.
              </p>

              @if($tempPassword)
                <table cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#fafafa;border:1px solid #e5e5e5;border-radius:8px;margin:20px 0;">
                  <tr>
                    <td style="padding:16px 18px;font-family:'JetBrains Mono',ui-monospace,monospace;font-size:13px;line-height:1.8;">
                      <div><span style="color:#888;">Sign-in URL:</span><br>
                        <a href="{{ $loginUrl }}" style="color:#0066cc;text-decoration:none;">{{ $loginUrl }}</a>
                      </div>
                      <div style="margin-top:10px;"><span style="color:#888;">Email:</span><br>{{ $user->email }}</div>
                      <div style="margin-top:10px;"><span style="color:#888;">Temporary password:</span><br><strong>{{ $tempPassword }}</strong></div>
                    </td>
                  </tr>
                </table>
                <p style="margin:0 0 16px;font-size:13px;color:#666;">
                  Sign in and change your password right away. You won't see this password again.
                </p>
              @else
                <p style="margin:0 0 16px;">
                  <a href="{{ $loginUrl }}" style="display:inline-block;background:#BEF264;color:#0a0a0a;padding:11px 22px;border-radius:6px;text-decoration:none;font-weight:600;">
                    Sign in
                  </a>
                </p>
              @endif

              <p style="margin:24px 0 0;font-size:13px;color:#666;line-height:1.6;">
                Questions? Reply to this email and someone from Intake will help.
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 32px;border-top:1px solid #e5e5e5;font-size:12px;color:#888;">
              You're getting this because you just created an Intake account at
              <a href="{{ $loginUrl }}" style="color:#666;">{{ $tenant->subdomain }}.intake.works</a>.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
