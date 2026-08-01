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
    @php
      // MARKER-RENTAL-WAIVER-DISPLAY-UI — inline the drawn signature. Base64
      // rather than a path so DomPDF never depends on filesystem access.
      $sigPath = $signaturePath ?? null;
      $sigData = null;
      if ($sigPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($sigPath)) {
          try {
              $sigData = 'data:image/png;base64,' . base64_encode(
                  \Illuminate\Support\Facades\Storage::disk('public')->get($sigPath)
              );
          } catch (\Throwable $e) {
              $sigData = null;
          }
      }
    @endphp
    @if($sigData)
      <img src="{{ $sigData }}" alt="Signature" style="max-height:60px;margin-bottom:4px">
    @endif
    <b>{{ $signerName }}</b>
    <div class="meta">{{ ($rental->agreement_method ?? 'desk') === 'display' ? 'Signed on the customer display' : 'Signed at the counter' }} · {{ tlocal_datetime($signedAt, 'M j, Y g:i A') }} · Agreement v{{ $template->version }} · Customer: {{ $rental->customer?->fullName() }}</div>
  </div>
</body>
</html>
