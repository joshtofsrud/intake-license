{{-- MARKER-EMAIL-CONSENT — public unsubscribe confirm / done / invalid --}}
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Email preferences</title>
<style>
  body{margin:0;background:#f4f4f2;font-family:-apple-system,BlinkMacSystemFont,'Inter',sans-serif;color:#1c1c1e}
  .card{max-width:440px;margin:64px auto;background:#fff;border:1px solid #e4e4e0;border-radius:16px;padding:34px 32px;text-align:center}
  h1{font-size:19px;margin:0 0 10px}
  p{font-size:14.5px;line-height:1.6;color:#555;margin:0 0 8px}
  .btn{display:inline-block;margin-top:18px;padding:12px 26px;border-radius:10px;border:none;cursor:pointer;font-size:14.5px;font-weight:600;background:#1c1c1e;color:#fff}
  .note{font-size:12.5px;color:#8a8a8e;margin-top:16px}
</style>
</head>
<body>
<div class="card">
@if($state === 'invalid')
  <h1>This link isn't valid</h1>
  <p>It may have been copied incompletely. Open the link from the email again.</p>
@elseif($state === 'already')
  <h1>You're already unsubscribed</h1>
  <p>{{ $tenant->name }} won't send you marketing email.</p>
  <div class="note">You'll still receive receipts and booking confirmations.</div>
@elseif($state === 'done')
  <h1>Unsubscribed</h1>
  <p>You won't get marketing email from {{ $tenant->name }} any more.</p>
  <div class="note">Receipts and booking confirmations still come through — those are about your orders, not marketing.</div>
@else
  <h1>Unsubscribe from {{ $tenant->name }}?</h1>
  <p>{{ $customer->email }} will stop receiving newsletters and promotions.</p>
  <form method="POST" action="{{ url('/email/unsubscribe/' . $customer->id . '/' . $sig) }}">
    @csrf
    <button class="btn" type="submit">Unsubscribe</button>
  </form>
  <div class="note">Receipts and booking confirmations are unaffected.</div>
@endif
</div>
</body>
</html>
