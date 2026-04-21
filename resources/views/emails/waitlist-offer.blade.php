<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $vars['service_name'] }} opening</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#222;line-height:1.55">

<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f4f4f4;padding:32px 12px">
  <tr>
    <td align="center">
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="560" style="background:#ffffff;border-radius:10px;overflow:hidden;max-width:560px">
        <tr>
          <td style="background:{{ $vars['accent'] }};padding:24px 28px">
            <div style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:{{ $vars['accent_text'] }};opacity:.85">{{ $vars['shop_name'] }}</div>
            <div style="font-size:22px;font-weight:600;color:{{ $vars['accent_text'] }};margin-top:4px">A spot opened up</div>
          </td>
        </tr>
        <tr>
          <td style="padding:28px">
            <p style="font-size:15px;margin:0 0 16px">Hi {{ $vars['first_name'] }},</p>
            <p style="font-size:15px;margin:0 0 20px">A spot just opened up at <b>{{ $vars['shop_name'] }}</b> for the service you're waitlisted for:</p>

            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f9f9f7;border-radius:8px;margin:0 0 24px">
              <tr>
                <td style="padding:16px 20px">
                  <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.06em">Service</div>
                  <div style="font-size:17px;font-weight:600;margin-top:2px">{{ $vars['service_name'] }}</div>
                </td>
              </tr>
              <tr>
                <td style="padding:4px 20px 16px">
                  <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.06em">When</div>
                  <div style="font-size:15px;margin-top:2px">{{ $vars['slot_datetime'] }}</div>
                </td>
              </tr>
            </table>

            <div style="text-align:center;margin:0 0 24px">
              <a href="{{ $vars['accept_url'] }}" style="display:inline-block;background:{{ $vars['accent'] }};color:{{ $vars['accent_text'] }};font-size:16px;font-weight:600;text-decoration:none;padding:14px 28px;border-radius:8px">Confirm this booking</a>
            </div>

            <p style="font-size:13.5px;color:#555;margin:0 0 16px">{{ $vars['offer_copy'] }}</p>

            <p style="font-size:13px;color:#888;margin:16px 0 0">If the button doesn't work, copy this link:<br>
              <span style="word-break:break-all;font-size:12px">{{ $vars['accept_url'] }}</span>
            </p>
          </td>
        </tr>
        <tr>
          <td style="padding:16px 28px;background:#f9f9f7;font-size:12px;color:#888;text-align:center">
            You're receiving this because you joined the waitlist at {{ $vars['shop_name'] }}.
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

</body>
</html>
