@extends('layouts.tenant.app')
@php $pageTitle = 'Distributor brands'; @endphp

@push('styles')
<style>
.bc-h{font-size:15px;font-weight:600;margin:0 0 4px}
.bc-sub{font-size:12.5px;color:var(--ia-text-dim);margin-bottom:14px;line-height:1.5}
.bc-legend{padding:11px 15px;border-radius:var(--ia-r-md);font-size:12.5px;line-height:1.55;margin-bottom:18px;border:1px solid rgba(190,242,100,.2);background:var(--ia-accent-soft);color:var(--ia-text-muted)}
.bc-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
.bc-input{background:var(--ia-input-bg);border:1px solid var(--ia-border);border-radius:var(--ia-r-md);padding:7px 11px;color:var(--ia-text);font-size:13px;min-width:200px}
.bc-input:focus{outline:none;border-color:var(--ia-accent)}
.bc-link{background:none;border:none;color:var(--ia-accent);font-size:12.5px;font-weight:600;cursor:pointer;padding:0}
.bc-list{border-top:.5px solid var(--ia-border);max-height:460px;overflow:auto}
.bc-row{display:flex;align-items:center;gap:12px;padding:8px 2px;border-bottom:.5px solid var(--ia-border);font-size:13px}
.bc-row label{flex:1;display:flex;align-items:center;gap:10px;cursor:pointer}
.bc-row .n{font-family:var(--ia-mono);font-size:12px;color:var(--ia-text-dim)}
.bc-row .lock{font-size:11.5px;color:var(--ia-text-dim)}
.bc-btn{display:inline-flex;align-items:center;padding:9px 16px;border-radius:var(--ia-r-md);font-size:13px;font-weight:600;cursor:pointer;border:1px solid var(--ia-accent);background:var(--ia-accent);color:var(--ia-accent-text)}
.bc-sec{margin-bottom:34px}
</style>
@endpush

@section('content')
<div class="ia-section">
  <h1 style="font-size:20px;font-weight:600;margin-bottom:14px">Distributor brands</h1>
  @include('layouts.tenant._inventory-tabs')

  @if(session('success'))<div class="bc-legend" style="margin-top:14px">{{ session('success') }}</div>@endif

  <div class="bc-legend" style="margin-top:14px">
    <b>What this does.</b> Brands you switch off stop appearing on the Import screen, so they are not offered for new imports.
    <b>What it does not do.</b> It never removes, hides or deactivates an item you already carry. Anything in your inventory,
    including everything you have in stock, stays and can still be sold, and its distributor cost and availability keep updating.
  </div>

  @forelse ($panels as $p)
    <form method="POST" action="{{ route('tenant.distributors.brands.save') }}" class="bc-sec" data-bc-form>
      @csrf
      <input type="hidden" name="code" value="{{ $p['code'] }}">
      <h2 class="bc-h">{{ $p['label'] }}</h2>
      <p class="bc-sub">
        {{ number_format(count($p['brands'])) }} brands.
        {{ number_format(collect($p['brands'])->where('enabled', true)->count()) }} on.
      </p>

      <div class="bc-bar">
        <input type="search" class="bc-input" placeholder="Find a brand" data-bc-find>
        <button type="button" class="bc-link" data-bc-all="1">Turn all on</button>
        <button type="button" class="bc-link" data-bc-all="0">Turn all off</button>
      </div>

      <div class="bc-list">
        @foreach ($p['brands'] as $b)
          <div class="bc-row" data-bc-name="{{ \Illuminate\Support\Str::lower($b['name']) }}">
            <label>
              <input type="checkbox" name="on[]" value="{{ $b['key'] }}"
                     @checked($b['enabled']) @disabled($b['locked'])>
              <span>{{ $b['name'] }}</span>
            </label>
            @if ($b['locked'])
              {{-- disabled inputs are not submitted, so a locked brand that is ON still needs its value sent --}}
              @if ($b['enabled'])<input type="hidden" name="on[]" value="{{ $b['key'] }}">@endif
              <span class="lock">Set by Intake support</span>
            @endif
            <span class="n">{{ number_format($b['items']) }} items</span>
          </div>
        @endforeach
      </div>

      <div style="margin-top:14px">
        <button type="submit" class="bc-btn">Save {{ $p['label'] }} brands</button>
      </div>
    </form>
  @empty
    <p class="bc-sub">Connect a distributor on the Connection &amp; sync tab and its brands appear here.</p>
  @endforelse
</div>

<script>
  document.querySelectorAll('[data-bc-form]').forEach(function (f) {
    var find = f.querySelector('[data-bc-find]');
    if (find) {
      find.addEventListener('input', function () {
        var q = find.value.trim().toLowerCase();
        f.querySelectorAll('.bc-row').forEach(function (r) {
          r.style.display = (q === '' || r.getAttribute('data-bc-name').indexOf(q) !== -1) ? '' : 'none';
        });
      });
    }
    f.querySelectorAll('[data-bc-all]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var on = btn.getAttribute('data-bc-all') === '1';
        f.querySelectorAll('.bc-row').forEach(function (r) {
          if (r.style.display === 'none') { return; }   // only what the search is showing
          var cb = r.querySelector('input[type=checkbox]');
          if (cb && !cb.disabled) { cb.checked = on; }
        });
      });
    });
  });
</script>
@endsection
