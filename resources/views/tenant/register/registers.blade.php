@extends('layouts.tenant.app')

{{-- MARKER-REGISTER-RECON-DISPLAY — manage physical registers + pair customer displays --}}

@php $pageTitle = 'Registers'; @endphp

@section('content')
<div style="max-width:860px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
    <h1 style="font-size:22px;font-weight:800;letter-spacing:-.02em">Registers &amp; pay displays</h1>
    <a href="{{ route('tenant.register.index') }}" class="ia-btn ia-btn-ghost">← Back to register</a>
  </div>
  <p style="color:var(--ia-muted);font-size:13.5px;margin-bottom:20px">
    Each register is a physical pay station. Pair an iPad or phone once by scanning its QR code —
    the screen then mirrors that register's cart automatically for every sale.
  </p>

  @if (session('status'))
    <div class="ia-alert ia-alert-success" style="margin-bottom:16px">{{ session('status') }}</div>
  @endif

  @foreach ($registers as $r)
    <div style="background:var(--ia-panel);border:1px solid var(--ia-border);border-radius:12px;padding:18px;margin-bottom:12px;display:flex;gap:20px;align-items:flex-start">
      <div style="flex:1">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
          <span style="font-weight:800;font-size:16px">#{{ $r->number }} — {{ $r->name }}</span>
          @if ($currentRegisterId === $r->id)
            <span style="font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:var(--ia-accent);color:#0B0B0B;border-radius:100px;padding:3px 9px">This device</span>
          @endif
        </div>
        <div style="font-size:12.5px;color:var(--ia-muted);margin-bottom:12px;word-break:break-all">
          Display link: {{ url('/pay-display/' . $r->display_token) }}
        </div>
        <div style="display:flex;gap:8px">
          <button class="ia-btn ia-btn-ghost" onclick="toggleQr({{ $r->id }})">Show pairing QR</button>
          <form method="POST" action="{{ route('tenant.register.registers.regenerate', ['id' => $r->id]) }}"
                onsubmit="return confirm('Regenerate the pairing link? All screens paired to this register will disconnect.');">
            @csrf
            <button class="ia-btn ia-btn-ghost" type="submit">Regenerate link</button>
          </form>
        </div>
      </div>
      <div id="qr-{{ $r->id }}" data-url="{{ url('/pay-display/' . $r->display_token) }}"
           style="display:none;background:#fff;border-radius:10px;padding:12px;width:170px;height:170px"></div>
    </div>
  @endforeach

  <form method="POST" action="{{ route('tenant.register.registers.store') }}"
        style="display:flex;gap:10px;margin-top:18px">
    @csrf
    <input name="name" required maxlength="80" placeholder="Register name — e.g. Front Counter"
           class="ia-input" style="flex:1">
    <button class="ia-btn ia-btn-primary" type="submit">Add register</button>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
function toggleQr(id) {
  const el = document.getElementById('qr-' + id);
  if (el.style.display === 'none') {
    if (!el.dataset.done && typeof qrcode === 'function') {
      const qr = qrcode(0, 'M');
      qr.addData(el.dataset.url);
      qr.make();
      el.innerHTML = qr.createSvgTag({ scalable: true, margin: 0 });
      el.querySelector('svg').style.cssText = 'width:100%;height:100%';
      el.dataset.done = '1';
    }
    el.style.display = 'block';
  } else {
    el.style.display = 'none';
  }
}
</script>
@endsection
