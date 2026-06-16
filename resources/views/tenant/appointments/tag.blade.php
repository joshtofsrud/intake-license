{{-- MARKER-PATCH-313 — standalone 80mm thermal service-tag print view.
     Its own minimal HTML document (not the admin layout) for clean output.
     Auto-fires the print dialog. Reads appointment_date and promised_at off
     the record — nothing computed here. --}}
@php
  $paperMm   = $tag['paper'] === '58mm' ? '58mm' : '80mm';
  $contentMm = $tag['paper'] === '58mm' ? '54mm' : '72mm';
  $name      = method_exists($appointment, 'customerName')
                 ? $appointment->customerName()
                 : trim(($appointment->customer_first_name ?? '') . ' ' . ($appointment->customer_last_name ?? ''));
  $job       = $appointment->ra_number ?: ('#' . $appointment->id);
  $apptDate  = $appointment->appointment_date ? $appointment->appointment_date->format('D M j') : '';
  $apptTime  = $appointment->appointment_time ? \Carbon\Carbon::parse($appointment->appointment_time)->format('g:ia') : '';
  $promised  = $appointment->promised_at ? tlocal_date($appointment->promised_at, 'D M j') : null;
  $logoUrl   = $tag['logo_path'] ? asset('storage/' . ltrim($tag['logo_path'], '/')) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tag {{ $job }}</title>
<style>
  @page { size: {{ $paperMm }} auto; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body { width: {{ $paperMm }}; color: #000; font-family: "JetBrains Mono", ui-monospace, Menlo, Consolas, monospace; }
  .slip { width: {{ $contentMm }}; margin: 0 auto; padding: 4mm 0 2mm; font-size: 11px; line-height: 1.45; }
  .slip + .slip { page-break-before: always; }
  .ctr { text-align: center; }
  .hr  { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .shop { font-size: 14px; font-weight: 700; letter-spacing: .04em; }
  .logo { max-width: {{ $tag['paper'] === '58mm' ? '46mm' : '60mm' }}; max-height: 16mm; display: block; margin: 0 auto 4px; }
  .lbl { font-size: 10px; letter-spacing: .16em; }
  .jobrow { display: flex; align-items: center; gap: 8px; margin: 4px 0; }
  .jobnum { font-size: 24px; font-weight: 700; line-height: 1; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 2px 0; font-size: 11px; vertical-align: top; }
  td.r { text-align: right; font-weight: 700; }
  .svc { font-size: 11px; }
  .note { font-size: 10px; }
  .stub { border: 1.5px solid #000; padding: 6px 8px; margin-top: 2px; }
  .stub .sj { font-size: 19px; font-weight: 700; }
  .scissors { text-align: center; font-size: 10px; letter-spacing: .14em; margin: 8px 0 4px; }
  .qr { width: 20mm; height: 20mm; }
  .qr img, .qr canvas { width: 100% !important; height: 100% !important; }
  .qrfallback { font-size: 8px; word-break: break-all; }
  @media screen {
    body { width: {{ $paperMm }}; margin: 24px auto; box-shadow: 0 0 0 1px #ddd; }
    .printbar { position: fixed; top: 0; left: 0; right: 0; background: #111; color: #fff;
      font-family: system-ui, sans-serif; font-size: 13px; padding: 10px 16px; display: flex;
      gap: 12px; align-items: center; justify-content: center; }
    .printbar button { background: #BEF264; color: #0a0a0a; border: 0; font-weight: 600;
      padding: 7px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    body { margin-top: 64px; }
  }
  @media print { .printbar { display: none; } }
  @if($embed ?? false) @media screen { body { margin: 0 auto !important; box-shadow: none !important; } } @endif
</style>
</head>
<body>

@unless($embed ?? false)
<div class="printbar">
  <span>Tag preview · {{ $job }} ({{ count($slips) }} {{ \Illuminate\Support\Str::plural('slip', count($slips)) }})</span>
  <button onclick="window.print()">Print</button>
</div>
@endunless

@foreach($slips as $slip)
  <div class="slip">

    @if($tag['show_header'])
      <div class="ctr" style="border-bottom:1px dashed #000;padding-bottom:6px;margin-bottom:6px;">
        @if($logoUrl)
          <img class="logo" src="{{ $logoUrl }}" alt="{{ $tenant->name }}">
        @else
          <div class="shop">{{ strtoupper($tenant->name ?? 'SHOP') }}</div>
        @endif
        @if(($tenant->phone ?? null))
          <div>{{ $tenant->phone }}</div>
        @endif
      </div>
    @endif

    <div class="ctr lbl">SERVICE TAG</div>

    <div class="jobrow">
      <div style="flex:1;">
        <div class="lbl">JOB</div>
        <div class="jobnum">{{ $job }}</div>
      </div>
      @if($tag['show_qr'])
        <div class="qr" data-qr="{{ $jobUrl }}"></div>
      @endif
    </div>

    <table>
      <tr><td>CUSTOMER</td><td class="r">{{ $name ?: '—' }}</td></tr>
      @if($tag['show_phone'] && $appointment->customer_phone)
        <tr><td>PHONE</td><td class="r" style="font-weight:400">{{ $appointment->customer_phone }}</td></tr>
      @endif
      <tr><td colspan="2"><hr class="hr"></td></tr>
      <tr><td>APPT DATE</td><td class="r" style="font-weight:400">{{ $apptDate }}{{ $apptTime ? ', '.$apptTime : '' }}</td></tr>
      @if($promised)
        <tr><td>PROMISED</td><td class="r">{{ $promised }}</td></tr>
      @endif
      @if($tag['show_bike'] && !empty($slip['bike']))
        <tr><td>BIKE</td><td class="r" style="font-weight:400">{{ $slip['bike'] }}</td></tr>
      @endif
    </table>

    @if($tag['show_services'] && !empty($slip['services']))
      <hr class="hr">
      <div class="lbl" style="margin-bottom:2px;">SERVICE</div>
      @foreach($slip['services'] as $svc)
        <div class="svc">— {{ $svc }}</div>
      @endforeach
    @endif

    @if($tag['show_note'] && !empty($appointment->staff_notes))
      <hr class="hr">
      <div class="note">NOTE: {{ \Illuminate\Support\Str::limit($appointment->staff_notes, 120) }}</div>
    @endif

    @if($tag['show_stub'])
      <div class="scissors">&#9986; &mdash; &mdash; &mdash; &mdash; &mdash; &mdash; &mdash; &mdash;</div>
      <div class="stub">
        <div class="ctr lbl">CUSTOMER CLAIM STUB</div>
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:4px;">
          <span class="sj">{{ $job }}</span>
          @if($promised)
            <span style="font-size:11px;text-align:right;">Ready<br>{{ $promised }}</span>
          @endif
        </div>
        <div style="font-size:11px;margin-top:2px;">{{ $name }}</div>
      </div>
    @endif

  </div>
@endforeach

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  (function () {
    var ok = (typeof QRCode !== 'undefined');
    document.querySelectorAll('.qr').forEach(function (el) {
      var url = el.getAttribute('data-qr');
      if (ok) {
        try { new QRCode(el, { text: url, width: 76, height: 76, correctLevel: QRCode.CorrectLevel.M }); return; }
        catch (e) {}
      }
      el.classList.add('qrfallback');
      el.textContent = url;
    });
    @unless($embed ?? false) setTimeout(function () { window.print(); }, 350); @endunless
  })();
</script>
</body>
</html>
