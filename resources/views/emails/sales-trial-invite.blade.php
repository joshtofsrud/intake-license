{{-- MARKER-SALES-INVITE --}}
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,sans-serif;font-size:15px;line-height:1.6;color:#111">
  <p>Hi {{ $ownerName ?: 'there' }},</p>

  @if($note)
    <p style="white-space:pre-line">{{ $note }}</p>
  @else
    <p>Here is the link to start {{ $prospect->shop }}'s Intake trial on the {{ $plan }} plan. It takes about five minutes to set up, nothing is charged until the trial ends, and your shop name is already filled in.</p>
  @endif

  <p style="margin:26px 0">
    <a href="{{ $signupUrl }}"
       style="display:inline-block;padding:13px 26px;border-radius:8px;font-weight:600;text-decoration:none;background:#BEF264;color:#0a0a0a">Start the trial</a>
  </p>

  <p style="font-size:13px;opacity:.6">If the button doesn't work, paste this into your browser:<br>{{ $signupUrl }}</p>

  <p>{{ $sentBy ? "— $sentBy, Intake" : '— Intake' }}</p>
</div>
