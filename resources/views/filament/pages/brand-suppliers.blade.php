<x-filament-panels::page>
{{-- MARKER-BRAND-DIRECT: hairlines, no cards (the Distribution warehouses vocabulary). --}}
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
  .bs-in { background:transparent; border:1px solid var(--line2); border-radius:7px; padding:6px 10px; font:inherit; font-size:13px; color:inherit; }
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
</style>

@php
  $codes = $this->codes();
  $ro = ! $this->canEdit();
@endphp

<div class="bs-root">
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
    $onCount = $this->onCount();
    $ready = $this->shopSideReady();
  @endphp

  <p class="sub">
    Brands that sell only to their own dealers. For each brand, the <b>catalog</b> (names, photos, descriptions, specs)
    is read here once for every shop, and <b>cost and stock</b> come from each dealer's own stock file, joined on the
    brand's part number. No shop sees a brand until you switch that shop on.
  </p>

  {{-- every brand supplier; the one picked below is the one the rest of the page is about --}}
  <div class="bs-sec" style="margin-top:20px">
    <div class="bs-sh">
      <div><h2>Brands</h2><p>Pick a brand to manage it below.</p></div>
      <a class="bs-btn g" href="{{ \App\Filament\Pages\Distributors::getUrl() }}">Add a brand</a>
    </div>
    <table class="bs-tbl">
      <tr><th>Brand</th><th class="n">Part numbers</th><th class="n">Shops on</th><th>Catalog last read</th><th>Status</th></tr>
      @foreach ($this->brandRows() as $b)
        <tr wire:key="brand-{{ $b['code'] }}" wire:click="$set('code', '{{ $b['code'] }}')" style="cursor:pointer;{{ $b['code'] === $code ? 'background:var(--vsoft)' : '' }}">
          <td style="padding-left:10px"><b style="{{ $b['code'] === $code ? 'color:var(--vtext)' : '' }}">{{ $b['label'] }}</b></td>
          <td class="n">{{ number_format($b['count']) }}</td>
          <td class="n">{{ $b['shops'] }}</td>
          <td class="bs-dim">{{ $b['synced'] ?? 'Never' }}</td>
          <td>
            @if (! $b['shop_ready'])<span class="bs-chip warn">Shop side not built</span>
            @elseif ($b['shops'] === 0)<span class="bs-chip">No shops on</span>
            @else<span class="bs-chip ok">Live</span>@endif
          </td>
        </tr>
      @endforeach
    </table>
    <div class="bs-legend" style="border-top:0">
      <b>Adding a brand:</b> its reader is built first (where its catalog and stock file come from), then it is switched to
      Brand-direct on the Distributors page and appears in this list, off for every shop.
    </div>
  </div>

  <h2 style="font-size:22px;font-weight:700;margin:34px 0 10px">{{ $label }}</h2>

  <div class="bs-legend" style="margin-top:14px">
    <b>Who sees {{ $label }}:</b> no shop, until you switch it on for that shop under Shops below.
    @unless ($ready)
      <b>Not live for shops yet:</b> the shop side ({{ $label }} box and stock file upload) is the next build, so even shops switched on see nothing until it ships.
    @endunless
    Switching a shop off hides its box and new imports; items it already has are never removed.
  </div>

  {{-- ============ SOURCES ============ --}}
  <div class="bs-sec" @if ($cat['live']) wire:poll.2s @endif>
    <div class="bs-sh">
      <div><h2>Where {{ $label }}'s data comes from</h2><p>Each source fills only its own fields.</p></div>
      @if ($cat['live'])
        <button type="button" class="bs-btn" style="background:#dc2626" wire:click="stopRead" wire:loading.attr="disabled" @disabled($ro || $this->stopping())>
          {{ $this->stopping() ? 'Stopping…' : 'Stop the read' }}
        </button>
      @else
        <button type="button" class="bs-btn g" wire:click="readCatalog" wire:loading.attr="disabled" @disabled($ro)>Read catalog now</button>
      @endif
    </div>
    <table class="bs-tbl">
      <tr><th>Source</th><th>Fills</th><th>How often</th><th>Last</th><th>Status</th></tr>
      <tr>
        <td><b>Catalog</b><div class="bs-faint">{{ $label }}'s store: product feed + each product page</div></td>
        <td class="bs-dim">Name, photos, description, spec table, model year, sizes, retail price</td>
        <td class="bs-dim">Daily, {{ \Carbon\Carbon::parse('04:55', config('app.timezone', 'UTC'))->setTimezone(function_exists('admin_tz') ? admin_tz() : config('app.timezone', 'UTC'))->format('g:i a') }}</td>
        <td>
          @if ($cat['synced'])
            {{ $cat['synced']->diffForHumans() }}<div class="bs-faint">{{ number_format($cat['products']) }} {{ $cat['products'] === 1 ? 'bike' : 'bikes' }} · {{ number_format($cat['count']) }} part {{ $cat['count'] === 1 ? 'number' : 'numbers' }}</div>
          @else
            <span class="bs-dim">Never read</span>
          @endif
          @if ($cat['state'] === 'queued')
            <div class="bs-prog">Waiting for a free worker…<div class="bs-bar ind"><i></i></div></div>
          @elseif ($cat['state'] === 'running')
            <div class="bs-prog">{{ $cat['line'] }}
              <div class="bs-bar {{ $cat['pct'] === null ? 'ind' : '' }}"><i style="{{ $cat['pct'] !== null ? 'width:' . $cat['pct'] . '%' : '' }}"></i></div>
              <span class="bs-faint">started {{ $cat['started']?->diffForHumans() }}</span>
              @foreach (array_slice($this->recentReads(), 0, 4) as $r)
                <div class="bs-faint" style="margin-top:3px">✓ {{ $r['title'] }} · {{ $r['sizes'] }} {{ $r['sizes'] === 1 ? 'size' : 'sizes' }} · {{ $r['specs'] }} specs · {{ $r['images'] }} photos <span style="opacity:.7">{{ \Carbon\Carbon::createFromTimestamp($r['at'])->diffForHumans() }}</span></div>
              @endforeach
            </div>
          @endif
          @foreach ($cat['errors'] as $e)<div class="bs-err">{{ \Illuminate\Support\Str::limit($e, 160) }}</div>@endforeach
        </td>
        <td>
          @switch ($cat['state'])
            @case ('running') <span class="bs-chip">Reading</span> @break
            @case ('queued') <span class="bs-chip">Queued</span> @break
            @case ('ok') <span class="bs-chip ok">Read</span> @break
            @case ('partial') <span class="bs-chip warn">Read with errors</span> @break
            @case ('failed') <span class="bs-chip bad">Failed</span> @break
            @default <span class="bs-chip warn">Not read yet</span>
          @endswitch
        </td>
      </tr>
      <tr>
        <td><b>Stock file</b><div class="bs-faint">{{ $label }}'s "Item Valuation Data" export, per dealer</div></td>
        <td class="bs-dim">Dealer cost, retail, stock on hand, UPC, model year</td>
        <td class="bs-dim">When a shop uploads one, or from a folder it sets up</td>
        <td class="bs-dim">{{ $ready ? 'In each shop\'s ' . $label . ' box' : 'Shop side not built yet: check a file below' }}</td>
        <td><span class="bs-chip">{{ $onCount }} {{ $onCount === 1 ? 'shop' : 'shops' }} on</span></td>
      </tr>
    </table>
    @if ($pace)
      @php $ps = $this->pagesStatus(); @endphp
      <div style="padding:12px 0;border-bottom:1px solid var(--line)">
        <div style="display:flex;gap:18px;align-items:flex-end;flex-wrap:wrap">
          <label class="bs-faint">One bike every<br><input type="number" min="1" max="600" class="bs-in" style="width:80px" wire:model="pace.page_gap_s" @disabled($ro)> sec</label>
          <label class="bs-faint">Read a bike again after<br><input type="number" min="1" max="365" class="bs-in" style="width:80px" wire:model="pace.refresh_days" @disabled($ro)> days</label>
          <button type="button" class="bs-btn g" wire:click="savePace" @disabled($ro)>Save</button>
        </div>
        @foreach (['pace.page_gap_s', 'pace.refresh_days'] as $f)
          @error($f)<div class="bs-err">{{ $message }}</div>@enderror
        @endforeach
        <div class="bs-faint" style="margin-top:8px">
          How gently {{ $label }}'s site is read. The list of bikes comes from its sitemap in one request; then each bike is read
          the way "Read this one" reads it (its data, then its page), one bike at a time. A bike already read is left alone until
          the period above has passed. One press reads every bike that is due, handing over between background jobs as it goes.
          @if ($ps && $ps['of'])
            <b style="color:var(--dim)">{{ number_format($ps['have']) }} of {{ number_format($ps['of']) }} bikes read.</b>
            @if ($ps['have'] < $ps['of']) About {{ $ps['left_minutes'] }} minutes of reading left at this pace. @endif
          @endif
        </div>
      </div>
    @endif
    @if ($this->supportsOne())
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 0;border-bottom:1px solid var(--line)">
        <span class="bs-dim" style="font-size:13px">Try one product first:</span>
        <input type="text" class="bs-in" style="flex:1;min-width:280px" wire:model="oneBike" placeholder="{{ $this->exampleUrl() }}">
        <button type="button" class="bs-btn g" wire:click="readOne" wire:loading.attr="disabled" @disabled($ro || $cat['live'])>Read this one</button>
      </div>
    @endif
    <div class="bs-legend" style="border-top:0">
      <b>The catalog is {{ $label }}'s public store, not a feed {{ $label }} sends.</b> If they change their site the read stops and
      this row says so; shops keep their cost and stock. A product page is read again only when {{ $label }} changes that product.
    </div>
  </div>

  {{-- ============ SHOPS ============ --}}
  @php $shops = $this->shops(); @endphp
  <div class="bs-sec">
    <div class="bs-sh">
      <div><h2>Shops</h2><p>Only shops with distributor import are listed. Switch on the ones that are {{ $label }} dealers; each switch saves straight away.</p></div>
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
        <tr><th>Shop</th><th>{{ $label }}</th><th>Since</th></tr>
        @foreach ($shops as $s)
          <tr wire:key="shop-{{ $s['id'] }}">
            <td>{{ $s['name'] }}</td>
            <td><label class="bs-sw"><input type="checkbox" @checked($s['on']) @disabled($ro) wire:click="toggleShop('{{ $s['id'] }}')" aria-label="{{ $label }} for {{ $s['name'] }}"><i></i></label></td>
            <td class="bs-dim">{{ $s['since'] ?? '—' }}</td>
          </tr>
        @endforeach
      </table>
    @endif
  </div>

  {{-- ============ STOCK FILE CHECK ============ --}}
  <div class="bs-sec">
    <div class="bs-sh">
      <div><h2>Check a stock file against the catalog</h2>
        <p>Drop in a dealer's {{ $label }} export to see which part numbers match the catalog and what needs a look. Nothing is saved or imported.</p></div>
      @if ($check && empty($check['error']))
        <div style="display:flex;gap:8px">
          @if (! empty($check['missing']))<button type="button" class="bs-btn g" wire:click="downloadMissing">Download the not-found list</button>@endif
          <button type="button" class="bs-btn g" wire:click="clearCheck">Check another file</button>
        </div>
      @endif
    </div>

    @if (! $check || ! empty($check['error']))
      <div class="bs-drop">
        <input type="file" wire:model="stockFile" accept=".xlsx,.csv" @disabled($ro)>
        <span class="bs-faint" wire:loading wire:target="stockFile">Reading the file…</span>
        <span class="bs-faint" wire:loading.remove wire:target="stockFile">.xlsx or .csv, as {{ $label }} sends it (Inventory ID, Description, Year, Qty. Hard Available, Base Price, Retail Price, UPC).</span>
      </div>
      @error('stockFile')<div class="bs-err">{{ $message }}</div>@enderror
      @if (! empty($check['error']))<div class="bs-err">{{ $check['error'] }}</div>@endif
    @else
      <div class="bs-faint" style="margin-top:10px">{{ $checkName }}</div>
      <div class="bs-stats">
        <div class="bs-stat"><div class="k">Rows</div><div class="v">{{ number_format($check['rows']) }}</div><div class="d">part numbers in the file</div></div>
        <div class="bs-stat"><div class="k">Found in the catalog</div><div class="v">{{ number_format($check['matched']) }}</div><div class="d">get photos and specs</div></div>
        <div class="bs-stat"><div class="k">Not in the catalog</div><div class="v" style="color:{{ count($check['missing']) ? 'var(--amber)' : 'inherit' }}">{{ number_format(count($check['missing'])) }}</div><div class="d">{{ $check['catalog'] ? 'sold with the file\'s data only' : 'catalog not read yet' }}</div></div>
        <div class="bs-stat"><div class="k">No model year</div><div class="v">{{ number_format($check['no_year']['count']) }}</div><div class="d">{{ number_format($check['no_year']['filled']) }} filled from the catalog</div></div>
      </div>

      @if (! $check['catalog'])
        <div class="bs-empty">The catalog hasn't been read yet, so nothing can match. <a href="#" wire:click.prevent="readCatalog" style="color:var(--vtext)">Read {{ $label }}'s catalog now</a>, then check this file again.</div>
      @endif

      @php
        $issues = [];
        foreach ($check['shared_upc'] as $g) {
          $issues[] = ['ids' => array_column($g['rows'], 'id'), 'what' => 'Same UPC ' . $g['upc'] . ' on ' . count($g['rows']) . ' part numbers', 'desc' => implode(' / ', array_column($g['rows'], 'description')), 'does' => 'Kept apart by part number; flag it to Kona'];
        }
        foreach ($check['dup_ids'] as $d) {
          $issues[] = ['ids' => [$d], 'what' => 'Part number listed twice', 'desc' => '', 'does' => 'The last row in the file wins'];
        }
        if ($check['no_year']['count'] > $check['no_year']['filled']) {
          $issues[] = ['ids' => [], 'what' => number_format($check['no_year']['count'] - $check['no_year']['filled']) . ' rows have no model year, in the file or the catalog', 'desc' => 'e.g. ' . $check['no_year']['example'], 'does' => 'Imported without a year'];
        }
        foreach (array_slice($check['price_diff'], 0, 8) as $p) {
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
        @if (count($check['price_diff']) > 8)<div class="bs-faint" style="margin-top:6px">and {{ count($check['price_diff']) - 8 }} more retail differences</div>@endif
      @endif

      @if ($check['missing'])
        <div style="margin-top:16px;font-weight:600">Not in the catalog</div>
        <table class="bs-tbl">
          <tr><th>Part number</th><th>Description</th><th class="n">Qty</th><th class="n">Retail</th></tr>
          @foreach (array_slice($check['missing'], 0, 15) as $m)
            <tr><td class="bs-mono">{{ $m['id'] }}</td><td class="bs-dim">{{ $m['description'] }}</td><td class="n">{{ $m['qty'] ?? '—' }}</td><td class="n">{{ $m['retail'] !== null ? '$' . number_format($m['retail'], 2) : '—' }}</td></tr>
          @endforeach
        </table>
        @if (count($check['missing']) > 15)<div class="bs-faint" style="margin-top:6px">and {{ count($check['missing']) - 15 }} more in the download</div>@endif
      @endif
    @endif
  </div>

  {{-- ============ RULES ============ --}}
  @php $rules = $this->rules(); @endphp
  <div class="bs-sec">
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

  {{-- ============ PREVIEW ============ --}}
  @php $prev = $this->preview(); $ptotal = $this->previewTotal(); $ins = $this->inspected(); @endphp
  <div class="bs-sec" @if ($cat['live']) wire:poll.5s @endif>
    <div class="bs-sh">
      <div><h2>Preview</h2><p>The catalog as shops will get it, after the rules above. It fills in as bikes are read. Inspect a row to see everything read for it and to open it as a shop's item page or online store page.</p></div>
      <input type="search" class="bs-in" style="width:240px" wire:model.live.debounce.300ms="previewSearch" placeholder="Part number or model">
    </div>
    @if (! $prev)
      <div class="bs-empty">
        @if (trim($previewSearch) !== '')
          Nothing matches "{{ $previewSearch }}". <a href="#" wire:click.prevent="$set('previewSearch','')" style="color:var(--vtext)">Clear</a>
        @else
          The catalog is empty until it's read. <a href="#" wire:click.prevent="readCatalog" style="color:var(--vtext)">Read {{ $label }}'s catalog now</a>; progress shows under Sources.
        @endif
      </div>
    @else
      <table class="bs-tbl">
        <tr><th></th><th>Part number</th><th>Title</th><th>Brand</th><th>Size</th><th>Color</th><th>Year</th><th>Category</th><th class="n">Retail</th><th class="n">Photos</th><th class="n">Specs</th><th></th></tr>
        @foreach ($prev as $p)
          <tr wire:key="pv-{{ $p['id'] }}" style="cursor:pointer;{{ $inspect === $p['id'] ? 'background:var(--vsoft)' : '' }}" wire:click="inspectRow('{{ $p['id'] }}')">
            <td>@if ($p['thumb'])<img class="bs-thumb" src="{{ $p['thumb'] }}" alt="" loading="lazy">@else<span class="bs-thumb"></span>@endif</td>
            <td class="bs-mono">{{ $p['id'] }}</td>
            <td><b>{{ $p['title'] }}</b>@unless ($p['sellable'])<div class="bs-faint">not available on {{ $label }}'s store</div>@endunless</td>
            <td>{{ $p['brand'] ?? '—' }}</td>
            <td>{{ $p['size'] ?? '—' }}</td>
            <td class="bs-dim">{{ $p['color'] ?? '—' }}</td>
            <td>{{ $p['year'] ?? '—' }}</td>
            <td class="bs-dim">{{ $p['category'] ?? '—' }}</td>
            <td class="n">{{ $p['msrp'] }}</td>
            <td class="n">{{ $p['images'] }}</td>
            <td class="n">{{ $p['specs'] }}</td>
            <td><span style="color:var(--vtext);font-size:12.5px">{{ $inspect === $p['id'] ? 'Close' : 'Inspect' }}</span></td>
          </tr>
          @if ($ins && $inspect === $p['id'])
            <tr wire:key="pv-ins-{{ $p['id'] }}"><td colspan="12" style="padding:16px 0 22px;cursor:default">
              @php $iRow = $ins['row']; @endphp
              <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
                <b>See it as a shop:</b>
                <select class="bs-in" wire:model.live="previewShop">
                  @foreach ($this->previewShops() as $s)<option value="{{ $s['id'] }}">{{ $s['name'] }}</option>@endforeach
                </select>
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
</div>
</x-filament-panels::page>
