<x-filament-panels::page>
{{-- MARKER-BRAND-DIRECT: hairlines, no cards (the Distribution warehouses vocabulary). MARKER-BS-TABS: one brand, five tabs. --}}
<style>
  .bs-root { --line:rgba(255,255,255,.075); --line2:rgba(255,255,255,.14); --dim:#a3a3ab; --faint:#74747d; --txt:#fff;
    --violet:#8b5cf6; --vtext:#a78bfa; --vsoft:rgba(139,92,246,.17); --ok:#86efac; --amber:#fbbf24; --bad:#fca5a5; --pop:#18181b; font-size:14px; }
  html:not(.dark) .bs-root { --line:rgba(0,0,0,.08); --line2:rgba(0,0,0,.16); --dim:#52525b; --faint:#6b6b75; --txt:#18181b;
    --vtext:#6d28d9; --vsoft:rgba(139,92,246,.11); --ok:#15803d; --amber:#b45309; --bad:#b91c1c; --pop:#fff; }
  .bs-root .sub { color:var(--dim); max-width:820px; line-height:1.55; margin:0 }
  .bs-legend { color:var(--faint); font-size:12.5px; line-height:1.65; padding:11px 0; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
  .bs-legend b { color:var(--dim); font-weight:600; }
  .bs-sec { margin-top:30px; }
  .bs-sh { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; padding-bottom:10px; border-bottom:1px solid var(--line2); }
  .bs-sh h2 { font-size:16px; font-weight:600; margin:0; }
  .bs-sh p { color:var(--dim); font-size:12.5px; margin:3px 0 0; max-width:70ch; line-height:1.5; }
  .bs-tbl { width:100%; border-collapse:collapse; font-size:13.5px; }
  .bs-tbl th { font-size:11.5px; font-weight:500; color:var(--faint); text-align:left; padding:9px 10px 7px 0; border-bottom:1px solid var(--line); white-space:nowrap; }
  .bs-tbl td { padding:10px 10px 10px 0; border-bottom:1px solid var(--line); vertical-align:top; }
  .bs-tbl .n { text-align:right; font-variant-numeric:tabular-nums; } .bs-tbl .c { text-align:center; }
  .bs-dim { color:var(--dim); } .bs-faint { color:var(--faint); font-size:12.5px; }
  .bs-mono { font-family:ui-monospace,Menlo,monospace; font-size:12px; color:var(--dim); }
  .bs-btn { background:var(--violet); color:#fff; border:0; border-radius:7px; padding:7px 14px; font:inherit; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; gap:6px; align-items:center; text-decoration:none; }
  .bs-btn.g { background:transparent; color:var(--txt); border:1px solid var(--line2); }
  .bs-btn[disabled] { opacity:.5; cursor:default; }
  .bs-chip { display:inline-block; white-space:nowrap; font-size:11.5px; font-weight:600; padding:1px 8px; border-radius:99px; background:var(--vsoft); color:var(--vtext); }
  .bs-chip.ok { background:rgba(74,222,128,.12); color:var(--ok); } .bs-chip.warn { background:rgba(251,191,36,.12); color:var(--amber); } .bs-chip.bad { background:rgba(248,113,113,.12); color:var(--bad); }
  /* selects: one arrow only (the panel already draws one; never tiled) */
  .bs-root select.bs-in { background-repeat:no-repeat; background-position:right 8px center; background-size:14px; padding-right:30px; }
  .bs-in { background-color:transparent; border:1px solid var(--line2); border-radius:7px; padding:6px 10px; font:inherit; font-size:13px; color:inherit; }
  .bs-sw { position:relative; width:40px; height:22px; flex:none; display:inline-block; vertical-align:middle; }
  .bs-sw input { opacity:0; width:100%; height:100%; position:absolute; inset:0; margin:0; cursor:pointer; z-index:2; }
  .bs-sw i { position:absolute; inset:0; border-radius:99px; background:var(--line2); transition:background .12s; }
  .bs-sw i::after { content:''; position:absolute; left:3px; top:3px; width:16px; height:16px; border-radius:50%; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.3); transition:left .12s; }
  .bs-sw input:checked + i { background:var(--violet); } .bs-sw input:checked + i::after { left:21px; }
  .bs-sw input:disabled + i { opacity:.4; }
  .bs-prog { margin-top:8px; font-size:12.5px; color:var(--dim); }
  .bs-bar { height:4px; border-radius:99px; background:var(--line2); overflow:hidden; margin:6px 0 4px; max-width:420px; }
  .bs-bar i { display:block; height:100%; background:var(--violet); transition:width .6s; }
  .bs-bar.ind i { width:30%; animation:bsInd 1.4s ease-in-out infinite; }
  @keyframes bsInd { 0% { margin-left:-30% } 100% { margin-left:100% } }
  .bs-stats { display:flex; gap:0; border-bottom:1px solid var(--line); }
  .bs-stat { padding:12px 22px 12px 0; margin-right:22px; border-right:1px solid var(--line); }
  .bs-stat:last-child { border-right:0; }
  .bs-stat .k { font-size:12px; color:var(--faint); } .bs-stat .v { font-size:22px; font-weight:700; font-variant-numeric:tabular-nums; } .bs-stat .d { font-size:12px; color:var(--faint); }
  .bs-empty { padding:20px 0; color:var(--dim); line-height:1.6; }
  .bs-drop { border:1px dashed var(--line2); border-radius:9px; padding:16px; display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin-top:12px; }
  .bs-thumb { width:56px; height:42px; object-fit:cover; border-radius:5px; background:var(--line); display:block; }
  .bs-root code { font-family:ui-monospace,Menlo,monospace; font-size:11.5px; background:var(--line); padding:1px 5px; border-radius:4px; }
  .bs-err { color:var(--bad); font-size:12.5px; margin-top:6px; }
  /* MARKER-BS-TABS */
  .bs-pills { display:flex; gap:8px; flex-wrap:wrap; margin:18px 0 0; }
  .bs-pill { border:1px solid var(--line2); border-radius:99px; padding:5px 14px; font-weight:600; cursor:pointer; background:transparent; color:inherit; font:inherit; font-size:13.5px; text-decoration:none; }
  .bs-pill.on { background:var(--vsoft); border-color:var(--violet); color:var(--vtext); }
  .bs-pill.add { color:var(--dim); font-weight:500; border-style:dashed; }
  .bs-head { display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap; padding:16px 0; margin-top:16px; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
  .bs-head h2 { font-size:22px; font-weight:700; margin:0; }
  .bs-hstats { display:flex; gap:26px; margin-left:auto; flex-wrap:wrap; }
  .bs-hstats .k { font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:var(--faint); }
  .bs-hstats .v { font-size:18px; font-weight:700; font-variant-numeric:tabular-nums; }
  .bs-hstats .d { font-size:12px; color:var(--dim); }
  .bs-tabs { display:flex; gap:2px; border-bottom:1px solid var(--line2); margin:14px 0 18px; flex-wrap:wrap; }
  .bs-tab { padding:9px 14px; color:var(--dim); font-weight:600; cursor:pointer; border:0; border-bottom:2px solid transparent; margin-bottom:-1px; background:none; font:inherit; font-size:14px; }
  .bs-tab.on { color:var(--vtext); border-bottom-color:var(--violet); }
  .bs-tab .n { color:var(--faint); font-weight:500; margin-left:4px; }
  .bs-row { display:grid; grid-template-columns:190px minmax(0,1fr) auto; gap:20px; padding:14px 0; border-bottom:1px solid var(--line); align-items:start; }
  .bs-row > .l { font-weight:600; } .bs-row > .l small { display:block; color:var(--faint); font-weight:400; font-size:12.5px; margin-top:2px; }
  .bs-note { background:var(--vsoft); border-radius:8px; padding:10px 14px; font-size:13px; color:var(--dim); margin-bottom:6px; line-height:1.55; }
  .bs-note b { color:var(--txt); font-weight:600; }
  .bs-fchip { border:1px solid var(--line2); border-radius:99px; padding:3px 11px; font-size:12.5px; color:var(--dim); cursor:pointer; background:none; font:inherit; font-size:12.5px; }
  .bs-fchip.on { border-color:var(--violet); color:var(--vtext); background:var(--vsoft); }
</style>

@php
  $codes = $this->codes();
  $ro = ! $this->canEdit();
  $tzName = function_exists('admin_tz') ? admin_tz() : config('app.timezone', 'UTC');
  $at = fn ($hm) => \Carbon\Carbon::parse($hm, config('app.timezone', 'UTC'))->setTimezone($tzName)->format('g:i a');
@endphp

<div class="bs-root">
  <p class="sub">
    Brands that sell only to their own dealers. The <b>catalog</b> (names, photos, descriptions, specs) is read from the brand once for every shop;
    <b>cost and stock</b> come from the brand's one stock file, joined on the brand's part number. No shop sees a brand until you switch that shop on.
  </p>

  @if (! $codes)
    <div class="bs-empty">
      No distributor is marked brand-direct yet. Mark one on
      <a href="{{ \App\Filament\Pages\Distributors::getUrl() }}" style="color:var(--vtext)">Distributors</a>
      (Brand-direct, dealers only) and it appears here.
    </div>
  @else
  @php
    $cat = $this->catalog();
    $label = $this->label();
    $hs = $this->headerStats();
    $brandRow = collect($this->brandRows())->firstWhere('code', $code);
  @endphp

  <div class="bs-pills">
    @foreach ($this->brandRows() as $b)
      <button type="button" class="bs-pill {{ $b['code'] === $code ? 'on' : '' }}" wire:click="$set('code', '{{ $b['code'] }}')">{{ $b['label'] }}</button>
    @endforeach
    <a class="bs-pill add" href="{{ \App\Filament\Pages\Distributors::getUrl() }}" title="A brand's reader is built first, then it is switched to Brand-direct on Distributors and appears here, off for every shop">+ Add a brand</a>
  </div>

  <div class="bs-head" @if ($cat['live']) wire:poll.5s @endif>
    <div>
      <h2>{{ $label }}
        @if (! ($brandRow['shop_ready'] ?? true))<span class="bs-chip warn" style="vertical-align:4px">Shop side not built</span>
        @elseif ($hs['shops'] === 0)<span class="bs-chip" style="vertical-align:4px">No shops on</span>
        @else<span class="bs-chip ok" style="vertical-align:4px">Live</span>@endif
      </h2>
      <div class="bs-dim" style="margin-top:4px;font-size:13px">Catalog from {{ parse_url((string) config('distributors.' . strtolower($code) . '.base_url'), PHP_URL_HOST) ?: $label . "'s store" }} · cost and stock from {{ $label }}'s {{ \App\Services\Distributors\BrandStockFile::exportName($code) }}</div>
    </div>
    <div class="bs-hstats">
      <div><div class="k">Products</div><div class="v">{{ number_format($hs['products']) }}</div><div class="d">{{ number_format($hs['parts']) }} part numbers</div></div>
      @if ($hs['read'] !== null)
        <div><div class="k">Read</div><div class="v">{{ number_format($hs['read']) }} / {{ number_format($hs['of']) }}</div><div class="d">{{ $cat['live'] ? 'reading now' : ($hs['read'] >= $hs['of'] ? 'all current' : 'read catalog to finish') }}</div></div>
      @endif
      <div><div class="k">Stock file</div>
        @if ($hs['file'])<div class="v">{{ number_format($hs['file']->row_count) }} rows</div><div class="d">loaded {{ \Carbon\Carbon::parse($hs['file']->loaded_at)->diffForHumans() }}</div>
        @else<div class="v" style="color:var(--amber)">None</div><div class="d">no cost or stock yet</div>@endif
      </div>
      <div><div class="k">Shops on</div><div class="v">{{ $hs['shops'] }}</div><div class="d">of {{ $hs['eligible'] }} eligible</div></div>
    </div>
  </div>

  @php $ptotalAll = $hs['parts']; @endphp
  <div class="bs-tabs" role="tablist">
    @foreach (['read' => 'Catalog read', 'stock' => 'Stock file', 'shops' => 'Shops', 'preview' => 'Preview', 'rules' => 'Clean-up rules'] as $k => $t)
      <button type="button" role="tab" class="bs-tab {{ $tab === $k ? 'on' : '' }}" wire:click="$set('tab', '{{ $k }}')">{{ $t }}@if ($k === 'shops')<span class="n">{{ $hs['shops'] }}</span>@elseif ($k === 'preview')<span class="n">{{ number_format($ptotalAll) }}</span>@endif</button>
    @endforeach
  </div>

  {{-- ============ CATALOG READ ============ --}}
  @if ($tab === 'read')
  <div @if ($cat['live']) wire:poll.2s @endif>
    <div class="bs-note"><b>{{ $label }}'s public store, not a feed {{ $label }} sends,</b> read one product at a time. If {{ $label }} changes its site the read stops and says so here; shops keep their items, cost and stock. Nothing here changes a shop's prices or on-hand counts.</div>

    <div class="bs-row">
      <div class="l">Now<small>{{ $cat['synced'] ? 'last finished ' . $cat['synced']->diffForHumans() : 'never finished' }}</small></div>
      <div>
        @if ($cat['state'] === 'queued')
          <div class="bs-prog" style="margin:0">Waiting for a free worker…<div class="bs-bar ind"><i></i></div></div>
        @elseif ($cat['state'] === 'running')
          <div class="bs-prog" style="margin:0"><b style="color:var(--txt)">{{ $cat['line'] }}</b>
            <div class="bs-bar {{ $cat['pct'] === null ? 'ind' : '' }}"><i style="{{ $cat['pct'] !== null ? 'width:' . $cat['pct'] . '%' : '' }}"></i></div>
            <span class="bs-faint">started {{ $cat['started']?->diffForHumans() }}</span>
            @foreach (array_slice($this->recentReads(), 0, 4) as $r)
              <div class="bs-faint" style="margin-top:3px">✓ <span style="color:var(--txt)">{{ $r['title'] }}</span> · {{ $r['sizes'] }} {{ $r['sizes'] === 1 ? 'size' : 'sizes' }} · {{ $r['specs'] }} specs · {{ $r['images'] }} photos <span style="opacity:.7">{{ \Carbon\Carbon::createFromTimestamp($r['at'])->diffForHumans() }}</span></div>
            @endforeach
          </div>
        @else
          <div>
            @switch ($cat['state'])
              @case ('ok') <span class="bs-chip ok">Read</span> @break
              @case ('partial') <span class="bs-chip warn">Read with errors</span> @break
              @case ('failed') <span class="bs-chip bad">Failed</span> @break
              @default <span class="bs-chip warn">Not read yet</span>
            @endswitch
            <span class="bs-dim" style="margin-left:6px">{{ number_format($cat['products']) }} products · {{ number_format($cat['count']) }} part numbers in the catalog</span>
          </div>
        @endif
        @foreach ($cat['errors'] as $e)<div class="bs-err">{{ \Illuminate\Support\Str::limit($e, 160) }}</div>@endforeach
      </div>
      <div>
        @if ($cat['live'])
          <button type="button" class="bs-btn" style="background:#dc2626" wire:click="stopRead" wire:loading.attr="disabled" @disabled($ro || $this->stopping())>{{ $this->stopping() ? 'Stopping…' : 'Stop' }}</button>
        @else
          <button type="button" class="bs-btn" wire:click="readCatalog" wire:loading.attr="disabled" @disabled($ro)>Read catalog now</button>
        @endif
      </div>
    </div>

    <div class="bs-row">
      <div class="l">Schedule<small>a light nightly scan</small></div>
      <div class="bs-dim">Every night at {{ $at('04:55') }} the product list is checked. Only new products, and products older than "read again after" below, are read in full; most nights that is none or a few.</div>
      <div></div>
    </div>

    @if ($pace)
      @php $ps = $this->pagesStatus(); @endphp
      <div class="bs-row">
        <div class="l">Pace</div>
        <div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            One product every <input type="number" min="1" max="600" class="bs-in" style="width:76px" wire:model="pace.page_gap_s" @disabled($ro)> sec
            <span class="bs-faint">·</span>
            Read a product again after <input type="number" min="1" max="365" class="bs-in" style="width:76px" wire:model="pace.refresh_days" @disabled($ro)> days
            <button type="button" class="bs-btn g" wire:click="savePace" @disabled($ro)>Save</button>
          </div>
          @foreach (['pace.page_gap_s', 'pace.refresh_days'] as $f)
            @error($f)<div class="bs-err">{{ $message }}</div>@enderror
          @endforeach
          <div class="bs-faint" style="margin-top:6px">
            A running read picks up a new pace at its next hand-over (within 20 minutes), or straight away if you stop and start it.
            @if ($ps && $ps['of'] && $ps['have'] < $ps['of']) About {{ $ps['left_minutes'] }} minutes of reading left at this pace. @endif
          </div>
        </div>
        <div></div>
      </div>
    @endif

    @if ($this->usesKinds())
      <div class="bs-row">
        <div class="l">Kinds<small>the category under the product type</small></div>
        <div>
          <textarea class="bs-in" rows="7" style="width:100%;max-width:420px;font-size:13px" wire:model="kindsText" @disabled($ro) placeholder="One tag per line, for example Mountain Full Suspension"></textarea>
          <div class="bs-faint" style="margin-top:4px">
            The tags {{ $label }} uses to say what kind of product it is, one per line. A product gets the first one it carries, so put the most specific first
            ("Mountain Full Suspension" above "Mountain"). Becomes for example "Bikes &gt; Mountain Full Suspension". Products with none stay under their product type.
            To rename a kind, use the category clean-up rule.
          </div>
          @if ($other = $this->otherTags())
            <div class="bs-faint" style="margin-top:8px">Other tags {{ $label }}'s products carry (click to add):</div>
            <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:4px">
              @foreach ($other as $t => $n)
                <button type="button" class="bs-fchip" wire:click="addKind(@js((string) $t))" @disabled($ro)>{{ $t }} <span style="opacity:.6">{{ $n }}</span></button>
              @endforeach
            </div>
          @endif
        </div>
        <div><button type="button" class="bs-btn g" wire:click="saveKinds" @disabled($ro)>Save</button></div>
      </div>
    @endif

    @if ($this->supportsOne())
      <div class="bs-row" style="border-bottom:0">
        <div class="l">Read one product<small>to try a page first</small></div>
        <div><input type="text" class="bs-in" style="width:100%" wire:model="oneBike" placeholder="{{ $this->exampleUrl() }}"></div>
        <div><button type="button" class="bs-btn g" wire:click="readOne" wire:loading.attr="disabled" @disabled($ro || $cat['live'])>Read it</button></div>
      </div>
    @endif
  </div>
  @endif

  {{-- ============ STOCK FILE (one per brand) ============ --}}
  @if ($tab === 'stock')
  @php
    $bf = $this->brandFile();
    $fresh = $check && empty($check['error']);
    $shown = $fresh ? $check : (($bf && is_array($bf->check)) ? $bf->check : null);
  @endphp
  <div>
    <div class="bs-note"><b>One file for {{ $label }}, used by every shop switched on.</b> It sets their dealer cost and {{ $label }}'s stock on the {{ $label }} items they carry. It never changes a shop's own on-hand counts or prices it set. A new file is checked first; nothing changes until you press Load.</div>

    <div class="bs-row">
      <div class="l">In use</div>
      <div>
        @if ($bf && $bf->loaded_at)
          <b>{{ $bf->file_name }}</b>
          <div class="bs-dim">{{ number_format($bf->row_count) }} rows · {{ number_format($bf->check['matched'] ?? 0) }} in the catalog · {{ number_format($bf->check['missing_count'] ?? count($bf->check['missing'] ?? [])) }} not in it · loaded {{ \Carbon\Carbon::parse($bf->loaded_at)->diffForHumans() }} by {{ $bf->source === 'ftp' ? 'FTP' : 'upload' }}</div>
        @else
          <span class="bs-dim">No file loaded yet, so shops' {{ $label }} items have no cost or stock.</span>
        @endif
        @if ($bf && $bf->last_error)
          <div class="bs-err">Last attempt failed{{ $bf->last_attempt_at ? ' ' . \Carbon\Carbon::parse($bf->last_attempt_at)->diffForHumans() : '' }}: {{ $bf->last_error }}. The file already loaded is still in use.</div>
        @endif
      </div>
      <div></div>
    </div>

    <div class="bs-row">
      <div class="l">Load a new file<small>checked first, then loaded</small></div>
      <div>
        @if (! $fresh)
          <div class="bs-drop" style="margin-top:0">
            <input type="file" wire:model="stockFile" accept=".xlsx,.csv" @disabled($ro)>
            <span class="bs-faint" wire:loading wire:target="stockFile">Reading the file…</span>
            <span class="bs-faint" wire:loading.remove wire:target="stockFile">.xlsx or .csv as {{ $label }} sends it · Inventory ID, Base Price, Qty. Hard Available</span>
          </div>
          @error('stockFile')<div class="bs-err">{{ $message }}</div>@enderror
          @if (! empty($check['error']))<div class="bs-err">{{ $check['error'] }}</div>@endif
        @else
          <b>{{ $checkName }}</b> <span class="bs-dim">· checked: {{ number_format($check['rows']) }} rows, {{ number_format($check['matched']) }} in the catalog, {{ number_format(count($check['missing'])) }} not in it. Not loaded yet.</span>
        @endif
      </div>
      <div style="display:flex;gap:8px">
        @if ($fresh)
          @unless ($ro)<button type="button" class="bs-btn" wire:click="loadChecked" wire:loading.attr="disabled" wire:target="loadChecked">
            <span wire:loading.remove wire:target="loadChecked">Load this file</span><span wire:loading wire:target="loadChecked">Loading…</span></button>@endunless
          <button type="button" class="bs-btn g" wire:click="clearCheck">Cancel</button>
        @endif
      </div>
    </div>

    <div class="bs-row" style="{{ $ftpOpen ? '' : 'border-bottom:1px solid var(--line)' }}">
      <div class="l">FTP folder<small>fetched nightly at {{ $at('06:10') }}</small></div>
      <div>
        @if ($ftpOpen)
          <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px">
            <input type="text" class="bs-in" wire:model="ftp.host" placeholder="Host" autocomplete="off">
            <input type="text" class="bs-in" wire:model="ftp.user" placeholder="User" autocomplete="off">
            <input type="password" class="bs-in" wire:model="ftp.password" autocomplete="new-password" placeholder="Password (blank keeps it)">
            <input type="text" class="bs-in" wire:model="ftp.folder" placeholder="Folder (blank = top)" autocomplete="off">
          </div>
          <div class="bs-faint" style="margin-top:6px">The newest .xlsx, .csv or .txt in the folder is loaded. Stored encrypted.</div>
        @elseif ($this->hasFtp())
          <span class="bs-dim">Set up. The newest file in the folder is loaded each night; a file already loaded is skipped.</span>
        @else
          <span class="bs-dim">Not set up. Uploads above work without it.</span>
        @endif
      </div>
      <div style="display:flex;gap:8px">
        @unless ($ro)
          @if ($ftpOpen)
            <button type="button" class="bs-btn" wire:click="saveFtp">Save</button>
            <button type="button" class="bs-btn g" wire:click="closeFtp">Close</button>
          @else
            <button type="button" class="bs-btn g" wire:click="editFtp">{{ $this->hasFtp() ? 'Edit' : 'Set up' }}</button>
            @if ($this->hasFtp())
              <button type="button" class="bs-btn g" wire:click="fetchStock" wire:loading.attr="disabled" wire:target="fetchStock">
                <span wire:loading.remove wire:target="fetchStock">Fetch now</span><span wire:loading wire:target="fetchStock">Fetching…</span></button>
            @endif
          @endif
        @endunless
      </div>
    </div>

    @if ($shown)
      @php $check = $shown; $check['missing_count'] = $check['missing_count'] ?? count($check['missing'] ?? []); @endphp
      <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:22px;gap:12px;flex-wrap:wrap">
        <div><div style="font-weight:600">{{ $fresh ? 'Check of the new file' : 'Check of the file in use' }}</div>
          <div class="bs-faint">{{ number_format($check['rows'] ?? 0) }} rows · {{ number_format($check['matched'] ?? 0) }} in the catalog · {{ number_format($check['missing_count']) }} not in it · {{ number_format($check['no_year']['count'] ?? 0) }} with no year ({{ number_format($check['no_year']['filled'] ?? 0) }} filled from the catalog)</div></div>
        @if ($fresh && ! empty($check['missing']))<button type="button" class="bs-btn g" wire:click="downloadMissing">Download the not-found list</button>@endif
      </div>
      @if (isset($check['catalog']) && ! $check['catalog'])
        <div class="bs-empty">The catalog hadn't been read, so nothing could match. <a href="#" wire:click.prevent="$set('tab','read')" style="color:var(--vtext)">Read {{ $label }}'s catalog</a>, then check the file again.</div>
      @endif
      @php
        $issues = [];
        foreach (($check['shared_upc'] ?? []) as $g) {
          $issues[] = ['ids' => array_column($g['rows'], 'id'), 'what' => 'Same UPC ' . $g['upc'] . ' on ' . count($g['rows']) . ' part numbers', 'desc' => implode(' / ', array_column($g['rows'], 'description')), 'does' => 'Kept apart by part number; flag it to ' . $this->label()];
        }
        foreach (($check['dup_ids'] ?? []) as $d) {
          $issues[] = ['ids' => [$d], 'what' => 'Part number listed twice', 'desc' => '', 'does' => 'The last row in the file wins'];
        }
        if (($check['no_year']['count'] ?? 0) > ($check['no_year']['filled'] ?? 0)) {
          $issues[] = ['ids' => [], 'what' => number_format($check['no_year']['count'] - $check['no_year']['filled']) . ' rows have no model year, in the file or the catalog', 'desc' => 'e.g. ' . $check['no_year']['example'], 'does' => 'Imported without a year'];
        }
        foreach (array_slice(($check['price_diff'] ?? []), 0, 8) as $p) {
          $issues[] = ['ids' => [$p['id']], 'what' => 'Retail differs: file $' . number_format($p['file'], 2) . ', store $' . number_format($p['store'], 2), 'desc' => $p['description'], 'does' => 'The file\'s retail is used for the shop'];
        }
      @endphp
      @if ($issues)
        <table class="bs-tbl" style="margin-top:6px">
          <tr><th>Part number</th><th>{{ $label }}'s description</th><th>What's wrong</th><th>What Intake does</th></tr>
          @foreach ($issues as $i)
            <tr><td class="bs-mono">{!! implode('<br>', array_map('e', $i['ids'])) ?: '—' !!}</td><td class="bs-dim">{{ $i['desc'] }}</td><td>{{ $i['what'] }}</td><td class="bs-dim">{{ $i['does'] }}</td></tr>
          @endforeach
        </table>
        @if (count(($check['price_diff'] ?? [])) > 8)<div class="bs-faint" style="margin-top:6px">and {{ count(($check['price_diff'] ?? [])) - 8 }} more retail differences</div>@endif
      @endif

      @if ($check['missing'])
        <div style="margin-top:16px;font-weight:600">Not in the catalog</div>
        <table class="bs-tbl">
          <tr><th>Part number</th><th>Description</th><th class="n">Qty</th><th class="n">Retail</th></tr>
          @foreach (array_slice($check['missing'], 0, 15) as $m)
            <tr><td class="bs-mono">{{ $m['id'] }}</td><td class="bs-dim">{{ $m['description'] }}</td><td class="n">{{ $m['qty'] ?? '—' }}</td><td class="n">{{ $m['retail'] !== null ? '$' . number_format($m['retail'], 2) : '—' }}</td></tr>
          @endforeach
        </table>
        @if ($check['missing_count'] > 15)<div class="bs-faint" style="margin-top:6px">and {{ $check['missing_count'] - 15 }} more{{ $fresh ? ' in the download' : '' }}</div>@endif
      @endif
    @endif
  </div>
  @endif

  {{-- ============ SHOPS ============ --}}
  @if ($tab === 'shops')
  @php $shops = $this->shops(); @endphp
  <div>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px">
      <div class="bs-dim" style="max-width:76ch">Only shops with distributor import are listed. Switching a shop on connects it: it can import {{ $label }} straight away, with nothing to set up, and its cost and stock come from the stock file. Switching off hides new imports; items it has stay.</div>
      <input type="search" class="bs-in" style="width:240px" wire:model.live.debounce.300ms="shopSearch" placeholder="Find a shop">
    </div>
    @if (! $shops)
      <div class="bs-empty">
        @if (trim($shopSearch) !== '')
          No shop with distributor import matches "{{ $shopSearch }}". <a href="#" wire:click.prevent="$set('shopSearch','')" style="color:var(--vtext)">Show all</a>
        @else
          No shop has distributor import yet. It's an add-on, given on the shop's page under Tenants.
        @endif
      </div>
    @else
      <table class="bs-tbl">
        <tr><th>Shop</th><th>{{ $label }}</th><th>Since</th><th class="n">{{ $label }} items</th><th>Cost &amp; stock updated</th></tr>
        @foreach ($shops as $s)
          <tr wire:key="shop-{{ $s['id'] }}">
            <td>{{ $s['name'] }}</td>
            <td><label class="bs-sw"><input type="checkbox" @checked($s['on']) @disabled($ro) wire:click="toggleShop('{{ $s['id'] }}')" aria-label="{{ $label }} for {{ $s['name'] }}"><i></i></label></td>
            <td class="bs-dim">{{ $s['since'] ?? '—' }}</td>
            <td class="n">{{ $s['items'] !== null ? number_format($s['items']) : '—' }}</td>
            <td class="bs-dim">{{ $s['synced'] ? \Carbon\Carbon::parse($s['synced'], 'UTC')->diffForHumans() : '—' }}</td>
          </tr>
        @endforeach
      </table>
    @endif
  </div>
  @endif

  {{-- ============ PREVIEW ============ --}}
  @if ($tab === 'preview')
  @php $prev = $this->preview(); $ptotal = $this->previewTotal(); $ins = $this->inspected(); $cats = $this->previewCategories(); $topCats = array_slice($cats, 0, 6, true); @endphp
  <div @if ($cat['live']) wire:poll.5s @endif>
    <div class="bs-dim" style="margin-bottom:10px">The catalog as shops get it, after the clean-up rules. Inspect a row to see everything read for it and to open it as a shop's item page or online store page.</div>
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <button type="button" class="bs-fchip {{ $previewCategory === '' ? 'on' : '' }}" wire:click="$set('previewCategory','')">All {{ number_format(array_sum($cats)) }}</button>
        @foreach ($topCats as $cname => $cn)
          <button type="button" class="bs-fchip {{ $previewCategory === $cname ? 'on' : '' }}" wire:click="$set('previewCategory', @js($cname))">{{ $cname }} {{ number_format($cn) }}</button>
        @endforeach
        @if (count($cats) > count($topCats))
          <select class="bs-in" style="padding:3px 8px;font-size:12.5px" wire:model.live="previewCategory">
            <option value="">More categories…</option>
            @foreach (array_slice($cats, 6, null, true) as $cname => $cn)<option value="{{ $cname }}">{{ $cname }} ({{ number_format($cn) }})</option>@endforeach
            @if ($previewCategory !== '' && ! isset($topCats[$previewCategory]) && ! isset(array_slice($cats, 6, null, true)[$previewCategory]))<option value="{{ $previewCategory }}">{{ $previewCategory }}</option>@endif
          </select>
        @endif
      </div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <button type="button" class="bs-fchip {{ $previewFilter === 'nocolor' ? 'on' : '' }}" wire:click="setPreviewFilter('nocolor')">Missing color</button>
        <button type="button" class="bs-fchip {{ $previewFilter === 'unread' ? 'on' : '' }}" wire:click="setPreviewFilter('unread')">Not read yet</button>
        <input type="search" class="bs-in" style="width:220px" wire:model.live.debounce.300ms="previewSearch" placeholder="Part number or model">
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px" class="bs-faint">
      Previews open as
      <select class="bs-in" style="padding:3px 8px;font-size:12.5px" wire:model.live="previewShop">
        @foreach ($this->previewShops() as $s)<option value="{{ $s['id'] }}">{{ $s['name'] }}</option>@endforeach
      </select>
    </div>
    @if (! $prev)
      <div class="bs-empty">
        @if (trim($previewSearch) !== '' || $previewCategory !== '' || $previewFilter !== '')
          Nothing matches these filters. <a href="#" wire:click.prevent="$set('previewSearch',''); $set('previewCategory',''); $set('previewFilter','')" style="color:var(--vtext)">Clear them</a>
        @else
          The catalog is empty until it's read. <a href="#" wire:click.prevent="$set('tab','read')" style="color:var(--vtext)">Go to Catalog read</a>.
        @endif
      </div>
    @else
      <table class="bs-tbl">
        <tr><th></th><th>Part number</th><th>Title</th><th>Category</th><th>Size</th><th>Color</th><th>Year</th><th class="n">Retail</th><th class="n">Cost</th><th class="n">Stock</th><th class="n">Specs</th><th>Read</th><th></th></tr>
        @foreach ($prev as $p)
          <tr wire:key="pv-{{ $p['id'] }}" style="cursor:pointer;{{ $inspect === $p['id'] ? 'background:var(--vsoft)' : '' }}" wire:click="inspectRow('{{ $p['id'] }}')">
            <td>@if ($p['thumb'])<img class="bs-thumb" src="{{ $p['thumb'] }}" alt="" loading="lazy">@else<span class="bs-thumb"></span>@endif</td>
            <td class="bs-mono">{{ $p['id'] }}</td>
            <td><b>{{ $p['title'] }}</b>@unless ($p['sellable'])<div class="bs-faint">not available on {{ $label }}'s store</div>@endunless</td>
            <td class="bs-dim">{{ $p['category'] ?? '—' }}</td>
            <td>{{ $p['size'] ?? '—' }}</td>
            <td class="{{ $p['color'] ? '' : 'bs-dim' }}">{{ $p['color'] ?? '—' }}</td>
            <td>{{ $p['year'] ?? '—' }}</td>
            <td class="n">{{ $p['msrp'] }}</td>
            <td class="n {{ $p['cost'] ? '' : 'bs-dim' }}">{{ $p['cost'] ?? '—' }}</td>
            <td class="n {{ $p['qty'] !== null ? '' : 'bs-dim' }}">{{ $p['qty'] ?? '—' }}</td>
            <td class="n">{{ $p['specs'] }}</td>
            <td>@if ($p['read'] === true)<span class="bs-chip ok">current</span>@elseif ($p['read'] === false)<span class="bs-chip warn">waiting</span>@else<span class="bs-dim">—</span>@endif</td>
            <td><span style="color:var(--vtext);font-size:12.5px">{{ $inspect === $p['id'] ? 'Close' : 'Inspect' }}</span></td>
          </tr>
          @if ($ins && $inspect === $p['id'])
            <tr wire:key="pv-ins-{{ $p['id'] }}"><td colspan="13" style="padding:16px 0 22px;cursor:default">
              @php $iRow = $ins['row']; @endphp
              <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
                <b>See it as {{ collect($this->previewShops())->firstWhere('id', $previewShop ?: ($this->previewShops()[0]['id'] ?? null))['name'] ?? 'a shop' }}:</b>
                @if ($u = $this->previewUrl('inventory', (string) $iRow->id))<a class="bs-btn" href="{{ $u }}" target="_blank" rel="noopener">Item page preview</a>@endif
                @if ($u = $this->previewUrl('shop', (string) $iRow->id))<a class="bs-btn g" href="{{ $u }}" target="_blank" rel="noopener">Online store preview</a>@endif
                <span class="bs-faint">Opens on the shop's own site. Nothing is saved. The item page needs you signed in to that shop.</span>
              </div>

              <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:28px">
                <div>
                  <div class="bs-faint" style="font-weight:600;margin-bottom:4px">IN INTAKE'S CATALOG</div>
                  <table class="bs-tbl">
                    @foreach ($ins['catalog'] as [$k, $v])
                      <tr><td class="bs-faint" style="width:110px">{{ $k }}</td><td style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($v, 600) }}</td></tr>
                    @endforeach
                  </table>
                  <div class="bs-faint" style="font-weight:600;margin:14px 0 4px">SPECS ({{ count($ins['specs']) }})</div>
                  <table class="bs-tbl">
                    @forelse ($ins['specs'] as $a)
                      <tr><td class="bs-faint" style="width:140px">{{ $a['Name'] ?? '' }}</td><td>{{ $a['Value'] ?? '' }}</td></tr>
                    @empty
                      <tr><td class="bs-dim">No specs. The product page had no spec table, or it hasn't been read yet.</td></tr>
                    @endforelse
                  </table>
                </div>
                <div>
                  <div class="bs-faint" style="font-weight:600;margin-bottom:6px">PHOTOS ({{ count($ins['images']) }})</div>
                  <div style="display:flex;flex-wrap:wrap;gap:6px">
                    @foreach (array_slice($ins['images'], 0, 12) as $img)
                      <a href="{{ $img }}" target="_blank" rel="noopener"><img src="{{ $img }}" alt="" loading="lazy" style="width:120px;height:90px;object-fit:cover;border-radius:6px;background:var(--line)"></a>
                    @endforeach
                  </div>
                  @php $raw = $ins['raw']; @endphp
                  <div class="bs-faint" style="font-weight:600;margin:14px 0 4px">AS {{ strtoupper($label) }}'S SITE GAVE IT</div>
                  @if (! $raw || empty($raw['product']))
                    <div class="bs-dim">Nothing saved for this product yet.</div>
                  @else
                    @php $rp = $raw['product']; @endphp
                    <table class="bs-tbl">
                      <tr><td class="bs-faint" style="width:110px">Title</td><td>{{ $rp['title'] ?? '' }}</td></tr>
                      <tr><td class="bs-faint">Address</td><td><a href="{{ rtrim((string) config('distributors.' . strtolower($code) . '.base_url'), '/') . '/products/' . ($rp['handle'] ?? '') }}" target="_blank" rel="noopener" style="color:var(--vtext)">{{ $rp['handle'] ?? '' }}</a></td></tr>
                      <tr><td class="bs-faint">Vendor</td><td>{{ $rp['vendor'] ?? '' }}</td></tr>
                      <tr><td class="bs-faint">Type</td><td>{{ $rp['product_type'] ?? '' }}</td></tr>
                      <tr><td class="bs-faint">Tags</td><td>{{ implode(', ', (array) ($rp['tags'] ?? [])) }}</td></tr>
                      <tr><td class="bs-faint">Read</td><td>{{ isset($raw['read_at']) ? \Carbon\Carbon::createFromTimestamp($raw['read_at'])->diffForHumans() : '—' }}</td></tr>
                    </table>
                    <div class="bs-faint" style="font-weight:600;margin:12px 0 4px">SIZES</div>
                    <table class="bs-tbl">
                      <tr><th>Part number</th><th>Option</th><th class="n">Price</th><th>Available</th></tr>
                      @foreach ((array) ($rp['variants'] ?? []) as $v)
                        <tr style="{{ strtoupper((string) ($v['sku'] ?? '')) === $inspect ? 'background:var(--vsoft)' : '' }}"><td class="bs-mono">{{ $v['sku'] ?? '' }}</td><td>{{ trim(implode(' / ', array_filter([$v['option1'] ?? null, $v['option2'] ?? null, $v['option3'] ?? null]))) }}</td><td class="n">{{ isset($v['price']) ? '$' . $v['price'] : '—' }}</td><td>{{ ! empty($v['available']) ? 'yes' : 'no' }}</td></tr>
                      @endforeach
                    </table>
                    @php
                      $iSizing = (! empty($raw['sizing_tables']) && ($iCls = app(\App\Services\Distributors\DistributorRegistry::class)->adapterClass($this->code)) && method_exists($iCls, 'sizingFor')) ? $iCls::sizingFor((array) $raw['sizing_tables'], (string) ($rp['title'] ?? '')) : null;
                      $iFit = $iSizing ? \App\Support\ProductDetails::fit($iSizing, (string) $iRow->size) : null;
                      $iStory = \App\Support\ProductDetails::story($raw['story'] ?? null);
                    @endphp
                    <div class="bs-faint" style="font-weight:600;margin:12px 0 4px">SIZING CHART</div>
                    @if ($iFit)
                      <div class="bs-dim" style="margin-bottom:4px">Column "{{ $iFit['model'] }}"@if ($iFit['range']) · {{ $iRow->size }} fits about {{ $iFit['range'] }}@endif</div>
                      <table class="bs-tbl">
                        <tr><th>Height</th><th>Metric</th><th>Inseam</th><th>Size</th></tr>
                        @foreach ($iFit['rows'] as $fr)
                          <tr style="{{ $fr['fits'] ? 'background:var(--vsoft)' : '' }}"><td>{{ $fr['height_in'] }}</td><td>{{ $fr['height_cm'] }}</td><td>{{ $fr['inseam_in'] }}</td><td>{{ $fr['size'] }}</td></tr>
                        @endforeach
                      </table>
                    @else
                      <div class="bs-dim">{{ empty($raw['sizing_tables']) ? 'Not read yet. It comes with the next read of this bike.' : 'No column of ' . $this->label() . '\'s chart matches this model, so no Sizing tab is shown.' }}</div>
                    @endif
                    <div class="bs-faint" style="font-weight:600;margin:12px 0 4px">STORY{{ $iStory && $iStory['title'] ? ': ' . strtoupper($iStory['title']) : '' }}</div>
                    @if ($iStory)
                      @foreach ($iStory['blocks'] as $sb)
                        @if ($sb['t'] === 'imgs')
                          <div style="display:flex;gap:6px;flex-wrap:wrap;margin:4px 0">@foreach ($sb['items'] as $si)<div style="width:150px"><img src="{{ $si['src'] }}" alt="" loading="lazy" style="width:150px;height:100px;object-fit:cover;border-radius:6px"><div class="bs-faint" style="font-size:11px">{{ $si['caption'] }}</div></div>@endforeach</div>
                        @else
                          <div class="{{ $sb['t'] === 'h' ? '' : 'bs-dim' }}" style="margin:3px 0;{{ $sb['t'] === 'h' ? 'font-weight:600' : '' }}">{{ \Illuminate\Support\Str::limit($sb['text'], 220) }}</div>
                        @endif
                      @endforeach
                    @else
                      <div class="bs-dim">{{ array_key_exists('story', $raw) ? $this->label() . '\'s page has no story section for this bike.' : 'Not read yet. It comes with the next read of this bike.' }}</div>
                    @endif
                    @if (! empty($raw['geometry']))
                      <div class="bs-faint" style="font-weight:600;margin:12px 0 4px">GEOMETRY</div>
                      @php $gsizes = array_keys(reset($raw['geometry']) ?: []); @endphp
                      <table class="bs-tbl">
                        <tr><th></th>@foreach ($gsizes as $gs)<th class="n">{{ $gs }}</th>@endforeach</tr>
                        @foreach ($raw['geometry'] as $gl => $gv)
                          <tr><td class="bs-faint">{{ $gl }}</td>@foreach ($gsizes as $gs)<td class="n">{{ $gv[$gs] ?? '' }}</td>@endforeach</tr>
                        @endforeach
                      </table>
                    @endif
                  @endif
                </div>
              </div>
            </td></tr>
          @endif
        @endforeach
      </table>
      @php $pages = (int) ceil($ptotal / 25); @endphp
      <div style="display:flex;gap:10px;align-items:center;margin-top:8px" class="bs-faint">
        {{ number_format(($previewPage - 1) * 25 + 1) }}–{{ number_format(min($ptotal, $previewPage * 25)) }} of {{ number_format($ptotal) }}
        @if ($previewPage > 1)<button type="button" class="bs-btn g" style="padding:4px 10px" wire:click="previewPageTo({{ $previewPage - 1 }})">Previous</button>@endif
        @if ($previewPage < $pages)<button type="button" class="bs-btn g" style="padding:4px 10px" wire:click="previewPageTo({{ $previewPage + 1 }})">Next</button>@endif
      </div>
    @endif
  </div>
  @endif

  {{-- ============ CLEAN-UP RULES ============ --}}
  @if ($tab === 'rules')
  @php $rules = $this->rules(); @endphp
  <div>
    <div class="bs-sh">
      <div><h2>Clean-up rules</h2><p>{{ $label }}'s field map: how each catalog field is filled. Edited in Field Mapping like every distributor's, never in code. Changes reach the catalog on the next read.</p></div>
      <a class="bs-btn" href="{{ $this->fieldMapsUrl() }}">Open Field Mapping</a>
    </div>
    @if (! $rules)
      <div class="bs-empty">No field map for {{ $label }} yet, so the catalog read refuses to run. <a href="{{ $this->fieldMapsUrl() }}" style="color:var(--vtext)">Add rows in Field Mapping</a>.</div>
    @else
      <table class="bs-tbl">
        <tr><th>Field</th><th>From</th><th>How</th><th>Clean-up</th><th>Note</th><th></th></tr>
        @foreach ($rules as $r)
          <tr style="{{ $r['active'] ? '' : 'opacity:.5' }}">
            <td><b>{{ str_replace('_', ' ', $r['field']) }}</b></td>
            <td class="bs-mono">{{ $r['from'] }}</td>
            <td class="bs-dim">{{ $r['how'] }}</td>
            <td class="bs-dim">{!! $r['cleanup'] ? implode('<br>', array_map('e', $r['cleanup'])) : '—' !!}</td>
            <td class="bs-faint">{{ $r['notes'] }}</td>
            <td><a href="{{ $r['url'] }}" style="color:var(--vtext);font-size:12.5px">Edit</a></td>
          </tr>
        @endforeach
      </table>
    @endif
  </div>

  @endif
  @endif
</div>
</x-filament-panels::page>
