@extends('layouts.tenant.app')
@php $pageTitle = 'Distributors'; @endphp

{{-- MARKER-PATCH-HLC7A --}}

@push('styles')
<style>
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
<div style="max-width:880px">
  {{-- MARKER-DIST-MULTI — one box per supported distributor. --}}
  <h1 style="font-size:20px;font-weight:600;margin-bottom:6px">Distributor catalogs</h1>
  <p class="dc-sub">Connect each distributor you buy from. Browsing and importing works without a key —
  your own key unlocks <b>your cost</b> and <b>live availability</b>, per account, never shared between shops.</p>

  <div class="dc-note" style="margin-bottom:18px">
    When two distributors carry the same item, the one placed higher supplies its product
    information — the name, description and specs on your items. Use the arrows to reorder.
    This doesn't change who you buy from.
  </div>

  @foreach ($boxes as $i => $b)
    <div class="dc-card" style="margin-bottom:16px">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <h2 class="dc-h" style="margin:0">{{ $b['label'] }}</h2>
        <div style="font-size:12px;color:var(--ia-text-dim)">
          @if ($b['hasKey'])
            <span style="color:var(--ia-ok,#8FD14F)">connected</span> ·
          @endif
          {{ number_format($b['linked']) }} linked item{{ $b['linked'] === 1 ? '' : 's' }}
        </div>
      </div>

      {{-- MARKER-PRIORITY-ORDER — position, stated in words. The stored
           integer never appears; arrows swap with the neighbour. --}}
      <div style="display:flex;align-items:center;gap:10px;margin-top:10px;padding:8px 10px;
                  background:var(--ia-surface-2);border-radius:var(--ia-r-md)">
        <span style="font-size:12.5px;font-weight:600">
          {{ $i === 0 ? '1st' : ($i === 1 ? '2nd' : ($i === 2 ? '3rd' : ($i + 1) . 'th') ) }} choice for product info
        </span>
        <span style="font-size:11px;color:var(--ia-text-dim)">
          @if ($i === 0)
            Its name, description and specs are used when more than one distributor carries an item.
          @else
            Used only where higher-placed distributors don't carry the item.
          @endif
        </span>
        <span style="flex:1"></span>
        <form method="POST" action="{{ route('tenant.distributors.connection.priority') }}" style="display:flex;gap:4px;margin:0">
          @csrf
          <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">
          <button name="direction" value="up" class="ia-btn ia-btn--ghost" style="padding:3px 9px;font-size:12px"
                  @disabled($i === 0)>&uarr;</button>
          <button name="direction" value="down" class="ia-btn ia-btn--ghost" style="padding:3px 9px;font-size:12px"
                  @disabled($i === count($boxes) - 1)>&darr;</button>
        </form>
      </div>

      <form method="POST" action="{{ route('tenant.distributors.connection.key') }}" style="margin-top:12px">
        @csrf
        <input type="hidden" name="distributor_code" value="{{ $b['code'] }}">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
          @foreach ($b['fields'] as $f)
            <div>
              <label class="dc-label">{{ $f['label'] }}</label>
              <input class="dc-input" type="{{ $f['type'] }}" name="{{ $f['name'] }}"
                     autocomplete="off"
                     placeholder="{{ $b['hasKey'] ? $b['maskedKey'] : '' }}">
              @if (! empty($f['hint']))
                <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">{{ $f['hint'] }}</div>
              @endif
            </div>
          @endforeach


        </div>

        @if ($b['hasKey'])
          <div style="font-size:11px;color:var(--ia-text-dim);margin-top:8px">
            Leave the credential blank to keep the saved one and change only the priority.
          </div>
        @endif

        <div style="margin-top:12px;display:flex;gap:8px">
          <button class="ia-btn ia-btn--primary">Save</button>
        </div>
      </form>
    </div>
  @endforeach

@endsection
