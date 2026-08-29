{{-- MARKER-RAISE-HTML — structure only. Every word in the body came from the
     compose box; this file adds none. $blocks is the message already split
     into paragraphs and link buttons by InvestorMessenger. --}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{{ $subject }}</title>
<style>
  body{margin:0;padding:0;background:#f4f4f2;
    font-family:-apple-system,BlinkMacSystemFont,'Helvetica Neue',Arial,sans-serif}
  @media(max-width:620px){.ew{padding:0!important}.eb{padding:26px 22px!important}}
</style>
</head>
<body>
<div class="ew" style="background:#f4f4f2;padding:32px 0">
<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%">

  <tr>
    <td style="background:#0c0c0c;padding:22px 32px;border-radius:8px 8px 0 0">
      <span style="font-size:19px;font-weight:700;color:#f0f0f0;letter-spacing:-.4px">intake</span>
    </td>
  </tr>

  <tr>
    <td class="eb" style="background:#ffffff;padding:34px 40px;border-left:1px solid #e8e8e4;
      border-right:1px solid #e8e8e4;font-size:15.5px;line-height:1.7;color:#111111">

      @foreach($blocks as $block)
        @if($block['type'] === 'link')
          <table cellpadding="0" cellspacing="0" border="0" style="margin:22px 0"><tr>
            <td style="background:#BEF264;border-radius:8px">
              <a href="{{ $block['url'] }}"
                 style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:700;
                        color:#0a0a0a;text-decoration:none">{{ $block['label'] }}</a>
            </td>
          </tr></table>
          {{-- The plain URL stays under the button: some clients strip the
               table, and this link is the entire point of the message. --}}
          <p style="margin:0 0 18px;font-size:12.5px;line-height:1.6;color:#888888;word-break:break-all">
            {{ $block['url'] }}
          </p>
        @else
          <p style="margin:0 0 16px">{!! nl2br(e($block['text'])) !!}</p>
        @endif
      @endforeach

    </td>
  </tr>

  <tr>
    <td style="background:#f8f8f6;padding:18px 32px;text-align:center;border-radius:0 0 8px 8px;
      border:1px solid #e8e8e4;border-top:none">
      <p style="font-size:12px;color:#888888;margin:0;line-height:1.6">
        Sent by Intake Inc · reply to this email and it comes straight to me.
      </p>
    </td>
  </tr>

</table>
</td></tr></table>
</div>
</body>
</html>
