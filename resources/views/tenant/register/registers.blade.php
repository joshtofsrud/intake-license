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
        {{-- MARKER-REGISTER-RECON-DISPLAY — welcome-screen logo choice --}}
        <form method="POST" action="{{ route('tenant.register.registers.update', ['id' => $r->id]) }}"
              style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
          @csrf
          <label style="font-size:12.5px;color:var(--ia-muted)">Welcome-screen logo</label>
          <select name="display_logo" class="ia-input" style="max-width:210px;font-size:13px"
                  onchange="this.form.submit()">
            <option value="auto"  @selected($r->display_logo === 'auto')>Auto (light, then main)</option>
            <option value="light" @selected($r->display_logo === 'light')>Light logo</option>
            <option value="main"  @selected($r->display_logo === 'main')>Main logo</option>
            <option value="none"  @selected($r->display_logo === 'none')>No logo</option>
          </select>
        </form>
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

{{-- MARKER-OFFLINE-SYNC stage 2 — per-device offline settings. Everything in
     this card lives on THIS device (localStorage / caches), not the server. --}}
@php $osEnabled = app(\App\Services\FeatureAccessService::class)->hasAddon(app('tenant'), 'offline_sync'); @endphp
@if ($osEnabled)
<div style="background:var(--ia-panel);border:1px solid var(--ia-border);border-radius:12px;padding:18px;margin-top:22px">
  <div style="font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ia-muted);margin-bottom:4px">Offline sync — this device</div>
  <div style="font-size:12.5px;color:var(--ia-muted);margin-bottom:14px">The register on this device keeps selling through outages. Snapshot and queue live on-device.</div>
  <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin-bottom:12px">
    <label style="font-size:13px">Catalog snapshot
      <select id="osSnapSize" class="ia-input" style="margin-left:8px;font-size:13px">
        <option value="250">Top 250 items</option>
        <option value="500" selected>Top 500 items</option>
        <option value="1000">Top 1,000 items</option>
      </select>
    </label>
    <span style="font-size:12.5px;color:var(--ia-muted)" id="osSnapInfo">Snapshot: checking…</span>
  </div>
  <div style="display:flex;gap:8px">
    <button class="ia-btn ia-btn-ghost" onclick="osRefreshNow()">Refresh snapshot now</button>
    <button class="ia-btn ia-btn-ghost" onclick="osClearDevice()">Clear device cache</button>
  </div>
</div>
<script>
(function () {
  const KEY = 'ia_offline_catalog', SIZE_KEY = 'ia_offline_snap_size';
  const sel = document.getElementById('osSnapSize');
  sel.value = localStorage.getItem(SIZE_KEY) || '500';
  sel.addEventListener('change', () => { localStorage.setItem(SIZE_KEY, sel.value); osRefreshNow(); });
  function info() {
    try {
      const snap = JSON.parse(localStorage.getItem(KEY) || 'null');
      const el = document.getElementById('osSnapInfo');
      if (!snap) { el.textContent = 'Snapshot: none yet — open the register while online.'; return; }
      const n = (snap.products || []).length + (snap.services || []).length;
      el.textContent = 'Snapshot: ' + n + ' items · ' + new Date(snap.captured_at).toLocaleString([], { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
      if (navigator.storage && navigator.storage.estimate) {
        navigator.storage.estimate().then(e => {
          if (e.usage) el.textContent += ' · ' + (e.usage / 1048576).toFixed(1) + ' MB on device';
        });
      }
    } catch (e) {}
  }
  window.osRefreshNow = async function () {
    try {
      const url = new URL(@json(route('tenant.register.offline_catalog')), window.location.origin);
      url.searchParams.set('limit', sel.value);
      const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const d = await r.json();
      if (d.ok) { localStorage.setItem(KEY, JSON.stringify(d)); info(); }
    } catch (e) { alert('Could not refresh — are you online?'); }
  };
  window.osClearDevice = async function () {
    localStorage.removeItem(KEY);
    try { indexedDB.deleteDatabase('intake-offline'); indexedDB.deleteDatabase('intake-offline-punches'); } catch (e) {}
    if (window.caches) (await caches.keys()).forEach(k => { if (k.startsWith('ia-offline')) caches.delete(k); });
    info();
  };
  info();
})();
</script>
@endif
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
