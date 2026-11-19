<x-filament-panels::page>
{{-- MARKER-BRAND-BUILDER: Add a brand, four steps. Same hairline vocabulary as Brand suppliers. --}}
<style>
  .ab-root { --line:rgba(255,255,255,.075); --line2:rgba(255,255,255,.14); --dim:#a3a3ab; --faint:#74747d; --txt:#fff;
    --violet:#8b5cf6; --vtext:#a78bfa; --vsoft:rgba(139,92,246,.17); --ok:#86efac; --amber:#fbbf24; --bad:#fca5a5; font-size:14px; }
  html:not(.dark) .ab-root { --line:rgba(0,0,0,.08); --line2:rgba(0,0,0,.16); --dim:#52525b; --faint:#6b6b75; --txt:#18181b;
    --vtext:#6d28d9; --vsoft:rgba(139,92,246,.11); --ok:#15803d; --amber:#b45309; --bad:#b91c1c; }
  .ab-root .sub { color:var(--dim); max-width:780px; line-height:1.55; margin:0 }
  .ab-steps { display:flex; gap:2px; border-bottom:1px solid var(--line2); margin:18px 0 18px; flex-wrap:wrap; }
  .ab-step { padding:9px 14px; color:var(--faint); font-weight:600; border:0; border-bottom:2px solid transparent; margin-bottom:-1px; background:none; font:inherit; font-size:14px; cursor:pointer; }
  .ab-step b { display:inline-block; width:20px; height:20px; border-radius:50%; border:1px solid var(--line2); text-align:center; font-size:11.5px; line-height:18px; margin-right:6px; }
  .ab-step.on { color:var(--vtext); border-bottom-color:var(--violet); } .ab-step.on b { border-color:var(--violet); }
  .ab-step.done { color:var(--dim); } .ab-step.done b { background:var(--violet); border-color:var(--violet); color:#fff; }
  .ab-step[disabled] { cursor:default; opacity:.6; }
  .ab-row { display:grid; grid-template-columns:200px minmax(0,1fr) auto; gap:20px; padding:14px 0; border-bottom:1px solid var(--line); align-items:start; }
  .ab-row > .l { font-weight:600; } .ab-row > .l small { display:block; color:var(--faint); font-weight:400; font-size:12.5px; margin-top:2px; }
  .ab-in { background-color:transparent; border:1px solid var(--line2); border-radius:7px; padding:7px 10px; font:inherit; font-size:13.5px; color:inherit; width:100%; }
  .ab-root select.ab-in { background-repeat:no-repeat; background-position:right 8px center; background-size:14px; padding-right:30px; }
  .ab-btn { background:var(--violet); color:#fff; border:0; border-radius:7px; padding:8px 15px; font:inherit; font-size:13.5px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
  .ab-btn.g { background:transparent; color:var(--txt); border:1px solid var(--line2); }
  .ab-btn[disabled] { opacity:.5; cursor:default; }
  .ab-dim { color:var(--dim); } .ab-faint { color:var(--faint); font-size:12.5px; }
  .ab-note { background:var(--vsoft); border-radius:8px; padding:10px 14px; font-size:13px; color:var(--dim); margin-bottom:12px; line-height:1.55; }
  .ab-note b { color:var(--txt); }
  .ab-chip { display:inline-block; white-space:nowrap; font-size:11.5px; font-weight:600; padding:1px 8px; border-radius:99px; background:var(--vsoft); color:var(--vtext); }
  .ab-chip.ok { background:rgba(74,222,128,.12); color:var(--ok); } .ab-chip.warn { background:rgba(251,191,36,.12); color:var(--amber); } .ab-chip.bad { background:rgba(248,113,113,.12); color:var(--bad); }
  .ab-tbl { width:100%; border-collapse:collapse; font-size:13.5px; }
  .ab-tbl th { font-size:11.5px; font-weight:500; color:var(--faint); text-align:left; padding:9px 10px 7px 0; border-bottom:1px solid var(--line); }
  .ab-tbl td { padding:10px 10px 10px 0; border-bottom:1px solid var(--line); vertical-align:top; }
  .ab-feed div { font-size:12.5px; color:var(--dim); padding:2px 0; }
  .ab-bar { height:4px; border-radius:99px; background:var(--line2); overflow:hidden; margin:6px 0 8px; max-width:420px; }
  .ab-bar i { display:block; height:100%; width:30%; background:var(--violet); animation:abInd 1.4s ease-in-out infinite; }
  @keyframes abInd { 0% { margin-left:-30% } 100% { margin-left:100% } }
  .ab-prods { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:20px; margin-top:8px; }
  .ab-prods img, .ab-prods .ph { width:100%; height:130px; object-fit:contain; background:#fff; border-radius:8px; display:block; margin-bottom:8px; }
  .ab-fchip { border:1px solid var(--line2); border-radius:99px; padding:3px 11px; font-size:12.5px; color:var(--dim); cursor:pointer; background:none; font:inherit; font-size:12.5px; }
  .ab-err { color:var(--bad); font-size:12.5px; margin-top:6px; }
  .ab-nav { display:flex; justify-content:space-between; margin-top:20px; }
</style>

@php
  $p = $this->probe();
  $status = $p['status'] ?? null;
  $ready = $status === 'done';
  $feed = in_array($p['platform'] ?? '', ['shopify', 'woocommerce'], true);
  $running = in_array($status, ['queued', 'running'], true);
@endphp

<div class="ab-root">
  {{-- a poll element of its own, keyed to the check: an attribute added to an element already on the page never starts polling --}}
  @if ($running)<div wire:key="ab-poll-{{ $token }}" wire:poll.1500ms="refreshProbe"></div>@endif
  <div class="ab-faint" style="margin-bottom:4px"><a href="{{ \App\Filament\Pages\BrandSuppliers::getUrl() }}" style="color:var(--vtext)">Brand suppliers</a> › Add a brand</div>
  <p class="sub">Give Intake the brand's website. It works out how the site is built, samples a few products and shows you what it can read before anything is saved. The brand starts off for every shop.</p>

  <div class="ab-steps">
    @foreach ([1 => 'Website', 2 => 'What Intake found', 3 => 'Fill the gaps', 4 => 'Create'] as $n => $t)
      <button type="button" class="ab-step {{ $step === $n ? 'on' : ($step > $n ? 'done' : '') }}" wire:click="goTo({{ $n }})" @disabled($n > 1 && ! ($ready && $feed))><b>{{ $n }}</b>{{ $t }}</button>
    @endforeach
  </div>

  {{-- 1 --}}
  @if ($step === 1)
    <div class="ab-row">
      <div class="l">Brand's website</div>
      <div>
        <input type="text" class="ab-in" wire:model="url" placeholder="https://www.brand.com" wire:keydown.enter="look">
        @error('url')<div class="ab-err">{{ $message }}</div>@enderror
        <div class="ab-faint" style="margin-top:6px">The public site its riders and dealers use. Intake reads it politely, a few requests with pauses, the way the catalog read does.</div>
      </div>
      <div><button type="button" class="ab-btn" wire:click="look" wire:loading.attr="disabled" @disabled($running)>{{ $p ? 'Look again' : 'Look at the site' }}</button></div>
    </div>
    @if ($p)
      <div class="ab-row">
        <div class="l">Looking<small>{{ $running ? 'live' : ($ready ? 'done' : 'stopped') }}</small></div>
        <div>
          @if ($running)<div class="ab-bar"><i></i></div>@endif
          <div class="ab-feed">
            @forelse ($p['steps'] ?? [] as $s)
              <div>{!! $s['tone'] === 'warn' ? '<span style="color:var(--amber)">!</span>' : '✓' !!} {{ $s['text'] }}</div>
            @empty
              <div>Waiting for a free worker…</div>
            @endforelse
          </div>
          @if ($status === 'failed')<div class="ab-err">Couldn't finish: {{ $p['error'] ?? 'unknown error' }}</div>@endif
          @if ($status === 'unsupported' && ! empty($p['hidden']))
            <div class="ab-note" style="margin-top:10px"><b>This Shopify store hides its products</b> from every route Intake can read without a login. Nothing was saved. If the brand can turn its product list back on, or gives you a product file, it can be added then.</div>
          @elseif ($status === 'unsupported')
            <div class="ab-note" style="margin-top:10px"><b>This site has no public product feed</b> ({{ ($p['platform'] ?? '') === 'bigcommerce' ? 'BigCommerce' : 'a custom-built site' }}).
              Intake can't add it from here yet: reading a site like this, by pointing at the parts of its pages, is the next build. Nothing was saved.</div>
          @endif
        </div>
        <div></div>
      </div>
      @if ($ready && $feed)
        <div class="ab-row" style="border-bottom:0">
          <div class="l">Brand name</div>
          <div><input type="text" class="ab-in" style="max-width:320px" wire:model="name">
            @error('name')<div class="ab-err">{{ $message }}</div>@enderror
            <div class="ab-faint" style="margin-top:6px">Filled in from the site. It becomes the brand on every product.</div></div>
          <div><button type="button" class="ab-btn" wire:click="goTo(2)">See what Intake found</button></div>
        </div>
      @endif
    @endif
  @endif

  {{-- 2 --}}
  @if ($step === 2 && $ready)
    <div class="ab-note"><b>Nothing is saved yet.</b> This is what Intake read from {{ count($p['samples'] ?? []) }} sample products on {{ parse_url($p['base'], PHP_URL_HOST) }}, a {{ $p['platform'] === 'shopify' ? 'Shopify' : 'WooCommerce' }} store{{ ! empty($p['total']) ? ' with ' . number_format($p['total']) . ' products' : '' }}.
      Green: found on every sample. Amber: on some. Red: not found.</div>
    <table class="ab-tbl">
      <tr><th style="width:170px">Field</th><th style="width:120px">Found</th><th>Example</th></tr>
      @foreach ($p['fields'] ?? [] as $f)
        <tr>
          <td><b>{{ $f['label'] }}</b></td>
          <td><span class="ab-chip {{ $f['found'] === $f['of'] ? 'ok' : ($f['found'] > 0 ? 'warn' : 'bad') }}">{{ $f['found'] }} of {{ $f['of'] }}</span></td>
          <td class="ab-dim">{{ $f['example'] !== '' ? \Illuminate\Support\Str::limit($f['example'], 120) : '—' }}</td>
        </tr>
      @endforeach
    </table>
    <div style="margin-top:18px;font-weight:600">Sample products as Intake would store them</div>
    <div class="ab-prods">
      @foreach (array_slice($p['samples'] ?? [], 0, 3) as $s)
        <div>
          @if ($s['image'])<img src="{{ $s['image'] }}" alt="" loading="lazy">@else<div class="ph"></div>@endif
          <div style="font-weight:600">{{ $name }} {{ $s['title'] }}</div>
          <div class="ab-faint">{{ $s['variants'] }} {{ $s['variants'] === 1 ? 'variant' : 'variants' }}@if ($s['sizes']) · {{ $s['sizes'] }} sizes @endif @if ($s['colors']) · {{ $s['colors'] }} colors @endif @if ($s['price']) · ${{ $s['price'] }} @endif @if ($s['type']) · {{ $s['type'] }} @endif</div>
        </div>
      @endforeach
    </div>
    <div class="ab-nav"><button type="button" class="ab-btn g" wire:click="goTo(1)">Back</button><button type="button" class="ab-btn" wire:click="goTo(3)">Next: fill the gaps</button></div>
  @endif

  {{-- 3 --}}
  @if ($step === 3 && $ready)
    @php $opts = array_keys($p['options'] ?? []); $f = $p['fields'] ?? []; @endphp
    <div class="ab-row">
      <div class="l">Size and color<small>which of the store's options they are</small></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:520px">
        <label class="ab-faint">Size option
          <select class="ab-in" wire:model.live="sizeOption"><option value="">None</option>@foreach ($opts as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach</select></label>
        <label class="ab-faint">Color option
          <select class="ab-in" wire:model.live="colorOption"><option value="">None</option>@foreach ($opts as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach</select></label>
        @unless ($opts)<div class="ab-faint" style="grid-column:1/-1">The sampled products have no options; each is one item.</div>@endunless
      </div>
      <div></div>
    </div>
    <div class="ab-row">
      <div class="l">Kinds<small>the category under the product type</small></div>
      <div>
        <textarea class="ab-in" rows="5" style="max-width:420px" wire:model="kindsText" placeholder="One tag per line, most specific first"></textarea>
        <div class="ab-faint" style="margin-top:4px">The tags that say what kind of product it is ("Mountain Full Suspension" above "Mountain"). A product gets the first one it carries. Leave it empty to use the product type only; you can change it later on the brand's Catalog read tab.</div>
        @if (! empty($p['tags']))
          <div class="ab-faint" style="margin-top:8px">Tags on the sampled products (click to add):</div>
          <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:4px">
            @foreach ($p['tags'] as $t => $n)<button type="button" class="ab-fchip" wire:click="addKind(@js((string) $t))">{{ $t }} <span style="opacity:.6">{{ $n }}</span></button>@endforeach
          </div>
        @endif
      </div>
      <div></div>
    </div>
    <div class="ab-row">
      <div class="l">Part numbers<small>{{ ($f['sku']['found'] ?? 0) }} of {{ ($f['sku']['of'] ?? 0) }} samples have them</small></div>
      <div class="ab-dim">
        @if (($f['sku']['found'] ?? 0) === ($f['sku']['of'] ?? 0))
          Every sampled variant has a part number (the store's SKU), so the brand's stock file joins on it.
        @else
          Some variants have no part number on the store. Intake gives those an id made from the product and variant, so they still import, but a stock file can't match them until the store lists a part number. Reading it from the store's cart is a later build.
        @endif
      </div>
      <div></div>
    </div>
    <div class="ab-row" style="border-bottom:0">
      <div class="l">Model year</div>
      <div class="ab-dim">{{ ($f['year']['found'] ?? 0) ? 'Taken from tags like "2027" where the store has them; the stock file fills the rest when loaded.' : 'Not on the store; the stock file fills it when loaded.' }}</div>
      <div></div>
    </div>
    <div class="ab-nav"><button type="button" class="ab-btn g" wire:click="goTo(2)">Back</button><button type="button" class="ab-btn" wire:click="goTo(4)">Next: create</button></div>
  @endif

  {{-- 4 --}}
  @if ($step === 4 && $ready)
    @php $kinds = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $kindsText)))); @endphp
    <div class="ab-row"><div class="l">Brand</div><div><b>{{ $name }}</b> <span class="ab-dim">· {{ parse_url($p['base'], PHP_URL_HOST) }} · {{ $p['platform'] === 'shopify' ? 'Shopify' : 'WooCommerce' }} · code {{ $this->suggestedCode() }}</span></div><div></div></div>
    <div class="ab-row"><div class="l">Catalog</div><div class="ab-dim">{{ ! empty($p['total']) ? number_format($p['total']) . ' products' : 'Every product in the feed' }}, read {{ match ($p['route'] ?? null) { 'collection' => 'from its all-products collection, a page at a time', 'sitemap' => 'one product at a time from its product sitemap (allow about ' . max(1, (int) ceil((($p['total'] ?? 100) * 7) / 60)) . ' minutes)', default => 'from the store\'s product feed, a page at a time' } }}. Size from "{{ $sizeOption ?: 'none' }}", color from "{{ $colorOption ?: 'none' }}"{{ $kinds ? ', kinds: ' . implode(', ', $kinds) : '' }}.</div><div></div></div>
    <div class="ab-row"><div class="l">Field map</div><div class="ab-dim">Started from what the reader writes: part number, name, description, brand, category, size, color, photos, retail price, model year. Edit it any time in Field Mapping.</div><div></div></div>
    <div class="ab-row"><div class="l">Cost and stock</div><div class="ab-dim">From the brand's stock file, loaded on its Stock file tab once you have one.</div><div></div></div>
    <div class="ab-row"><div class="l">Shops</div><div class="ab-dim">Off for every shop. Switch dealers on from the brand's Shops tab.</div><div></div></div>
    <div class="ab-row" style="border-bottom:0"><div class="l">After creating</div><div><label><input type="checkbox" wire:model="startRead"> Start the first full read straight away</label></div><div></div></div>
    <div class="ab-nav"><button type="button" class="ab-btn g" wire:click="goTo(3)">Back</button>
      <button type="button" class="ab-btn" wire:click="create" wire:loading.attr="disabled" wire:target="create"><span wire:loading.remove wire:target="create">Create {{ $name }}</span><span wire:loading wire:target="create">Creating…</span></button></div>
  @endif
</div>
</x-filament-panels::page>
