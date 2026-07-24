<!DOCTYPE html>
{{-- MARKER-PATCH-232 — rendered + stored at signature; never re-rendered. --}}
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; line-height: 1.55; }
  h1 { font-size: 16px; margin: 0 0 2px; }
  .sub { color: #777; font-size: 10px; margin-bottom: 18px; }
  .body { white-space: pre-wrap; margin-bottom: 26px; }
  .sig { border-top: 1px solid #999; padding-top: 10px; width: 60%; }
  .sig b { font-size: 13px; }
  .meta { color: #777; font-size: 9.5px; margin-top: 4px; }
</style>
</head>
<body>
  <h1>{{ $template->title }}</h1>
  <div class="sub">{{ $tenant->name }} · Rental {{ $rental->rental_number }} · {{ tlocal_datetime($rental->starts_at, 'M j, Y g:i A') }} → {{ tlocal_datetime($rental->due_at, 'M j, Y g:i A') }}</div>
  <div class="body">{{ $template->body }}</div>
  <div class="sig">
    <b>{{ $signerName }}</b>
    <div class="meta">Signed at the counter · {{ tlocal_datetime($signedAt, 'M j, Y g:i A') }} · Agreement v{{ $template->version }} · Customer: {{ $rental->customer?->fullName() }}</div>
  </div>
</body>
</html>
