#!/bin/bash
# distributor-connection-restore — put back what my patch destroyed.
#
#   apply-tenant-distributor-multi replaced everything from the page heading
#   to @endsection. That was the wrong shape of edit: it took out four things
#   that had nothing to do with adding a second distributor.
#
#     · @include('layouts.tenant._inventory-tabs')  — the tab bar, so the
#       page lost its navigation entirely
#     · the Account # field — the controller still validates and stores
#       account_number, so it was accepted and could never be entered
#     · "What your key unlocks" — the panel explaining why a shop should
#       bother connecting at all
#     · the pointer to Catalog attention
#
#   And it ADDED something that was never there: a Refresh button.
#   MARKER-PATCH-559 deliberately moved sync status and manual runs to
#   Catalog attention, "the surface staff actually watch". Putting a refresh
#   back here re-splits what was consolidated on purpose, so it's gone again.
#
#   This rebuilds the section from the committed original with the
#   multi-distributor boxes folded in, rather than patching my own damage —
#   the styles block above @section is untouched and every original class
#   (dc-card, dc-field, dc-btn, dc-unlock) is used as it was.
#
#   Test connection returns to its original form: a second submit button on
#   the credential form using formaction, so it posts that box's code with
#   whatever is typed, exactly as HLC's did.
# NO MIGRATION. Server: view:clear
set -e
if grep -q "MARKER-CONNECTION-RESTORE" resources/views/tenant/distributors/connection.blade.php; then
  echo "distributor-connection-restore already applied — aborting."; exit 1
fi
if ! grep -q "@push('styles')" resources/views/tenant/distributors/connection.blade.php; then
  echo "unexpected view shape — aborting."; exit 1
fi

python3 - <<'DCR_0_EOF'
import io
p = 'resources/views/tenant/distributors/connection.blade.php'
s = io.open(p, encoding='utf-8').read()

# Keep everything up to and including @section('content'); rebuild below it.
marker = "@section('content')"
head = s[:s.index(marker) + len(marker)]

body = """

{{-- MARKER-CONNECTION-RESTORE — rebuilt from the committed original with the
     multi-distributor boxes folded in. The previous patch replaced this whole
     section and silently dropped the tabs, the Account # field and the
     "what your key unlocks" panel. --}}
<div style="max-width:880px">
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">Distributor catalogs</h1>
  @include('layouts.tenant._inventory-tabs')

  <p class="dc-sub">Connect each distributor you buy from to unlock your cost and live availability.</p>

  <div class="dc-note">Browsing and importing the catalog works without a key. Your <b>own</b> key
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
        <div>
          <h2 class="dc-h">Your {{ $b['label'] }} account</h2>
          <p class="dc-sub" style="margin-bottom:0">Stored encrypted. Used only for your shop's cost &amp; availability.</p>
        </div>
        <div style="font-size:12px;color:var(--ia-text-dim);text-align:right">
          @if ($b['hasKey'])
            <span style="color:var(--ia-accent)">connected</span><br>
          @endif
          {{ number_format($b['linked']) }} linked item{{ $b['linked'] === 1 ? '' : 's' }}
        </div>
      </div>

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

      <form method="POST" action="{{ route('tenant.distributors.connection.key') }}" style="margin-top:14px">
        @csrf
        <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">

        <div class="dc-row">
          @foreach ($b['fields'] as $f)
            <div class="dc-field">
              <label>{{ $f['label'] }}</label>
              <input class="dc-input" type="{{ $f['type'] === 'password' ? 'text' : $f['type'] }}"
                     name="{{ $f['name'] }}" autocomplete="off"
                     placeholder="{{ $b['hasKey'] ? $b['maskedKey'] : 'paste your ' . $b['label'] . ' ' . strtolower($f['label']) }}">
            </div>
          @endforeach
          <div class="dc-field" style="max-width:180px">
            <label>Account #</label>
            <input class="dc-input" type="text" name="account_number"
                   value="{{ $b['sub']->account_number }}" placeholder="optional">
          </div>
        </div>

        @if ($b['hasKey'])
          <div style="font-size:11.5px;color:var(--ia-text-dim);margin-bottom:10px">
            Leave the credential blank to keep the saved one.
          </div>
        @endif

        <div style="display:flex;gap:10px;margin-top:4px;flex-wrap:wrap;align-items:center">
          <button class="dc-btn primary" type="submit">Save</button>
          <button class="dc-btn" type="submit"
                  formaction="{{ route('tenant.distributors.connection.test') }}">Test connection</button>
          @if ($b['sub']->last_sync_status)
            <span style="font-size:11.5px;color:var(--ia-text-dim)">
              last check: {{ $b['sub']->last_sync_status }}
            </span>
          @endif
        </div>
      </form>
    </div>
  @endforeach

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
@endsection
"""

io.open(p, 'w', encoding='utf-8').write(head + body)
print('view rebuilt')
DCR_0_EOF

echo
echo "distributor-connection-restore applied."
