@php
    $configured = $this->configured();
    $rows   = $this->visibleResults();
    $newN   = count(array_filter($this->results, fn ($r) => $r['status'] === 'new'));
    $mtd    = $this->monthToDateCents();
    $budget = \App\Models\SalesSetting::placesBudgetCents();
    $card   = 'border-radius:12px;padding:14px 16px;border:1px solid rgba(127,127,127,.22)';
    $label  = 'font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;opacity:.55;margin-bottom:8px';
    $muted  = 'font-size:12px;opacity:.65';
    $badge  = 'display:inline-block;border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;';
    $input  = 'rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm';
@endphp

<x-filament-panels::page>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
  .sfs-grid{display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start}
  @media (max-width:1100px){.sfs-grid{grid-template-columns:1fr}}
  .sfs-chip{display:inline-flex;align-items:center;border:1px solid rgba(127,127,127,.35);border-radius:999px;padding:3px 10px;font-size:12px;cursor:pointer;margin:0 6px 6px 0;opacity:.8}
  .sfs-chip.on{border-color:rgb(139,92,246);background:rgba(139,92,246,.16);opacity:1}
  .sfs-map{height:440px;border-radius:12px;border:1px solid rgba(127,127,127,.22);overflow:hidden;background:#101114}
  .sfs-row{display:grid;grid-template-columns:22px 1fr auto;gap:10px;padding:10px 12px;border-top:1px solid rgba(127,127,127,.15);align-items:start;cursor:pointer}
  .sfs-row:hover{background:rgba(127,127,127,.08)}
  .sfs-row.sel{background:rgba(139,92,246,.10)}
  .sfs-btn{border:1px solid rgba(127,127,127,.35);border-radius:6px;padding:6px 11px;font-size:13px;font-weight:500;cursor:pointer}
  .sfs-btn.p{background:rgb(139,92,246);border-color:rgb(139,92,246);color:#fff}
  .sfs-btn:disabled{opacity:.45;cursor:not-allowed}
  .leaflet-container{font:inherit}
  .sfs-legend{border-radius:12px;padding:10px 14px;border:1px solid rgba(139,92,246,.35);background:rgba(139,92,246,.08);font-size:13px;margin-bottom:16px}
</style>

<div class="sfs-legend">
  <b>What this page does.</b> Each search calls Google Places, then checks every result against prospects and tenants before you see it.
  Adding a shop creates the prospect with phone, hours, rating and coordinates already filled, and — when a territory rule matches — assigns it to that rep.
  Searches cost money (about {{ $this->money(\App\Models\SalesSetting::placesCostCents()) }} per page of 20); month to date <b>{{ $this->money($mtd) }}</b> of a {{ $this->money($budget) }} budget.
  Nothing on this page changes an existing prospect.
</div>

{{-- the website pass runs on the server; this says what it is doing --}}
@php $ss = $this->siteScanStats(); $ssDone = $ss['with_site'] ? (int) floor(($ss['with_site'] - $ss['left']) * 100 / $ss['with_site']) : 0; @endphp
<div style="{{ $card }};margin-bottom:16px">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <span style="font-size:13px;font-weight:600">Website pass</span>
    <span style="{{ $badge }}{{ $ss['paused'] ? 'background:rgba(251,191,36,.18);color:#fbbf24' : ($ss['left'] ? 'background:rgba(139,92,246,.18);color:#a78bfa' : 'background:rgba(74,222,128,.15);color:#4ade80') }}">{{ $ss['paused'] ? 'Paused' : ($ss['left'] ? 'Running' : 'Up to date') }}</span>
    <span style="{{ $muted }}">{{ number_format($ss['with_site'] - $ss['left']) }} of {{ number_format($ss['with_site']) }} sites read ({{ $ssDone }}%)@if($ss['last']) · last {{ \Illuminate\Support\Carbon::parse($ss['last'])->diffForHumans() }}@endif</span>
    @php $ssSpeed = \App\Models\SalesSetting::get('site_scan_speed', 'normal'); @endphp
    <select class="{{ $input }}" style="margin-left:auto;width:auto;padding-top:5px;padding-bottom:5px" wire:change="setScanSpeed($event.target.value)" title="How many shops each five-minute run reads">
      @foreach(['normal' => 'Normal · 80 per run', 'fast' => 'Fast · 250 per run', 'max' => 'Max · 600 per run'] as $sv => $sl)
        <option value="{{ $sv }}" @selected($ssSpeed === $sv)>{{ $sl }}</option>
      @endforeach
    </select>
    <button type="button" class="sfs-btn" wire:click="toggleSiteScan">{{ $ss['paused'] ? 'Resume' : 'Pause' }}</button>
  </div>
  <div style="height:4px;border-radius:2px;background:rgba(127,127,127,.18);margin:10px 0 8px;overflow:hidden"><div style="height:100%;width:{{ $ssDone }}%;background:rgb(139,92,246)"></div></div>
  <div style="{{ $muted }};display:flex;gap:16px;flex-wrap:wrap">
    <span><b>{{ number_format($ss['emails']) }}</b> with an email</span>
    <span><b>{{ number_format($ss['socials']) }}</b> with socials</span>
    <span><b>{{ number_format($ss['brands']) }}</b> with brands</span>
    <span>{{ number_format(($ss['by']['not_shop_site'] ?? 0) + ($ss['by']['name_mismatch'] ?? 0)) }} links that aren't the shop's own site</span>
    <span>{{ number_format($ss['by']['unreachable'] ?? 0) }} sites that didn't answer</span>
  </div>
  <div style="{{ $muted }};margin-top:8px">
    Runs on the server every five minutes, {{ \App\Console\Commands\ScanProspectSites::SPEEDS[$ssSpeed] ?? 80 }} shops at a time (Speed), and reads each prospect's own website: home page plus a contact or about page.
    It fills email, phone and owner only when they are empty, and adds socials and the brands the site mentions. Nothing a person typed is changed.
    Brand dealer pages, booking tools and sites that don't mention the shop's name are skipped and counted above. Signed-up shops are left alone.
  </div>
</div>

{{-- Duplicate shops — review and merge --}}
@php $dupN = $this->duplicateCount(); @endphp
<div style="{{ $card }};margin-bottom:16px">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <span style="font-size:13px;font-weight:600">Duplicate shops</span>
    <span style="{{ $badge }}{{ $dupN ? 'background:rgba(251,191,36,.18);color:#fbbf24' : 'background:rgba(74,222,128,.15);color:#4ade80' }}">{{ $dupN ? number_format($dupN) . ' ' . ($dupN === 1 ? 'set' : 'sets') : 'None' }}</span>
    <span style="{{ $muted }}">Same phone number, or the same website at the same ZIP.</span>
    @if($dupN)
      <span style="margin-left:auto;display:flex;gap:8px;align-items:center">
        @if($confirmMergeAll)
          <span style="{{ $muted }}">Merge all {{ number_format($dupN) }} sets? This can't be undone.</span>
          <button type="button" class="sfs-btn p" wire:click="mergeAll" wire:loading.attr="disabled">Merge all</button>
          <button type="button" class="sfs-btn" wire:click="$set('confirmMergeAll', false)">Cancel</button>
        @else
          <button type="button" class="sfs-btn" wire:click="$set('confirmMergeAll', true)">Merge all</button>
          <button type="button" class="sfs-btn" wire:click="$toggle('showDupes')">{{ $showDupes ? 'Hide' : 'Review' }}</button>
        @endif
      </span>
    @endif
  </div>
  <div style="{{ $muted }};margin-top:8px">
    Merging keeps the shop someone has worked (signed up, stage, calls, rep, notes; marked <b>keep</b>), fills its empty fields from the others, moves their timeline onto it and deletes the rest.
    A chain's locations share a website but not a phone or ZIP, so they are not listed. "Not the same shop" hides a set for good.
  </div>
  @if($showDupes && $dupN)
    <div style="margin-top:10px">
      @foreach($this->duplicateSets() as $set)
        <div style="border-top:1px solid rgba(127,127,127,.15);padding:10px 0" wire:key="dup-{{ md5(implode(',', $set['ids'])) }}">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
            <span style="{{ $muted }}">{{ $set['reason'] }}</span>
            <span style="margin-left:auto;display:flex;gap:6px">
              <button type="button" class="sfs-btn" wire:click="ignoreSet('{{ implode(',', $set['ids']) }}')">Not the same shop</button>
              <button type="button" class="sfs-btn p" wire:click="mergeSet('{{ implode(',', $set['ids']) }}')" wire:loading.attr="disabled">Merge</button>
            </span>
          </div>
          <table style="width:100%;font-size:12.5px;border-collapse:collapse">
            @foreach($set['rows'] as $r)
              <tr>
                <td style="padding:3px 6px;width:42px">@if($r->id === $set['keeper'])<span style="{{ $badge }}background:rgba(139,92,246,.18);color:#a78bfa">keep</span>@endif</td>
                <td style="padding:3px 6px;font-weight:{{ $r->id === $set['keeper'] ? 600 : 400 }}">{{ $r->shop }}</td>
                <td style="padding:3px 6px;{{ $muted }}">{{ $r->city }}{{ $r->state ? ', ' . $r->state : '' }} {{ $r->postcode }}</td>
                <td style="padding:3px 6px;{{ $muted }}">{{ $r->phone }}</td>
                <td style="padding:3px 6px;{{ $muted }}">{{ $r->website ? parse_url((str_contains($r->website, '://') ? '' : 'https://') . $r->website, PHP_URL_HOST) : '' }}</td>
                <td style="padding:3px 6px;{{ $muted }}">{{ \App\Models\SalesProspect::STAGES[$r->stage] ?? $r->stage }}</td>
              </tr>
            @endforeach
          </table>
        </div>
      @endforeach
      @if($dupN > 100)<div style="{{ $muted }};padding-top:8px">Showing the first 100 sets. Merge these and the rest appear.</div>@endif
    </div>
  @endif
</div>

@unless($configured)
  {{-- the key and budget live on Sales setup › Google Places now. --}}
  <div style="{{ $card }};margin-bottom:16px;border-color:rgba(251,191,36,.5)">
    Searching needs a Google Places key. Add it on <a href="{{ \App\Filament\Pages\SalesPlacesSettings::getUrl() }}" style="color:#a78bfa">Sales setup › Google Places</a>.
  </div>
@endunless

<div class="sfs-grid">
  <div>
    <div style="{{ $card }}">
      <div style="{{ $label }}">Industry</div>
      <div>
        @foreach($this->industries() as $code => $ind)
          <span class="sfs-chip {{ $industry === $code ? 'on' : '' }}" wire:click="$set('industry', '{{ $code }}')">{{ $ind['label'] }}</span>
        @endforeach
      </div>
      @if($industry === 'custom')
        <input type="text" wire:model="customQuery" class="{{ $input }}" style="width:100%;margin-top:6px" placeholder='e.g. "paddle shop", "sewing machine repair"'>
      @endif

      <div style="{{ $label }};margin-top:16px">Where</div>
      <input type="text" wire:model="place" wire:keydown.enter="search" class="{{ $input }}" style="width:100%" placeholder="City or address">
      @error('place')<div style="color:#f87171;font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
      <div style="{{ $muted }};margin-top:10px">Radius <b>{{ $radius }}</b> mi <span style="opacity:.6">(Places caps a search at about 30)</span></div>
      <input type="range" min="5" max="30" step="5" wire:model.live="radius" style="width:100%">

      <div style="{{ $label }};margin-top:16px">On add</div>
      <label style="display:flex;gap:8px;font-size:13px;align-items:center"><input type="checkbox" wire:model="autoAssign" class="rounded"> Assign to the territory's rep</label>
      <label style="display:flex;gap:8px;font-size:13px;align-items:center;margin-top:6px"><input type="checkbox" wire:model.live="hideKnown" class="rounded"> Hide shops already known</label>

      <div style="{{ $muted }};margin-top:12px">This search: about <b>{{ $this->money($this->estimateCents()) }}</b>.</div>
      <button class="sfs-btn p" wire:click="search" wire:loading.attr="disabled" style="width:100%;margin-top:10px" @disabled(! $configured)>
        <span wire:loading.remove wire:target="search">Search Places</span>
        <span wire:loading wire:target="search">Searching…</span>
      </button>
      @if($error)<div style="color:#f87171;font-size:13px;margin-top:8px">{{ $error }}</div>@endif
    </div>

    {{-- not a <details>: Livewire's morph dropped the user's `open` attribute on every re-render --}}
    <div style="{{ $card }};margin-top:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" wire:click="$toggle('showLoader')">
        <span style="font-size:13px;font-weight:600">Load a shop list (free base layer)</span>
        <span style="{{ $muted }}">{{ $showLoader ? 'hide' : 'show' }}</span>
      </div>
      <div style="{{ $showLoader ? '' : 'display:none' }}">
      <div style="{{ $muted }};margin:8px 0">A CSV like the Overture export: shop_name, address, city, state_code, postcode, website, verified_workstand, and latitude/longitude if you have them. Rows already present (same name, city and address) are skipped. Nothing is written until you confirm, and a batch can be undone below — only rows nobody has worked are removed.</div>
      {{-- progress + errors are shown here; before this the page went quiet when the upload was rejected --}}
      <div x-data="{ up: false, pct: 0, err: '', stage: '' }"
           x-on:change="if ($event.target.type === 'file' && $event.target.files.length) { stage = 'Picked ' + $event.target.files[0].name + ' (' + Math.round($event.target.files[0].size / 1024) + ' KB) — waiting for the upload to start…'; err = ''; setTimeout(() => { if (!up && pct === 0 && !err) { err = 'The browser never started the upload. Reload the page and try again; if it repeats, tell Josh the file name and size.'; } }, 4000); }"
           x-on:livewire-upload-start="up = true; pct = 0; err = ''; stage = 'Uploading…'"
           x-on:livewire-upload-progress="pct = $event.detail.progress; stage = 'Uploading… ' + pct + '%'"
           x-on:livewire-upload-finish="up = false; pct = 100; stage = 'Uploaded — reading the columns…'"
           x-on:livewire-upload-error="up = false; err = 'The upload was rejected before it reached Intake — usually the server body-size limit (413). ' + ($event.detail && $event.detail.message ? $event.detail.message : '')">
        <input type="file" wire:model="shopList" accept=".csv,text/csv" class="{{ $input }}" style="width:100%">
        <div x-show="up" style="margin-top:6px;height:6px;border-radius:3px;background:rgba(127,127,127,.2);overflow:hidden"><div :style="'height:100%;background:rgb(139,92,246);width:' + pct + '%'"></div></div>
        <div x-show="err" x-text="err" style="color:#f87171;font-size:12px;margin-top:6px"></div>
        <div x-show="stage && !err" x-text="stage" style="{{ $muted }};margin-top:6px"></div>
      </div>
      @if($uploadError)<div style="color:#f87171;font-size:12px;margin-top:6px">{{ $uploadError }}</div>@endif
      @if($uploadHeaders)
        <div style="{{ $label }};margin-top:14px">Map columns · file read</div>
        <div style="{{ $muted }};margin-bottom:6px">{{ count($uploadHeaders) }} columns found. Guessed where the names were obvious; fix anything wrong. Shop name and state are required.</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 10px">
          @foreach($this->fields() as $field => [$flabel, $req])
            <div wire:key="map-{{ $field }}">
              <div style="{{ $muted }}">{{ $flabel }}{{ $req ? ' *' : '' }}</div>
              <select class="{{ $input }}" style="width:100%" wire:model.live="columnMap.{{ $field }}">
                <option value="">— not in this file —</option>
                @foreach($uploadHeaders as $h)<option value="{{ $h }}">{{ $h }}@if(isset($uploadSample[0][$h]) && $uploadSample[0][$h] !== '') · e.g. {{ Str::limit($uploadSample[0][$h], 28) }}@endif</option>@endforeach
              </select>
            </div>
          @endforeach
        </div>
        @if($this->mapProblem())<div style="color:#fbbf24;font-size:12px;margin-top:6px">{{ $this->mapProblem() }}</div>@endif
      @endif
      @error('shopList')<div style="color:#f87171;font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
      <div style="font-size:13px;margin-top:10px">Industry for these shops</div>
      <select wire:model="uploadIndustry" class="{{ $input }}" style="width:100%;margin-top:4px">
        <option value="">None</option>
        @foreach($this->uploadIndustries() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
      </select>
      <label style="display:flex;gap:8px;font-size:13px;align-items:center;margin-top:8px"><input type="checkbox" wire:model="uploadAssign" class="rounded"> Assign to territory reps on load</label>
      <div style="display:flex;gap:8px;margin-top:10px">
        <button class="sfs-btn" wire:click="previewUpload" wire:loading.attr="disabled" @disabled(! $uploadHeaders || $this->mapProblem())><span wire:loading.remove wire:target="previewUpload">Preview</span><span wire:loading wire:target="previewUpload">Reading…</span></button>
        @if($uploadPreview && ! $uploadPreview['error'])
          <button class="sfs-btn p" wire:click="importUpload" wire:loading.attr="disabled"><span wire:loading.remove wire:target="importUpload">Load {{ number_format($uploadPreview['inserted']) }} shops</span><span wire:loading wire:target="importUpload">Loading… this can take a minute</span></button>
        @endif
      </div>
      @if($uploadPreview && ! $uploadPreview['error'])
        <div style="font-size:13px;margin-top:10px"><b>{{ number_format($uploadPreview['inserted']) }} new</b> · {{ number_format($uploadPreview['matched']) }} already present · {{ number_format($uploadPreview['blank']) }} skipped (no name or state) · {{ number_format($uploadPreview['with_coords']) }} with coordinates · {{ number_format($uploadPreview['total']) }} rows read</div>
        @if($uploadPreview['with_coords'] === 0)<div style="{{ $muted }};margin-top:4px;color:#fbbf24">No coordinates in this file — these shops won't appear on maps until a Pull details. Re-export with latitude/longitude if you can.</div>@endif
      @endif
      @if($uploadResult && ! $uploadResult['error'])
        <div style="font-size:13px;margin-top:10px">Loaded <b>{{ number_format($uploadResult['inserted']) }}</b> as batch <code>{{ $uploadResult['batch'] }}</code>.</div>
      @endif
      @php $batches = $this->batches(); @endphp
      @if($batches)
        <div style="{{ $label }};margin-top:14px">Loaded batches</div>
        @foreach($batches as $b)
          <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;padding:5px 0;border-top:1px solid rgba(127,127,127,.12)" wire:key="b-{{ $b['batch'] }}">
            <span><code>{{ $b['batch'] }}</code> · {{ number_format($b['total']) }} shops · {{ number_format($b['untouched']) }} untouched</span>
            @if($confirmUndo === $b['batch'])
              <span style="display:flex;gap:6px;align-items:center"><span style="{{ $muted }}">Remove {{ number_format($b['untouched']) }} untouched?</span><button class="sfs-btn sm" style="border-color:#f87171;color:#f87171" wire:click="undoBatch('{{ $b['batch'] }}')" wire:loading.attr="disabled">Remove</button><button class="sfs-btn sm ghost" wire:click="$set('confirmUndo', '')">Cancel</button></span>
            @else
              <button class="sfs-btn sm" wire:click="$set('confirmUndo', '{{ $b['batch'] }}')" @disabled(! $b['untouched'])>Undo</button>
            @endif
          </div>
        @endforeach
      @endif
      </div>
    </div>

    <div style="{{ $card }};margin-top:16px">
      <div style="{{ $label }}">Recent searches</div>
      @forelse($this->recentSearches() as $s)
        <div style="font-size:12px;padding:4px 0;border-top:1px solid rgba(127,127,127,.12)">
          <b>{{ ['bicycle_store' => 'Bike shops', 'ski_store' => 'Ski & snowboard', 'gym' => 'Fitness studios', 'motorcycle_repair' => 'Motorcycle', 'outdoor' => 'Outdoor specialty', 'paddle' => 'Paddle & kayak'][$s->industry] ?? $s->industry }}</b> near {{ $s->place }} · {{ $s->radius_miles }} mi
          <span style="opacity:.6">· {{ $s->found }} found, {{ $s->new_count }} new · {{ $this->money($s->cost_cents) }} · {{ $s->created_at->diffForHumans() }}</span>
        </div>
      @empty
        <div style="{{ $muted }}">None yet.</div>
      @endforelse
    </div>

    <div style="{{ $muted }};margin-top:12px">Key and monthly budget: <a href="{{ \App\Filament\Pages\SalesPlacesSettings::getUrl() }}" style="color:#a78bfa">Sales setup › Google Places</a></div>
  </div>

  <div>
    <div wire:ignore class="sfs-map" id="sfs-map"></div>
    {{-- not @json: that directive splits on commas --}}
    <script type="application/json" id="sfs-data">{!! json_encode(['rows' => $this->results, 'selected' => $this->selected, 'located' => $this->located], JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>

    <div style="{{ $card }};margin-top:16px;padding:0">
      <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;flex-wrap:wrap;gap:8px">
        <div>
          <div style="font-weight:600;font-size:14px">Results</div>
          <div style="{{ $muted }}">
            @if($this->results)
              {{ count($this->results) }} found near {{ $this->located }} · <b>{{ $newN }} new</b> · {{ count($this->results) - $newN }} already known · {{ $this->money($this->lastCostCents) }}
            @else
              Run a search to see shops here.
            @endif
          </div>
        </div>
        <div style="display:flex;gap:8px">
          <button class="sfs-btn" wire:click="selectAllNew" @disabled(! $newN)>Select all new</button>
          <button class="sfs-btn p" wire:click="addSelected" wire:loading.attr="disabled" @disabled(! count($selected))>
            {{ count($selected) ? 'Add ' . count($selected) . ' to prospects' : 'Add selected to prospects' }}
          </button>
        </div>
      </div>

      @forelse($rows as $r)
        @php $isSel = in_array($r['place_id'], $selected, true); @endphp
        <div class="sfs-row {{ $isSel ? 'sel' : '' }}" wire:key="r-{{ $r['place_id'] }}" wire:click="toggle('{{ $r['place_id'] }}')">
          <input type="checkbox" class="rounded" style="margin-top:3px" @checked($isSel) @disabled($r['status'] !== 'new') onclick="event.preventDefault()">
          <div>
            <div>
              <b>{{ $r['shop'] }}</b>
              @if($r['status'] === 'new')<span style="{{ $badge }}background:rgba(52,211,153,.15);color:#34d399">New</span>
              @elseif($r['status'] === 'tenant')<span style="{{ $badge }}background:rgba(190,242,100,.18);color:#BEF264">Tenant</span>
              @else<span style="{{ $badge }}background:rgba(154,154,163,.15);color:#9a9aa3">Prospect · {{ \App\Models\SalesProspect::STAGES[$r['stage']] ?? $r['stage'] }}</span>@endif
              @if(($r['gstatus'] ?? null) && $r['gstatus'] !== 'OPERATIONAL')<span style="{{ $badge }}background:rgba(248,113,113,.18);color:#f87171">Closed</span>@endif
            </div>
            <div style="{{ $muted }}">
              {{ $r['city'] }}{{ $r['state'] ? ', ' . $r['state'] : '' }}
              @if(isset($r['miles'])) · {{ $r['miles'] }} mi @endif
              @if($r['rating']) · ★ {{ $r['rating'] }} ({{ $r['rating_count'] }}) @endif
              · {{ $r['website'] ? parse_url($r['website'], PHP_URL_HOST) : 'no website' }}
              @if($r['phone']) · {{ $r['phone'] }} @endif
              @if($r['territory']) · would go to <b>{{ $r['territory_owner'] }}</b> @else · <span style="color:#fbbf24">no territory rule matches</span> @endif
            </div>
          </div>
          <div>
            @if($r['status'] === 'new')
              <button class="sfs-btn" wire:click.stop="addOne('{{ $r['place_id'] }}')">Add</button>
            @elseif($r['prospect_id'])
              <a class="sfs-btn" style="text-decoration:none" href="{{ \App\Filament\Resources\SalesProspectResource::getUrl('edit', ['record' => $r['prospect_id']]) }}" onclick="event.stopPropagation()">Open</a>
            @endif
          </div>
        </div>
      @empty
        @if($this->results)
          <div style="padding:24px;text-align:center;{{ $muted }}">Everything here is already known. Widen the radius or try the next town.</div>
        @endif
      @endforelse
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  // map is wire:ignore'd; it redraws from #sfs-data whenever the component says so.
  (function () {
    var map = null, layer = null;
    function draw() {
      var el = document.getElementById('sfs-map'); if (!el || !window.L) return;
      var data; try { data = JSON.parse(document.getElementById('sfs-data').textContent); } catch (e) { return; }
      if (!map) {
        map = L.map(el, { zoomControl: true }).setView([47.66, -117.43], 9);
         var esri = 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/'; L.tileLayer(esri + 'World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16, attribution: 'Tiles &copy; Esri &mdash; Esri, HERE, Garmin, &copy; OpenStreetMap contributors' }).addTo(map); L.tileLayer(esri + 'World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, maxNativeZoom: 16 }).addTo(map);
      }
      if (layer) { layer.remove(); }
      layer = L.layerGroup().addTo(map);
      var pts = [];
      (data.rows || []).forEach(function (r) {
        if (r.lat == null) return;
        var col = r.status === 'new' ? '#34d399' : (r.status === 'tenant' ? '#BEF264' : '#7c7c86');
        var sel = (data.selected || []).indexOf(r.place_id) >= 0;
        var m = L.circleMarker([r.lat, r.lng], { radius: sel ? 9 : 7, color: sel ? '#fff' : '#0b0b0b', weight: sel ? 2.5 : 1.5, fillColor: col, fillOpacity: 1 });
        m.bindTooltip(r.shop + (r.rating ? ' · ★ ' + r.rating : ''), { direction: 'top' });
        if (r.status === 'new') { m.on('click', function () { var c = el.closest('[wire\\:id]'); if (c && window.Livewire) { Livewire.find(c.getAttribute('wire:id')).call('toggle', r.place_id); } }); }
        m.addTo(layer); pts.push([r.lat, r.lng]);
      });
      if (pts.length) { map.fitBounds(pts, { padding: [24, 24], maxZoom: 13 }); }
    }
    document.addEventListener('DOMContentLoaded', draw);
    document.addEventListener('livewire:initialized', function () { draw(); });
    window.addEventListener('places-updated', function () { setTimeout(draw, 30); });
    if (document.readyState !== 'loading') { setTimeout(draw, 0); }
  })();
</script>
</x-filament-panels::page>
