@extends('layouts.tenant.app')
@php $pageTitle = 'Distributors'; @endphp

{{-- MARKER-PATCH-HLC7A --}}

@push('styles')
<style>
.dc-toggle{width:38px;height:21px;border-radius:99px;border:.5px solid var(--ia-border);background:rgba(127,127,127,.18);position:relative;cursor:pointer;flex:none;padding:0;transition:.15s}
.dc-toggle span{position:absolute;top:2px;left:2px;width:15px;height:15px;border-radius:50%;background:#8a8a88;transition:.15s}
.dc-toggle.on{background:var(--ia-accent-soft);border-color:var(--ia-accent)}
.dc-toggle.on span{left:20px;background:var(--ia-accent)}
.dc-card{background:var(--ia-surface);border:.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:22px;margin-bottom:18px}
.dc-h{font-size:15px;font-weight:600;margin:0 0 4px}
.dc-sub{font-size:12.5px;color:var(--ia-text-dim);margin-bottom:16px;line-height:1.5}
.dc-row{display:flex;gap:16px;flex-wrap:wrap}
.dc-field{flex:1;min-width:200px;margin-bottom:14px}
.dc-field label{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600;margin-bottom:6px}
.dc-input{width:100%;background:var(--ia-input-bg);border:1px solid var(--ia-border);border-radius:var(--ia-r-md);padding:9px 11px;color:var(--ia-text);font-size:13px;font-family:var(--ia-mono)}
.dc-input:focus{outline:none;border-color:var(--ia-accent)}
.dc-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:var(--ia-r-md);font-size:13px;font-weight:600;cursor:pointer;border:1px solid var(--ia-border-strong);background:var(--ia-surface-2);color:var(--ia-text)}
.dc-btn.primary{background:var(--ia-accent);color:var(--ia-accent-text);border-color:var(--ia-accent)}
.dc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:16px}
.dc-stat{background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);padding:13px 14px}
.dc-stat .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ia-text-dim);font-weight:600}
.dc-stat .v{font-size:20px;font-weight:700;font-family:var(--ia-mono);margin-top:3px}
.dc-banner{padding:11px 15px;border-radius:var(--ia-r-md);font-size:13px;margin-bottom:16px;border:1px solid}
.dc-ok{background:rgba(99,153,34,.15);border-color:rgba(99,153,34,.4);color:#cfe6ab}
.dc-err{background:rgba(226,75,74,.12);border-color:rgba(226,75,74,.4);color:#f0a3a3}
.dc-note{font-size:12px;color:var(--ia-text-dim);background:var(--ia-accent-soft);border:1px solid rgba(190,242,100,.2);border-radius:var(--ia-r-md);padding:10px 13px;margin-bottom:16px;line-height:1.5}
.dc-unlock{display:flex;gap:9px;align-items:flex-start;margin-bottom:9px;font-size:12.5px;color:var(--ia-text-muted)}
.dc-unlock b{color:var(--ia-text)}
.dc-dim{color:var(--ia-text-dim)}
</style>
@endpush

@section('content')

{{-- MARKER-CONNECTION-RESTORE — rebuilt from the committed original with the
     multi-distributor boxes folded in. The previous patch replaced this whole
     section and silently dropped the tabs, the Account # field and the
     "what your key unlocks" panel. --}}
<div style="max-width:880px">
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">Distributor catalogs</h1>
  @include('layouts.tenant._inventory-tabs')

  <p class="dc-sub">Connect each distributor you buy from to unlock your cost and live availability.</p>

  <div class="dc-note">Turn on the distributors you buy from. Your <b>own</b> key
  unlocks <b>your cost</b> and <b>live availability</b> — per-account, never shared between shops.</div>

  @if (count($boxes) > 1)
    <div class="dc-note">
      When two distributors carry the same item, the one placed higher supplies its product
      information — the name, description and specs on your items. Use the arrows to reorder.
      This doesn't change who you buy from.
    </div>
  @endif

  @foreach ($boxes as $i => $b)
    <div class="dc-card">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:12px">
          {{-- MARKER-DIST-TOGGLE --}}
          <form method="POST" action="{{ route('tenant.distributors.connection.toggle') }}">
            @csrf
            <input type="hidden" name="code" value="{{ $b['code'] }}">
            <input type="hidden" name="enabled" value="{{ $b['enabled'] ? '' : '1' }}">
            <button type="submit" class="dc-toggle {{ $b['enabled'] ? 'on' : '' }}"
                    title="{{ $b['enabled'] ? 'Turn off' : 'Turn on' }}"><span></span></button>
          </form>
          <div>
            <h2 class="dc-h" style="margin:0">{{ $b['label'] }}</h2>
            <p class="dc-sub" style="margin-bottom:0">
              @if($b['enabled'])
                Stored encrypted. Used only for your shop's cost &amp; availability.
              @else
                Turn on to import from the {{ $b['label'] }} catalog and add your account key.
              @endif
            </p>
          </div>
        </div>
        <div style="font-size:12px;color:var(--ia-text-dim);text-align:right">
          {{-- MARKER-FLASH-MODAL — this said "connected" whenever a credential
               was STORED, so a distributor could show connected and
               auth_failed on the same card. It now reflects the last test. --}}
          @php $st = $b['sub']->last_sync_status; @endphp
          @if ($st === 'connected')
            <span style="color:var(--ia-accent)">connected</span><br>
          @elseif ($st === 'auth_failed')
            <span style="color:#E24B4A">credentials rejected</span><br>
          @elseif ($st === 'unreachable')
            {{-- MARKER-BTI-PROBE — not a credential problem. --}}
            <span style="color:var(--ia-warn,#D9A441)">couldn't reach it</span><br>
          @elseif ($b['hasKey'])
            <span style="color:var(--ia-text-dim)">saved, not tested</span><br>
          @endif
          {{ number_format($b['linked']) }} linked item{{ $b['linked'] === 1 ? '' : 's' }}
        </div>
      </div>

      {{-- MARKER-DIST-TOGGLE --}}
      @if($b['enabled'])

      @if (count($boxes) > 1)
        {{-- Position in words; the stored number never appears. Its own form so
             a reorder can't be read as a credential change. --}}
        <div style="display:flex;align-items:center;gap:10px;margin:14px 0;padding:9px 12px;
                    background:var(--ia-surface-2);border-radius:var(--ia-r-md);flex-wrap:wrap">
          <span style="font-size:12.5px;font-weight:600">
            {{ $i === 0 ? '1st' : ($i === 1 ? '2nd' : ($i === 2 ? '3rd' : ($i + 1) . 'th')) }} choice for product info
          </span>
          <span style="font-size:11.5px;color:var(--ia-text-dim);flex:1;min-width:200px">
            @if ($i === 0)
              Its name, description and specs are used when more than one distributor carries an item.
            @else
              Used only where higher-placed distributors don't carry the item.
            @endif
          </span>
          <form method="POST" action="{{ route('tenant.distributors.connection.priority') }}"
                style="display:flex;gap:5px;margin:0">
            @csrf
            <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">
            <button class="dc-btn" name="direction" value="up" style="padding:5px 11px" @disabled($i === 0)>&uarr;</button>
            <button class="dc-btn" name="direction" value="down" style="padding:5px 11px" @disabled($i === count($boxes) - 1)>&darr;</button>
          </form>
        </div>
      @endif

      {{-- MARKER-PRICE-SEED — which list price this distributor's new items
           start at, and a check of where its items sit today. Its own form, so
           it never travels through the credential form (a blank field there
           means "keep the saved key"). --}}
      @if ($b['enabled'])
        <div class="dc-row" style="border-top:.5px solid var(--ia-border);padding-top:16px;margin-top:14px">
          <div class="dc-field" style="min-width:280px">
            <label>Price new items at</label>
            <form method="POST" action="{{ route('tenant.distributors.connection.pricing') }}" style="margin:0">
              @csrf
              <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">
              <div style="display:inline-flex;border:1px solid var(--ia-border-strong);border-radius:var(--ia-r-md);overflow:hidden">
                @foreach (['msrp' => 'MSRP', 'map' => 'MAP'] as $val => $lbl)
                  <button type="submit" name="price_seed" value="{{ $val }}"
                          style="padding:8px 16px;font-size:13px;font-weight:600;border:0;cursor:pointer;
                                 {{ ($b['priceSeed'] ?? 'map') === $val
                                     ? 'background:var(--ia-accent);color:var(--ia-accent-text)'
                                     : 'background:transparent;color:var(--ia-text-dim)' }}">{{ $lbl }}</button>
                @endforeach
              </div>
            </form>
            <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.5">
              Applies to items this distributor's imports create from now on. MAP is the lowest
              price you may advertise, so pricing there gives up the margin between the two.
              Prices you set yourself are never changed.
            </div>
          </div>
          <div class="dc-field" style="min-width:240px">
            <label>Pricing check</label>
            <button type="button" class="dc-btn" data-pricing-check="{{ $b['code'] }}">Check pricing against MAP / MSRP</button>
            <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:6px;line-height:1.5">
              See where this distributor's items sit today, and fix them in one go.
            </div>
          </div>
        </div>
      @endif

      <form method="POST" action="{{ route('tenant.distributors.connection.key') }}" style="margin-top:14px">
        @csrf
        <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">

        <div class="dc-row">
          @foreach ($b['fields'] as $f)
            <div class="dc-field">
              <label>{{ $f['label'] }}</label>
              <input class="dc-input" type="{{ $f['type'] === 'password' ? 'text' : $f['type'] }}"
                     name="{{ $f['name'] }}" autocomplete="off"
                     {{-- MARKER-PARTIAL-CREDS — each field hints at ITS OWN stored
                          value. Both BTI fields used to show the whole joined
                          credential, so the username box hinted at the password. --}}
                     placeholder="{{ $b['hints'][$f['name']] ?? ('paste your ' . $b['label'] . ' ' . strtolower($f['label'])) }}">
            </div>
          @endforeach
          <div class="dc-field" style="max-width:180px">
            <label>Account #</label>
            <input class="dc-input" type="text" name="account_number"
                   value="{{ $b['sub']->account_number }}" placeholder="optional">
          </div>
        </div>

        {{-- MARKER-DIST-VENDOR-PROMPT — which vendor is this distributor --}}
        <div class="dc-row">
          <div class="dc-field" style="max-width:320px">
            <label>This distributor is which of your vendors?</label>
            <select class="dc-input" name="vendor_id">
              <option value="">Not linked — create one on first import</option>
              @foreach ($b['vendors'] as $v)
                <option value="{{ $v->id }}" @selected($b['linkedVendorId'] === $v->id)>
                  {{ $v->name }}@if($v->distributor_code && $v->distributor_code !== strtolower($b['code'])) (linked to {{ strtoupper($v->distributor_code) }})@endif
                </option>
              @endforeach
            </select>
            <div style="font-size:11.5px;color:var(--ia-text-dim);margin-top:5px;line-height:1.5">
              @if ($b['linkedVendorId'])
                Imported items, costs and stock attach to this vendor, and its
                free-freight minimum and program discount are what the
                lowest-price rule compares.
              @else
                Pick the vendor you already use for {{ $b['label'] }}. Leave it
                unlinked and the first import creates a separate vendor called
                {{ strtoupper($b['code']) }}, leaving your own record — and its
                freight minimum and discount — out of the picture.
              @endif
            </div>
          </div>
        </div>

        @if ($b['hasKey'])
          <div style="font-size:11.5px;color:var(--ia-text-dim);margin-bottom:10px">
            Leave a field blank to keep the value already saved for it.
          </div>
        @endif

        {{-- MARKER-TENANT-TEST-FEEDBACK — a 30s form post with no feedback
             reads as a hung page. Say what's happening and why. --}}
        <div style="display:flex;gap:10px;margin-top:4px;flex-wrap:wrap;align-items:center">
          <button class="dc-btn primary" type="submit" data-dc-save>Save</button>
          <button class="dc-btn" type="submit" data-dc-test
                  data-dc-slow="{{ strtoupper($b['code']) === 'BTI' ? '1' : '0' }}"
                  formaction="{{ route('tenant.distributors.connection.test') }}">Test connection</button>
        </div>
        <div data-dc-testnote
             style="display:none;font-size:11.5px;color:var(--ia-text-dim);margin-top:9px;line-height:1.5"></div>
      </form>
      @endif {{-- MARKER-DIST-TOGGLE --}}
    </div>
  @endforeach

  {{-- MARKER-PRICE-SEED — the pricing check modal. --}}
  <script>
  (function () {
    var bg = document.getElementById('pcModalBg');
    if (!bg) { return; }
    var body = document.getElementById('pcBody'), title = document.getElementById('pcTitle');
    var sweep = document.getElementById('pcSweep'), codeIn = document.getElementById('pcCode');
    var apply = document.getElementById('pcApply');
    var money = function (c) {
      return '$' + (Math.abs(c) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var row = function (label, value, strong) {
      return '<div style="display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:.5px solid var(--ia-border)'
        + (strong ? ';font-weight:600' : '')
        + '"><span>' + label + '</span><span style="font-family:var(--ia-font-mono)">' + value + '</span></div>';
    };

    document.querySelectorAll('[data-pricing-check]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var code = btn.getAttribute('data-pricing-check');
        title.textContent = code + ' pricing';
        body.innerHTML = 'Checking\u2026';
        sweep.style.display = 'none';
        bg.style.display = 'flex';

        fetch('{{ route('tenant.distributors.connection.pricing.check') }}?code=' + encodeURIComponent(code),
              { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d || !d.ok) { body.innerHTML = 'Could not read this distributor&rsquo;s pricing.'; return; }
            var s = d.stats, t = s.target.toUpperCase(), other = t === 'MSRP' ? 'MAP' : 'MSRP';
            var html = row('At MSRP', s.at_msrp.toLocaleString())
              + row('At MAP', s.at_map.toLocaleString())
              + row('Priced by you', s.shop_priced.toLocaleString())
              + row('No price from the feed', s.no_price.toLocaleString())
              + row('Still at the import&rsquo;s price, movable to ' + t, s.change.toLocaleString(), true);

            if (s.change > 0 && !d.running) {
              html += '<div style="font-size:12px;color:var(--ia-text-dim);background:var(--ia-accent-soft);'
                + 'border:1px solid rgba(233,162,59,.25);border-radius:var(--ia-r-md);padding:10px 13px;margin-top:14px;line-height:1.55">'
                + '<b>What this does.</b> ' + s.change.toLocaleString() + ' items still at the price the import gave them move to '
                + t + ', ' + (s.delta_cents >= 0 ? 'raising' : 'lowering') + ' them by ' + money(s.delta_cents)
                + ' in total, ' + Math.abs(s.avg_pct) + '% each on average. Prices you set yourself, and items with no '
                + t + ', are left alone. It is recorded as one batch, so it can be put back from Catalog changes.</div>';
              apply.textContent = 'Move ' + s.change.toLocaleString() + ' items to ' + t;
              codeIn.value = d.code;
              sweep.style.display = '';
            } else if (d.running) {
              html += '<div style="font-size:12.5px;color:var(--ia-text-dim);margin-top:14px">A sweep is already running in the background.</div>';
            } else {
              html += '<div style="font-size:12.5px;color:var(--ia-text-dim);margin-top:14px">Nothing to change: every untouched price is already at '
                + t + '. Switch the setting to ' + other + ' to check the other way.</div>';
            }
            body.innerHTML = html;
          })
          .catch(function () { body.innerHTML = 'Could not read this distributor&rsquo;s pricing.'; });
      });
    });

    document.getElementById('pcClose').addEventListener('click', function () { bg.style.display = 'none'; });
    bg.addEventListener('click', function (e) { if (e.target === bg) { bg.style.display = 'none'; } });
  })();
  </script>

  {{-- MARKER-TENANT-TEST-FEEDBACK --}}
  <script>
  (function () {
    document.querySelectorAll('[data-dc-test]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = btn.closest('form');
        if (!form) { return; }

        var slow = btn.getAttribute('data-dc-slow') === '1';
        var note = form.querySelector('[data-dc-testnote]');
        var save = form.querySelector('[data-dc-save]');

        // Let the submit proceed, then lock the row down on the next tick —
        // disabling before submit would drop the button's formaction.
        setTimeout(function () {
          btn.disabled = true;
          btn.textContent = slow ? 'Checking\u2026 about 30s' : 'Checking\u2026';
          if (save) { save.disabled = true; }
          if (note) {
            note.style.display = '';
            note.textContent = slow
              ? 'BTI has no status endpoint \u2014 the only address that answers rebuilds their entire stock feed on every request, so this takes about 30 seconds. Nothing is wrong; the page will reload with the result.'
              : 'Sending one authenticated request to confirm the credentials work.';
          }
        }, 0);
      });
    });
  })();
  </script>

  <div class="dc-card">
    {{-- MARKER-PATCH-559 — sync status + manual runs live on Catalog attention,
         the surface staff actually watch. Connection is credentials only. --}}
    <div style="font-size:12.5px;color:var(--ia-text-muted);padding:6px 0 2px">
      Looking for sync status or a manual refresh? That lives on
      <a href="{{ route('tenant.distributors.attention') }}" style="color:var(--ia-accent)">Catalog attention</a> now.
    </div>
  </div>

  <div class="dc-card">
    <h2 class="dc-h">What your key unlocks</h2>
    <div class="dc-unlock"><span style="color:var(--ia-accent)">&check;</span><div><b>Your dealer cost</b> — per-account pricing on every linked item.</div></div>
    <div class="dc-unlock"><span style="color:var(--ia-accent)">&check;</span><div><b>Live availability</b> — per-warehouse stock on the item.</div></div>
    <div class="dc-unlock"><span style="color:var(--ia-accent)">&check;</span><div><b>Pricing attention</b> — vanished cost/MAP/MSRP flags on items you stock.</div></div>
    <div class="dc-unlock"><span class="dc-dim">&cir;</span><div class="dc-dim">Without a key: catalog, MAP and MSRP are visible, but cost, availability and flags stay hidden.</div></div>
  </div>
</div>

{{-- MARKER-PRICE-SEED — the pricing check, in the app's own modal styles. --}}
<div id="pcModalBg" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:900;align-items:flex-start;justify-content:center;padding:48px 16px;overflow:auto">
  <div class="dc-card" style="width:100%;max-width:620px;margin:0" role="dialog" aria-label="Pricing check">
    <h2 class="dc-h" id="pcTitle">Pricing check</h2>
    <p class="dc-sub" id="pcSub">Where each item's price came from, and what it is now.</p>
    <div id="pcBody" style="font-size:13px">Loading…</div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap">
      <button type="button" class="dc-btn" id="pcClose">Close</button>
      <form method="POST" action="{{ route('tenant.distributors.connection.pricing.sweep') }}" id="pcSweep" style="margin:0;display:none">
        @csrf
        <input type="hidden" name="distributor_code" id="pcCode" value="">
        <button type="submit" class="dc-btn primary" id="pcApply">Apply</button>
      </form>
    </div>
  </div>
</div>
@endsection
