@extends('layouts.tenant.app')
@php $pageTitle = 'Fleet'; @endphp

{{-- MARKER-RENTAL-FLEET-STUDIO: the fleet in the Studio design. Same data and
     the same save endpoints as before (per-field PATCH, bulk add units,
     checklists, categories); what changed is how it looks and where editing
     happens: models, categories and checklists edit in a side drawer, and
     Save only writes what you changed. --}}

@section('mobile-fab', 'new-rental')
@include('layouts.tenant._rental-styles')

@section('content')
@php
  $qs = fn (array $over = []) => array_filter(array_merge(
      ['q' => $search ?: null, 'category' => $filterCategory ?: null, 'status' => $filterStatus ?: null], $over),
      fn ($v) => $v !== null && $v !== '');
  $filtering = $search !== '' || $filterCategory || $filterStatus;
  $statusLabels = ['all' => 'All', 'available' => 'Free', 'out' => 'Out', 'reserved' => 'Reserved', 'maintenance' => 'Maintenance'];
  $reservedIdf = ['size', 'serial', 'tag', 'serial / tag', 'status', 'condition', 'identifier'];
  $tones = ['--sd-t1', '--sd-t2', '--sd-t3', '--sd-t4'];
  $thumbStyle = function ($seed) use ($tones) {
      $t = crc32((string) $seed) % 4;
      return 'background:linear-gradient(135deg,color-mix(in srgb,var(' . $tones[$t] . ') 70%,var(--ia-surface)),color-mix(in srgb,var(' . $tones[($t + 1) % 4] . ') 35%,var(--ia-surface)));color:var(--sd-ink)';
  };
  $initials = fn ($n) => mb_strtoupper(mb_substr(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), preg_split('/\s+/', trim((string) $n)))), 0, 2));
@endphp

<div class="sd rf" id="sd">
  <header class="sd-head" id="sdHead">
    <div class="sd-head-l">
      <h1 class="sd-hi">Fleet</h1>
      <p class="sd-sub">{{ $unitTotal }} {{ $unitTotal === 1 ? 'unit' : 'units' }} across {{ $modelTotal }} {{ $modelTotal === 1 ? 'model' : 'models' }} · {{ $statusCounts['available'] }} free right now</p>
    </div>
    <div class="sd-head-r">
      <form method="GET" action="{{ route('tenant.rentals.fleet') }}" class="rf-sform" role="search">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" name="q" value="{{ $search }}" placeholder="Search models, tags, sizes" aria-label="Search the fleet">
        @if($filterCategory)<input type="hidden" name="category" value="{{ $filterCategory }}">@endif
        @if($filterStatus)<input type="hidden" name="status" value="{{ $filterStatus }}">@endif
      </form>
      <button type="button" class="ia-btn ia-btn--sm sd-hide-m" data-open="d-checklists">Checklists</button>
      <button type="button" class="ia-btn ia-btn--sm ia-btn--primary" data-open="d-newmodel">+ Add model</button>
    </div>
  </header>

  @include('layouts.tenant._rental-nav', ['active' => 'fleet'])

  @if(session('flash'))<div class="ia-flash ia-flash--success" style="margin-bottom:16px">{{ session('flash') }}</div>@endif
  @if($errors->any())<div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>@endif

  @if($allCategories->isEmpty())
    <div class="sd-empty" style="padding:40px 0;text-align:center">
      <b style="display:block;font-size:15px;color:var(--ia-text);margin-bottom:6px">Your fleet starts here</b>
      Add a category, then a model, then units. A simple shop just adds one model per item.
      <div class="sd-empty-acts" style="justify-content:center"><button type="button" class="ia-btn ia-btn--primary" data-open="d-newcat">Add a category</button></div>
    </div>
  @else
  {{-- control line: what's shown, right above it --}}
  <div class="rf-ctl sd-in">
    <div class="sd-seg" role="tablist" aria-label="Status">
      @foreach($statusLabels as $sk => $sl)
        <a href="{{ route('tenant.rentals.fleet', $qs(['status' => $sk === 'all' ? null : $sk])) }}" class="{{ ($filterStatus ?: 'all') === $sk ? 'on' : '' }}" role="tab">{{ $sl }} <em>{{ $statusCounts[$sk] }}</em></a>
      @endforeach
    </div>
    <div class="rf-pills">
      <a class="rf-pill {{ $filterCategory ? '' : 'on' }}" href="{{ route('tenant.rentals.fleet', $qs(['category' => null])) }}">All categories</a>
      @foreach($allCategories as $c)
        <a class="rf-pill {{ $filterCategory === $c->id ? 'on' : '' }}" href="{{ route('tenant.rentals.fleet', $qs(['category' => $c->id])) }}">{{ $c->name }}</a>
      @endforeach
      <button type="button" class="rf-pill add" data-open="d-newcat">+ Category</button>
      <button type="button" class="rf-pill add rf-only-m" data-open="d-checklists">Checklists</button>
    </div>
  </div>

  @php $shownCats = 0; @endphp
  @foreach($categories as $cat)
    @php
      $r = $rollups[$cat->id] ?? ['total'=>0,'available'=>0,'out'=>0,'reserved'=>0,'maintenance'=>0,'models'=>0];
      $t = max(1, $r['total']);
      $fr = $r['available']; $ou = $r['out'] + $r['reserved']; $mt = $r['maintenance'];
      $tag = $r['total'] === 0 ? ['none', 'No units'] : ($fr / $t > 0.4 ? ['ok', 'Plenty free'] : ($fr > 0 ? ['tight', 'Running low'] : ['full', 'All out']));
      $catModels = collect($modelsByCat[$cat->id] ?? [])
          ->filter(fn ($m) => ! $filterStatus || $m->view_units->isNotEmpty());
      if ($filterStatus && $catModels->isEmpty()) continue;
      $shownCats++;
      $catOpen = $loop->first || $filtering;
    @endphp
    <section class="rf-cat sd-in {{ $catOpen ? 'open' : '' }}" style="--d:{{ min($loop->index, 6) * 80 }}ms" data-cat="{{ $cat->id }}">
      <div class="rf-cat-h" data-ctoggle>
        <div class="rf-disc"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></div>
        <div>
          <h2 data-cat-name>{{ $cat->name }}</h2>
          <small><span data-cat-axis>{{ $cat->size_axis ? 'Sized by ' . mb_strtolower($cat->size_axis) . ' · ' : '' }}</span>{{ $r['models'] }} {{ $r['models'] === 1 ? 'model' : 'models' }} · {{ $r['total'] }} {{ $r['total'] === 1 ? 'unit' : 'units' }}</small>
        </div>
        <div class="rd-bar"><i class="fr" style="width:{{ $fr / $t * 100 }}%"></i><i class="ou" style="width:{{ $ou / $t * 100 }}%"></i><i class="mt" style="width:{{ $mt / $t * 100 }}%"></i></div>
        <div class="nums" style="font:500 11.5px var(--ia-font-mono);color:var(--ia-text-muted)">{{ $fr }} free · {{ $ou }} out</div>
        <div class="rd-tag {{ $tag[0] }}">{{ $tag[1] }}</div>
        <button type="button" class="rf-ib" data-open="d-cat-{{ $cat->id }}" title="Edit category" aria-label="Edit {{ $cat->name }}">✎</button>
      </div>

      <div class="rf-cat-b">
        @forelse($catModels as $model)
          @php
            $mIdents = array_values(array_filter($model->identifiers ?? [], fn ($n) => ! in_array(mb_strtolower($n), $reservedIdf, true)));
            $mPhotos = array_values(array_filter($model->photos ?? []));
            if ($model->image_url && ! in_array($model->image_url, $mPhotos, true)) array_unshift($mPhotos, $model->image_url);
            $mainPhoto = $model->image_url ?: ($mPhotos[0] ?? null);
            $live = $model->view_units->count();
            $uGrid = '34px 104px 64px ' . str_repeat('minmax(70px,110px) ', count($mIdents)) . 'minmax(0,1fr) 128px 74px 70px 20px';
            $mOpen = $filtering && ($filterStatus || $search !== '');
          @endphp
          <div class="rf-model {{ $mOpen ? 'open' : '' }}" data-model="{{ $model->id }}" data-idents="{{ json_encode($mIdents) }}">
            <div class="rf-model-h" data-mtoggle>
              <div class="rf-disc"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></div>
              <div class="rf-thumb" style="{{ $mainPhoto ? "background-image:url('" . e($mainPhoto) . "')" : $thumbStyle($model->id) }}">{{ $mainPhoto ? '' : $initials($model->name) }}</div>
              <div class="rf-mname"><b data-model-name>{{ $model->name }}</b><small>{{ $model->subtitle ?: $cat->name }}{{ $model->conditionTemplate ? ' · ' . $model->conditionTemplate->name : '' }}</small></div>
              <div class="rf-rates">
                @if($model->hourly_rate_cents)<span class="rf-rate"><b>{{ format_money($model->hourly_rate_cents) }}</b>/hr</span>@endif
                @if($model->daily_rate_cents)<span class="rf-rate"><b>{{ format_money($model->daily_rate_cents) }}</b>/day</span>@endif
                @if($model->weekend_rate_cents)<span class="rf-rate"><b>{{ format_money($model->weekend_rate_cents) }}</b>/wknd</span>@endif
                @if($model->seasonal_rate_cents)<span class="rf-rate"><b>{{ format_money($model->seasonal_rate_cents) }}</b>/season</span>@endif
                <span class="rf-rate"><b>{{ format_money($model->deposit_cents) }}</b> deposit</span>
              </div>
              <div class="rf-mcount" data-unit-count>{{ $live }} {{ $live === 1 ? 'unit' : 'units' }}</div>
              <span class="rf-st {{ $model->avail_count ? 'av' : 'off' }}" data-avail-count>{{ $model->avail_count }} free</span>
              <button type="button" class="ia-btn ia-btn--sm" data-open="d-model-{{ $model->id }}">Edit</button>
            </div>

            <div class="rf-units">
              <div class="rf-uh" data-uhead style="grid-template-columns:{{ $uGrid }}"><span></span><span>Tag</span><span>Size</span>@foreach($mIdents as $in)<span>{{ $in }}</span>@endforeach<span>Condition</span><span>Status</span><span>Last out</span><span>30-day use</span><span></span></div>
              @foreach($model->view_units as $u)
                @php $m = $unitMeta[$u->id] ?? ['last' => null, 'util' => null, 'flags' => 0, 'photos' => 0]; @endphp
                <a class="rf-u" data-unit="{{ $u->id }}" data-uvals="{{ json_encode($u->identifier_values ?? []) }}" style="grid-template-columns:{{ $uGrid }}" href="{{ route('tenant.rentals.fleet.units.show', $u->id) }}">
                  <span data-stop><button type="button" class="rf-upick {{ $u->photo_url ? 'has' : '' }}" data-upick="{{ $model->id }}" data-photo="{{ $u->photo_url }}" style="{{ $u->photo_url ? "background-image:url('" . e($u->photo_url) . "')" : '' }}" title="Photo" aria-label="Unit photo">{{ $u->photo_url ? '' : '▣' }}</button></span>
                  <span class="rf-mono">{{ $u->identifier ?: '—' }}</span>
                  <span class="c-size">{{ $u->size ?: '—' }}@if(! $u->available_for_rent) <small>· off</small>@endif</span>
                  @foreach($mIdents as $in)
                    <span class="rf-idc" data-stop><input class="rf-idv" data-ui="{{ $in }}" value="{{ ($u->identifier_values ?? [])[$in] ?? '' }}" placeholder="—" aria-label="{{ $in }}"></span>
                  @endforeach
                  <span class="rf-cond">
                    @if($m['flags'] > 0)<span class="rf-flag">⚑ {{ $m['flags'] }} {{ $m['flags'] === 1 ? 'flag' : 'flags' }}</span> @endif
                    @if($m['photos'] > 0)<span class="rf-dim">{{ $m['photos'] }} {{ $m['photos'] === 1 ? 'photo' : 'photos' }}</span>@endif
                    @if($m['flags'] === 0 && $m['photos'] === 0)<span class="rf-dim">No incidents</span>@endif
                  </span>
                  <span data-stop>
                    @if($u->derived_status === 'out')<span class="rf-st out">Out</span>
                    @elseif($u->derived_status === 'reserved')<span class="rf-st res">Reserved</span>
                    @else
                      <select data-uf="status" aria-label="Status">
                        @foreach(['available' => 'Available', 'maintenance' => 'Maintenance', 'retired' => 'Retired'] as $sk => $sv)
                          <option value="{{ $sk }}" {{ $u->status === $sk ? 'selected' : '' }}>{{ $sv }}</option>
                        @endforeach
                      </select>
                    @endif
                  </span>
                  <span class="rf-dim c-last" style="font-size:11.5px">{{ $m['last'] ? tlocal_date($m['last'], 'M j') : 'never' }}</span>
                  <span class="rf-use c-use" style="--w:{{ (int) ($m['util'] ?? 0) }}%"><i></i>{{ $m['util'] !== null ? $m['util'] . '%' : '—' }}</span>
                  <span class="rf-go">›</span>
                </a>
              @endforeach

              @unless($filterStatus)
                <button type="button" class="ia-btn ia-btn--sm rf-addu-open" data-addu="{{ $model->id }}">+ Add units</button>
                <form class="rf-addu" method="POST" action="{{ route('tenant.rentals.fleet.units.bulk') }}" data-add-form hidden>
                  @csrf
                  <input type="hidden" name="model_id" value="{{ $model->id }}">
                  <input type="hidden" name="photo_url" value="">
                  <label><span class="rf-lbl">Photo</span><button type="button" class="rf-upick" data-apick="{{ $model->id }}" title="Pick a photo for this batch" aria-label="Pick a photo for this batch">▣</button></label>
                  <label><span class="rf-lbl">Qty</span><input class="ia-input" type="number" name="count" value="1" min="1" max="200" style="width:64px"></label>
                  <label><span class="rf-lbl">Tag prefix</span><input class="ia-input rf-mono" type="text" name="tag_prefix" placeholder="#SK-" style="width:96px"></label>
                  <label><span class="rf-lbl">Start #</span><input class="ia-input" type="number" name="start_number" value="{{ $model->units->count() + 1 }}" min="0" style="width:76px"></label>
                  <label><span class="rf-lbl">Size</span><input class="ia-input" type="text" name="size" placeholder="optional" style="width:110px"></label>
                  @foreach($mIdents as $in)
                    <label class="rf-ident-fg"><span class="rf-lbl">{{ $in }}</span><input class="ia-input" type="text" name="ident[{{ $in }}]" placeholder="optional" style="width:110px"></label>
                  @endforeach
                  <button type="submit" class="ia-btn ia-btn--sm ia-btn--primary" style="height:32px" data-add-submit>Add units</button>
                  <button type="button" class="ia-btn ia-btn--sm ia-btn--ghost" data-addx>Cancel</button>
                </form>
              @endunless
            </div>
          </div>
        @empty
          <div class="sd-empty">No models in this category yet.
            <div class="sd-empty-acts"><button type="button" class="ia-btn ia-btn--sm ia-btn--primary" data-open="d-newmodel" data-cat-pre="{{ $cat->id }}">Add a model</button></div>
          </div>
        @endforelse
      </div>
    </section>
  @endforeach

  @if($shownCats === 0)
    <div class="sd-empty">Nothing matches.
      <div class="sd-empty-acts"><a href="{{ route('tenant.rentals.fleet') }}" class="ia-btn ia-btn--sm">Show everything</a></div>
    </div>
  @else
    <div class="rd-key" style="margin-top:14px"><span><i class="fr"></i>Free</span><span><i class="ou"></i>Out or reserved</span><span><i class="mt"></i>Maintenance</span></div>
  @endif

  @if($pageCount > 1)
    <nav class="rf-pager" aria-label="Pages">
      @for($p = 1; $p <= $pageCount; $p++)
        <a href="{{ route('tenant.rentals.fleet', $qs(['page' => $p])) }}" class="{{ $p === $page ? 'cur' : '' }}">{{ $p }}</a>
      @endfor
    </nav>
  @endif

  <p class="sd-legend"><b>What changes on its own:</b> Out and Reserved come from bookings and update by themselves — you can't set them here. You set Available, Maintenance or Retired from a unit's status. 30-day use is hours out over the last 30 days. Rates, photos and identifiers only change when you press Save model.</p>
  @endif
</div>

{{-- ============ drawers (outside .sd so the page's motion can't pin them) ============ --}}
<div class="rf-drawers" id="rfDrawers">
  <div class="rf-scrim" id="rfScrim" hidden></div>

  {{-- one drawer per model: edits save on "Save model", per changed field --}}
  @foreach($categories as $cat)
    @foreach($modelsByCat[$cat->id] ?? [] as $model)
      @php
        $mIdents = array_values(array_filter($model->identifiers ?? [], fn ($n) => ! in_array(mb_strtolower($n), $reservedIdf, true)));
        $mPhotos = array_values(array_filter($model->photos ?? []));
        if ($model->image_url && ! in_array($model->image_url, $mPhotos, true)) array_unshift($mPhotos, $model->image_url);
        $rate = fn ($c) => $c ? number_format($c / 100, 2, '.', '') : '';
      @endphp
      <aside class="rf-drawer" id="d-model-{{ $model->id }}" data-model-drawer="{{ $model->id }}" aria-hidden="true" role="dialog" aria-label="Edit {{ $model->name }}">
        <div class="rf-dh"><div><h3>Edit model</h3><span>{{ $model->units->count() }} {{ $model->units->count() === 1 ? 'unit' : 'units' }} · {{ $cat->name }}</span></div><button type="button" class="rf-x" data-close aria-label="Close">×</button></div>
        <div class="rf-db">
          <div class="rf-dsec">Photos<small>★ is the main photo on your public pages · up to 8</small></div>
          <div data-pset-wrap data-model-photos="{{ $model->id }}">
            <input type="hidden" data-mf="photos" value="{{ json_encode($mPhotos) }}">
            <input type="hidden" data-mf="image_url" data-pset-main value="{{ $model->image_url }}">
            <div class="rf-photos" data-pset></div>
            <input type="file" accept="image/jpeg,image/png,image/webp,image/avif" data-pset-file style="display:none">
          </div>

          <div class="rf-dsec">Details</div>
          <div class="rf-fg">
            <label class="rf-c4"><span class="rf-lbl">Model name</span><input class="ia-input" data-mf="name" value="{{ $model->name }}" placeholder="What customers see"></label>
            <label class="rf-c2"><span class="rf-lbl">Category</span>
              <select class="ia-input" data-mf="category_id">@foreach($allCategories as $c)<option value="{{ $c->id }}" {{ $model->category_id === $c->id ? 'selected' : '' }}>{{ $c->name }}</option>@endforeach</select>
            </label>
            <label class="rf-c2"><span class="rf-lbl">Subtitle</span><input class="ia-input" data-mf="subtitle" value="{{ $model->subtitle }}" placeholder="all-mountain, junior…"></label>
          </div>

          <div class="rf-dsec">Rates<small>Leave any blank you don't offer</small></div>
          <div class="rf-fg">
            <label><span class="rf-lbl">Hourly</span><div class="rf-money"><input class="ia-input" data-mf="hourly_rate" value="{{ $rate($model->hourly_rate_cents) }}" placeholder="—" inputmode="decimal"></div></label>
            <label><span class="rf-lbl">Daily</span><div class="rf-money"><input class="ia-input" data-mf="daily_rate" value="{{ $rate($model->daily_rate_cents) }}" placeholder="—" inputmode="decimal"></div></label>
            <label><span class="rf-lbl">Weekend</span><div class="rf-money"><input class="ia-input" data-mf="weekend_rate" value="{{ $rate($model->weekend_rate_cents) }}" placeholder="—" inputmode="decimal"></div></label>
            <label><span class="rf-lbl">Deposit</span><div class="rf-money"><input class="ia-input" data-mf="deposit" value="{{ number_format($model->deposit_cents / 100, 2, '.', '') }}" inputmode="decimal"></div></label>
            <label class="rf-c2"><span class="rf-lbl">Entire season / lease @unless(tenant()->leasing_available)<span style="text-transform:none;letter-spacing:0">(Scale plan)</span>@endunless</span><div class="rf-money"><input class="ia-input" data-mf="seasonal_rate" value="{{ $rate($model->seasonal_rate_cents) }}" placeholder="—" inputmode="decimal" {{ tenant()->leasing_available ? '' : 'disabled' }}></div></label>
            <label class="rf-c2"><span class="rf-lbl">Condition checklist</span>
              <select class="ia-input" data-mf="condition_template_id"><option value="">None</option>@foreach($conditionTemplates as $ct)<option value="{{ $ct->id }}" {{ $model->condition_template_id === $ct->id ? 'selected' : '' }}>{{ $ct->name }}</option>@endforeach</select>
            </label>
          </div>

          <div class="rf-dsec">Identifiers<small>Extra fields on every unit of this model · staff only · 3 max</small></div>
          <div data-idf-wrap>
            <input type="hidden" data-mf="identifiers" value="{{ json_encode($mIdents) }}">
            <div class="rf-chips" data-idf-chips></div>
            <p class="rf-err" data-idf-err hidden></p>
          </div>
          <p class="rf-hint">Each identifier becomes a column in the unit list and a field when you add units. Size, tag and status are built in.</p>
        </div>
        <div class="rf-df">
          <button type="button" class="ia-btn ia-btn--ghost rf-danger" data-archive-model="{{ $model->id }}">Archive model</button>
          <span class="sp"></span>
          <span class="rf-saved" data-saved>✓ Saved</span>
          <button type="button" class="ia-btn" data-close>Close</button>
          <button type="button" class="ia-btn ia-btn--primary" data-msave>Save model</button>
        </div>
      </aside>
    @endforeach

    {{-- category drawer --}}
    <aside class="rf-drawer" id="d-cat-{{ $cat->id }}" data-cat-drawer="{{ $cat->id }}" aria-hidden="true" role="dialog" aria-label="Edit {{ $cat->name }}">
      @php $realModels = (int) ($catModelCounts[$cat->id] ?? 0); @endphp
      <div class="rf-dh"><div><h3>Edit category</h3><span>{{ $realModels }} {{ $realModels === 1 ? 'model' : 'models' }}</span></div><button type="button" class="rf-x" data-close aria-label="Close">×</button></div>
      <div class="rf-db">
        <div class="rf-fg">
          <label class="rf-c4"><span class="rf-lbl">Name</span><input class="ia-input" data-cf="name" value="{{ $cat->name }}"></label>
          <label class="rf-c4"><span class="rf-lbl">Size axis (optional) @include('tenant.rentals._size_axis_help')</span><input class="ia-input" data-cf="size_axis" value="{{ $cat->size_axis }}" placeholder="Length (cm), Frame size, Boot size…"></label>
        </div>
        <p class="rf-hint">How this category is sized: shown as the Size label on its units and on your public pages. Leave blank if size doesn't matter.</p>
        <div class="rf-dsec">Delete<small>{{ $realModels ? 'Move or archive its models first' : 'Nothing in it yet' }}</small></div>
        <button type="button" class="ia-btn ia-btn--sm" data-cat-del="{{ $cat->id }}" {{ $realModels ? 'disabled style=opacity:.5' : '' }}>Delete category</button>
      </div>
      <div class="rf-df">
        <span class="sp"></span>
        <span class="rf-saved" data-saved>✓ Saved</span>
        <button type="button" class="ia-btn" data-close>Close</button>
        <button type="button" class="ia-btn ia-btn--primary" data-csave>Save category</button>
      </div>
    </aside>
  @endforeach

  {{-- add a category --}}
  <aside class="rf-drawer" id="d-newcat" aria-hidden="true" role="dialog" aria-label="Add a category">
    <form method="POST" action="{{ route('tenant.rentals.fleet.categories.store') }}" style="display:contents">
      @csrf
      <div class="rf-dh"><div><h3>Add a category</h3><span>Group models the way customers shop</span></div><button type="button" class="rf-x" data-close aria-label="Close">×</button></div>
      <div class="rf-db">
        <div class="rf-fg">
          <label class="rf-c4"><span class="rf-lbl">Name</span><input class="ia-input" name="name" placeholder="e.g. Skis" required maxlength="120"></label>
          <label class="rf-c4"><span class="rf-lbl">Size axis (optional) @include('tenant.rentals._size_axis_help')</span><input class="ia-input" name="size_axis" placeholder="Length (cm), Frame size, Boot size…" maxlength="40"></label>
        </div>
      </div>
      <div class="rf-df"><span class="sp"></span><button type="button" class="ia-btn" data-close>Cancel</button><button type="submit" class="ia-btn ia-btn--primary">Add category</button></div>
    </form>
  </aside>

  {{-- add a model --}}
  <aside class="rf-drawer" id="d-newmodel" aria-hidden="true" role="dialog" aria-label="Add a model">
    <form method="POST" action="{{ route('tenant.rentals.fleet.models.store') }}" style="display:contents">
      @csrf
      <div class="rf-dh"><div><h3>Add a model</h3><span>What a thing is and what it costs</span></div><button type="button" class="rf-x" data-close aria-label="Close">×</button></div>
      <div class="rf-db">
        <div class="rf-dsec">Details</div>
        <div class="rf-fg">
          <label class="rf-c4"><span class="rf-lbl">Model name</span><input class="ia-input" name="name" placeholder="e.g. Rossignol Experience 80" required></label>
          <label class="rf-c2"><span class="rf-lbl">Category</span>
            <select class="ia-input" name="category_id" id="nmCat" required><option value="">Choose…</option>@foreach($allCategories as $c)<option value="{{ $c->id }}" {{ $filterCategory === $c->id ? 'selected' : '' }}>{{ $c->name }}</option>@endforeach</select>
          </label>
          <label class="rf-c2"><span class="rf-lbl">Subtitle</span><input class="ia-input" name="subtitle" placeholder="all-mountain"></label>
        </div>
        <div class="rf-dsec">Rates<small>Leave any blank you don't offer</small></div>
        <div class="rf-fg">
          <label><span class="rf-lbl">Hourly</span><div class="rf-money"><input class="ia-input" name="hourly_rate" placeholder="—" inputmode="decimal"></div></label>
          <label><span class="rf-lbl">Daily</span><div class="rf-money"><input class="ia-input" name="daily_rate" placeholder="—" inputmode="decimal"></div></label>
          <label><span class="rf-lbl">Weekend</span><div class="rf-money"><input class="ia-input" name="weekend_rate" placeholder="—" inputmode="decimal"></div></label>
          <label><span class="rf-lbl">Deposit</span><div class="rf-money"><input class="ia-input" name="deposit" placeholder="0.00" inputmode="decimal"></div></label>
          @if(tenant()->leasing_available)
            <label class="rf-c2"><span class="rf-lbl">Entire season / lease</span><div class="rf-money"><input class="ia-input" name="seasonal_rate" placeholder="—" inputmode="decimal"></div></label>
          @endif
        </div>
        <div class="rf-dsec">First unit<small>Optional — add more from the model afterwards</small></div>
        <div class="rf-fg">
          <label class="rf-c2"><span class="rf-lbl">Tag</span><input class="ia-input rf-mono" name="first_unit_identifier" placeholder="#SK-001"></label>
          <label class="rf-c2"><span class="rf-lbl">Size</span><input class="ia-input" name="first_unit_size" placeholder="optional"></label>
        </div>
        <p class="rf-hint">Photos and identifiers are added from the model once it exists.</p>
      </div>
      <div class="rf-df"><span class="sp"></span><button type="button" class="ia-btn" data-close>Cancel</button><button type="submit" class="ia-btn ia-btn--primary">Add model</button></div>
    </form>
  </aside>

  {{-- condition checklists --}}
  <aside class="rf-drawer" id="d-checklists" aria-hidden="true" role="dialog" aria-label="Condition checklists">
    <div class="rf-dh"><div><h3>Condition checklists</h3><span>Used in check-out and return. In-flight rentals keep the checklist they started with.</span></div><button type="button" class="rf-x" data-close aria-label="Close">×</button></div>
    <div class="rf-db">
      @foreach($conditionTemplates as $ct)
        @php $ctItems = collect($ct->items)->pluck('label'); @endphp
        <div class="rf-ct" data-ct="{{ $ct->id }}">
          <div class="rf-ct-h"><input class="ia-input" style="font-weight:600" value="{{ $ct->name }}" data-ctf="name" aria-label="Checklist name"></div>
          <textarea class="ia-input" rows="{{ max(3, $ctItems->count() + 1) }}" data-ctf="items" spellcheck="false" aria-label="Checklist items">{{ $ctItems->implode("\n") }}</textarea>
          <div class="rf-ct-acts"><span class="rf-ct-note">One item per line</span><button type="button" class="ia-btn ia-btn--sm ia-btn--ghost rf-danger" data-ct-del="{{ $ct->id }}">Delete</button><button type="button" class="ia-btn ia-btn--sm ia-btn--primary" data-ct-save="{{ $ct->id }}">Save</button></div>
        </div>
      @endforeach
      <form method="POST" action="{{ route('tenant.rentals.fleet.ct.store') }}">
        @csrf
        <div class="rf-dsec">Add a checklist</div>
        <div class="rf-fg">
          <label class="rf-c4"><span class="rf-lbl">Name</span><input class="ia-input" name="name" placeholder="e.g. Ski checklist" required></label>
          <label class="rf-c4"><span class="rf-lbl">Items, one per line</span><textarea class="ia-input" name="items" rows="3" required placeholder="Edges — no major gouges&#10;Bindings — DIN intact&#10;Bases — no core shots"></textarea></label>
        </div>
        <button type="submit" class="ia-btn ia-btn--sm ia-btn--primary" style="margin-top:12px">Add checklist</button>
      </form>
    </div>
  </aside>
</div>

<div class="rf-toast" id="rfToast" hidden></div>
@endsection

@push('scripts')
<script>
(function () {
  'use strict';
  var csrf = '{{ csrf_token() }}';
  var URL_MODEL = '{{ url('admin/rentals/fleet/models') }}/';
  var URL_UNIT  = '{{ url('admin/rentals/fleet/units') }}/';
  var URL_CAT   = '{{ url('admin/rentals/fleet/categories') }}/';
  var URL_CT    = '{{ url('admin/rentals/fleet/condition-templates') }}/';
  var URL_FEED  = '{{ route('tenant.media.feed') }}';
  var URL_UP    = '{{ route('tenant.uploads.store') }}';
  var RESERVED  = ['size', 'serial', 'tag', 'serial / tag', 'status', 'condition', 'identifier'];

  function $(s, e) { return (e || document).querySelector(s); }
  function $$(s, e) { return Array.prototype.slice.call((e || document).querySelectorAll(s)); }
  function parseList(v) { try { var l = JSON.parse(v || '[]'); return Array.isArray(l) ? l : []; } catch (e) { return []; } }

  /* ---------------------------------------------------------- motion + header */
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduce) document.documentElement.classList.add('sd-motion');
  function reveal() {
    var line = innerHeight * 0.9;
    $$('.sd-in:not(.is-in)').forEach(function (el) { if (el.getBoundingClientRect().top < line) el.classList.add('is-in'); });
  }
  reveal(); addEventListener('scroll', reveal, { passive: true }); addEventListener('resize', reveal);
  setTimeout(function () { $$('.sd-in').forEach(function (el) { el.classList.add('is-in'); }); }, 2500);
  var head = $('#sdHead');
  function frost() { if (head) head.classList.toggle('frost', scrollY > 24 && innerWidth > 1023); }
  addEventListener('scroll', frost, { passive: true }); frost();

  /* ---------------------------------------------------------- toast + saving */
  var tT, toastEl = $('#rfToast');
  function toast(msg, err, keep) {
    toastEl.textContent = msg; toastEl.classList.toggle('err', !!err); toastEl.hidden = false;
    clearTimeout(tT); if (!keep) tT = setTimeout(function () { toastEl.hidden = true; }, err ? 3600 : 1800);
  }
  // the one save rail: POST + X-HTTP-Method-Override PATCH, {field, value}
  function patch(url, field, value, ok, fail) {
    toast('Saving…', false, true);
    fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-HTTP-Method-Override': 'PATCH' }, body: JSON.stringify({ field: field, value: value }) })
      .then(function (r) { if (!r.ok && r.status !== 422) throw new Error('http ' + r.status); return r.json(); })
      .then(function (j) {
        if (j && j.success === false) { toast(j.message || 'Could not save.', true); if (fail) fail(); return; }
        toast('Saved ✓'); if (ok) ok();
      })
      .catch(function () { toast("Couldn't save — check your connection and retry.", true); if (fail) fail(); });
  }
  function del(url, title, message, confirmText, failTitle) {
    IntakeConfirm.show({ title: title, message: message, confirmText: confirmText, danger: true }).then(function (ok) {
      if (!ok) return;
      fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-HTTP-Method-Override': 'DELETE' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.success) location.reload(); else IntakeConfirm.alert({ title: failTitle, message: j.message || 'Could not do that.' }); })
        .catch(function () { IntakeConfirm.alert({ title: failTitle, message: "Couldn't reach the server — check your connection." }); });
    });
  }

  /* ---------------------------------------------------------- drawers */
  var scrim = $('#rfScrim'), openD = null, lastFocus = null, pm = null;
  function openDrawer(id) {
    var d = document.getElementById(id); if (!d) return;
    if (openD && openD !== d) closeDrawer(true);
    lastFocus = document.activeElement; openD = d;
    scrim.hidden = false;
    requestAnimationFrame(function () { scrim.classList.add('on'); d.classList.add('on'); });
    d.setAttribute('aria-hidden', 'false');
    var body = $('.rf-db', d); if (body) body.scrollTop = 0;
    setTimeout(function () { var f = $('input:not([type=hidden]):not([disabled]),select,textarea', d); if (f && innerWidth > 900) f.focus({ preventScroll: true }); }, 340);
  }
  function closeDrawer(quick) {
    if (!openD) return;
    var d = openD; openD = null;
    d.classList.remove('on'); d.setAttribute('aria-hidden', 'true'); scrim.classList.remove('on');
    setTimeout(function () { if (!openD) scrim.hidden = true; }, quick ? 0 : 260);
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
  }
  scrim.addEventListener('click', function () { closeDrawer(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openD && !(pm && pm.classList.contains('open'))) closeDrawer(); });
  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-open]');
    if (o) {
      e.preventDefault(); e.stopPropagation();
      var pre = o.getAttribute('data-cat-pre'); if (pre) { var s = $('#nmCat'); if (s) s.value = pre; }
      openDrawer(o.getAttribute('data-open')); return;
    }
    if (e.target.closest('[data-close]')) closeDrawer();
  });

  /* ---------------------------------------------------------- expand / collapse */
  document.addEventListener('click', function (e) {
    if (e.target.closest('button, a, input, select, textarea, [data-stop]')) return;
    var mh = e.target.closest('[data-mtoggle]');
    if (mh) { mh.parentElement.classList.toggle('open'); return; }
    var ch = e.target.closest('[data-ctoggle]');
    if (ch) ch.parentElement.classList.toggle('open');
  });
  // the unit rows are links; their controls shouldn't navigate
  document.addEventListener('click', function (e) {
    var s = e.target.closest('[data-stop]'); if (s) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  /* ---------------------------------------------------------- units */
  function unitUrl(line) { return URL_UNIT + line.getAttribute('data-unit'); }
  function bindIdent(line) {
    $$('[data-ui]', line).forEach(function (el) {
      if (el.dataset.bound) return; el.dataset.bound = '1';
      el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); el.blur(); } });
      el.addEventListener('change', function () {
        var map = {};
        $$('[data-ui]', line).forEach(function (x) { if (x.value.trim() !== '') map[x.getAttribute('data-ui')] = x.value.trim(); });
        patch(unitUrl(line), 'identifier_values', JSON.stringify(map));
      });
    });
  }
  function bindUnit(line) {
    $$('[data-uf]', line).forEach(function (el) { el.addEventListener('change', function () { patch(unitUrl(line), el.getAttribute('data-uf'), el.value); }); });
    bindIdent(line);
  }
  $$('.rf-u').forEach(bindUnit);

  function gridFor(n) { return '34px 104px 64px ' + new Array(n + 1).join('minmax(70px,110px) ') + 'minmax(0,1fr) 128px 74px 70px 20px'; }
  // a model's identifiers changed: rebuild its unit columns and add-units fields in place
  function syncIdentCols(card, idents) {
    card.setAttribute('data-idents', JSON.stringify(idents));
    var grid = gridFor(idents.length), units = $('.rf-units', card);
    var hd = $('[data-uhead]', units);
    if (hd) {
      hd.style.gridTemplateColumns = grid; hd.textContent = '';
      ['', 'Tag', 'Size'].concat(idents, ['Condition', 'Status', 'Last out', '30-day use', '']).forEach(function (h) { var s = document.createElement('span'); s.textContent = h; hd.appendChild(s); });
    }
    $$('.rf-u', units).forEach(function (line) {
      var vals = {};
      try { vals = JSON.parse(line.getAttribute('data-uvals') || '{}') || {}; } catch (e) {}
      $$('[data-ui]', line).forEach(function (x) { vals[x.getAttribute('data-ui')] = x.value; });
      line.setAttribute('data-uvals', JSON.stringify(vals));
      $$('.rf-idc', line).forEach(function (c) { c.remove(); });
      line.style.gridTemplateColumns = grid;
      var after = line.children[2];
      idents.forEach(function (name) {
        var cell = document.createElement('span'); cell.className = 'rf-idc'; cell.setAttribute('data-stop', '');
        var i = document.createElement('input'); i.className = 'rf-idv'; i.setAttribute('data-ui', name); i.placeholder = '—'; i.setAttribute('aria-label', name);
        i.value = typeof vals[name] === 'string' ? vals[name] : '';
        cell.appendChild(i); after.insertAdjacentElement('afterend', cell); after = cell;
      });
      bindIdent(line);
    });
    var form = $('[data-add-form]', units);
    if (form) {
      $$('.rf-ident-fg', form).forEach(function (f) { f.remove(); });
      var submit = $('[data-add-submit]', form);
      idents.forEach(function (name) {
        var l = document.createElement('label'); l.className = 'rf-ident-fg';
        var s = document.createElement('span'); s.className = 'rf-lbl'; s.textContent = name;
        var i = document.createElement('input'); i.className = 'ia-input'; i.type = 'text'; i.name = 'ident[' + name + ']'; i.placeholder = 'optional'; i.style.width = '110px';
        l.appendChild(s); l.appendChild(i); form.insertBefore(l, submit);
      });
    }
  }

  /* ---------------------------------------------------------- model drawers */
  $$('[data-model-drawer]').forEach(function (d) {
    var id = d.getAttribute('data-model-drawer'), url = URL_MODEL + id, dirty = {};
    var saveBtn = $('[data-msave]', d), note = $('[data-saved]', d);
    $$('[data-mf]', d).forEach(function (el) {
      el.addEventListener('change', function () { dirty[el.getAttribute('data-mf')] = el; });
      if (el.tagName === 'INPUT' && el.type === 'text') el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); el.blur(); } });
    });
    d.__markDirty = function (el) { dirty[el.getAttribute('data-mf')] = el; };
    function flash(msg) { note.textContent = msg; note.classList.add('on'); setTimeout(function () { note.classList.remove('on'); }, 1800); }
    saveBtn.addEventListener('click', function () {
      var fields = Object.keys(dirty);
      if (!fields.length) { flash('Nothing to save'); return; }
      var nm = dirty.name; if (nm && !nm.value.trim()) { toast('Give the model a name first', true); return; }
      saveBtn.disabled = true;
      var i = 0, moved = false;
      function done(ok) { saveBtn.disabled = false; if (ok) { dirty = {}; flash('✓ Saved'); if (moved) location.reload(); } }
      (function next() {
        if (i >= fields.length) { done(true); return; }
        var f = fields[i++], el = dirty[f];
        patch(url, f, el.value, function () {
          var card = $('.rf-model[data-model="' + id + '"]');
          if (f === 'name' && card) { var t = $('[data-model-name]', card); if (t) t.textContent = el.value; }
          if (f === 'identifiers' && card) syncIdentCols(card, parseList(el.value));
          if (f === 'category_id') moved = true;
          next();
        }, function () { done(false); });
      })();
    });
    var arch = $('[data-archive-model]', d);
    if (arch) arch.addEventListener('click', function () { del(url, 'Archive this model?', 'Its units must already be archived.', 'Archive', 'Archive failed'); });
  });

  /* ---------------------------------------------------------- category drawers */
  $$('[data-cat-drawer]').forEach(function (d) {
    var id = d.getAttribute('data-cat-drawer'), url = URL_CAT + id, dirty = {};
    var note = $('[data-saved]', d);
    function flash(msg) { note.textContent = msg; note.classList.add('on'); setTimeout(function () { note.classList.remove('on'); }, 1800); }
    $$('[data-cf]', d).forEach(function (el) {
      el.addEventListener('change', function () { dirty[el.getAttribute('data-cf')] = el; });
      el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); el.blur(); } });
    });
    $('[data-csave]', d).addEventListener('click', function () {
      var fields = Object.keys(dirty);
      if (!fields.length) { flash('Nothing to save'); return; }
      var i = 0, sec = $('.rf-cat[data-cat="' + id + '"]');
      (function next() {
        if (i >= fields.length) { dirty = {}; flash('✓ Saved'); return; }
        var f = fields[i++], el = dirty[f];
        patch(url, f, el.value, function () {
          if (sec && f === 'name') $('[data-cat-name]', sec).textContent = el.value;
          if (sec && f === 'size_axis') $('[data-cat-axis]', sec).textContent = el.value ? 'Sized by ' + el.value.toLowerCase() + ' · ' : '';
          next();
        });
      })();
    });
    var cd = $('[data-cat-del]', d);
    if (cd) cd.addEventListener('click', function () { if (!cd.disabled) del(url, 'Delete this category?', 'It must be empty (no models) first.', 'Delete', 'Delete failed'); });
  });
  // size-axis "?" popovers
  document.addEventListener('click', function (e) {
    var dot = e.target.closest('.fl-help-dot');
    $$('[data-axis-help].open').forEach(function (o) { if (!dot || o !== dot.closest('[data-axis-help]')) o.classList.remove('open'); });
    if (dot) { e.preventDefault(); e.stopPropagation(); dot.closest('[data-axis-help]').classList.toggle('open'); }
  });

  /* ---------------------------------------------------------- checklists */
  $$('.rf-ct[data-ct]').forEach(function (row) {
    var id = row.getAttribute('data-ct');
    $('[data-ct-save]', row).addEventListener('click', function () {
      var name = $('[data-ctf="name"]', row), items = $('[data-ctf="items"]', row);
      if (!name.value.trim()) { toast('Give the checklist a name first', true); return; }
      patch(URL_CT + id, 'name', name.value, function () { patch(URL_CT + id, 'items', items.value); });
    });
    $('[data-ct-del]', row).addEventListener('click', function () {
      del(URL_CT + id, 'Delete this checklist?', 'Models using it fall back to no checklist; past condition checks are unaffected.', 'Delete', 'Delete failed');
    });
  });

  /* ---------------------------------------------------------- add units */
  $$('[data-addu]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.parentElement.querySelector('[data-add-form]'); f.hidden = false; b.hidden = true;
      var q = $('[name="count"]', f); if (q) q.focus();
    });
  });
  $$('[data-addx]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.closest('[data-add-form]'); f.hidden = true; var o = f.parentElement.querySelector('[data-addu]'); if (o) o.hidden = false;
    });
  });
  $$('[data-add-form]').forEach(function (form) {
    var units = form.closest('.rf-units'), btn = $('[data-add-submit]', form);
    form.addEventListener('submit', function (e) {
      e.preventDefault(); if (btn.disabled) return;
      btn.disabled = true; toast('Adding…', false, true);
      fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          btn.disabled = false;
          if (!j || j.success === false) { toast((j && j.message) || 'Could not add units.', true); return; }
          var card = form.closest('.rf-model');
          var names = parseList(card.getAttribute('data-idents'));
          var grid = gridFor(names.length), modelId = card.getAttribute('data-model');
          var anchor = $('.rf-addu-open', units);
          (j.units || []).forEach(function (u) {
            var a = document.createElement('a');
            a.className = 'rf-u'; a.setAttribute('data-unit', u.id); a.setAttribute('data-uvals', JSON.stringify(u.values || {}));
            a.href = u.url; a.style.gridTemplateColumns = grid;
            function cell(cls, txt) { var s = document.createElement('span'); if (cls) s.className = cls; if (txt != null) s.textContent = txt; a.appendChild(s); return s; }
            var pc = cell(null); pc.setAttribute('data-stop', '');
            var pb = document.createElement('button'); pb.type = 'button'; pb.className = 'rf-upick' + (u.photo ? ' has' : '');
            pb.setAttribute('data-upick', modelId); pb.setAttribute('data-photo', u.photo || ''); pb.title = 'Photo'; pb.setAttribute('aria-label', 'Unit photo');
            if (u.photo) pb.style.backgroundImage = "url('" + u.photo + "')"; else pb.textContent = '▣';
            pc.appendChild(pb); bindPhotoBtn(pb);
            cell('rf-mono', u.identifier || '—'); cell('c-size', u.size || '—');
            names.forEach(function (n) {
              var c = cell('rf-idc'); c.setAttribute('data-stop', '');
              var i = document.createElement('input'); i.className = 'rf-idv'; i.setAttribute('data-ui', n); i.placeholder = '—'; i.setAttribute('aria-label', n);
              i.value = (u.values && u.values[n]) || ''; c.appendChild(i);
            });
            var cd = cell('rf-cond'); var none = document.createElement('span'); none.className = 'rf-dim'; none.textContent = 'No incidents'; cd.appendChild(none);
            var st = cell(null); st.setAttribute('data-stop', '');
            var sel = document.createElement('select'); sel.setAttribute('data-uf', 'status'); sel.setAttribute('aria-label', 'Status');
            [['available', 'Available'], ['maintenance', 'Maintenance'], ['retired', 'Retired']].forEach(function (o) { var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; sel.appendChild(op); });
            st.appendChild(sel);
            var lastc = cell('rf-dim c-last', 'never'); lastc.style.fontSize = '11.5px';
            var use = cell('rf-use c-use'); use.style.setProperty('--w', '0%'); use.innerHTML = '<i></i>—';
            cell('rf-go', '›');
            units.insertBefore(a, anchor); bindUnit(a);
          });
          var cEl = $('[data-unit-count]', card), aEl = $('[data-avail-count]', card);
          if (cEl) cEl.textContent = j.unit_count + (j.unit_count === 1 ? ' unit' : ' units');
          if (aEl) { aEl.textContent = j.avail_count + ' free'; aEl.classList.toggle('av', j.avail_count > 0); aEl.classList.toggle('off', j.avail_count === 0); }
          var qty = $('[name="count"]', form), st2 = $('[name="start_number"]', form), sz = $('[name="size"]', form);
          if (qty) qty.value = 1; if (st2 && j.next_start != null) st2.value = j.next_start; if (sz) sz.value = '';
          toast(j.message || 'Added ✓'); if (qty) qty.focus();
        })
        .catch(function () { btn.disabled = false; toast("Couldn't add units — check your connection and retry.", true); });
    });
  });

  /* ---------------------------------------------------------- identifier chips (model drawers, 3 max) */
  $$('[data-idf-wrap]').forEach(function (wrap) {
    var d = wrap.closest('[data-model-drawer]');
    var hidden = $('[data-mf="identifiers"]', wrap), chips = $('[data-idf-chips]', wrap), errEl = $('[data-idf-err]', wrap);
    function list() { return parseList(hidden.value); }
    function save(l) {
      hidden.value = JSON.stringify(l); draw();
      if (d && d.__markDirty) d.__markDirty(hidden);
    }
    function err(m) { errEl.textContent = m; errEl.hidden = false; }
    function commit(inp) {
      var v = inp.value.trim().slice(0, 30); if (!v) return;
      var l = list();
      if (RESERVED.indexOf(v.toLowerCase()) !== -1) { err('“' + v + '” is already a built-in column — pick another name.'); return; }
      if (l.some(function (x) { return x.toLowerCase() === v.toLowerCase(); })) { err('This model already has “' + v + '”.'); return; }
      if (l.length >= 3) return;
      errEl.hidden = true; l.push(v); save(l);
      var n = $('.rf-chipin input', chips); if (n) n.focus();
    }
    function draw() {
      var l = list(); chips.textContent = '';
      l.forEach(function (name, i) {
        var c = document.createElement('span'); c.className = 'rf-chip'; c.appendChild(document.createTextNode(name));
        var x = document.createElement('button'); x.type = 'button'; x.textContent = '×';
        x.title = 'Remove ' + name + ' (unit values are kept, hidden until re-added)'; x.setAttribute('aria-label', 'Remove ' + name);
        x.addEventListener('click', function () { var n = list(); n.splice(i, 1); errEl.hidden = true; save(n); });
        c.appendChild(x); chips.appendChild(c);
      });
      if (l.length < 3) {
        var w = document.createElement('span'); w.className = 'rf-chipin';
        var inp = document.createElement('input'); inp.className = 'ia-input'; inp.placeholder = 'e.g. Color'; inp.maxLength = 30; inp.setAttribute('aria-label', 'New identifier');
        var ok = document.createElement('button'); ok.type = 'button'; ok.textContent = '✓'; ok.title = 'Add identifier'; ok.setAttribute('aria-label', 'Add identifier');
        inp.addEventListener('input', function () { w.classList.toggle('has-text', inp.value.trim() !== ''); errEl.hidden = true; });
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(inp); } });
        ok.addEventListener('click', function () { commit(inp); });
        w.appendChild(inp); w.appendChild(ok); chips.appendChild(w);
      } else {
        var m = document.createElement('span'); m.className = 'rf-colon'; m.textContent = '3 max'; chips.appendChild(m);
      }
    }
    draw();
  });

  /* ---------------------------------------------------------- photo picker (units + batches) */
  pm = document.createElement('div');
  pm.className = 'rf-pm'; pm.setAttribute('role', 'dialog'); pm.setAttribute('aria-label', 'Choose a photo');
  pm.innerHTML = '<div class="rf-pm-card"><h3>Choose a photo</h3><div class="rf-pm-sub">Photos on this model. Switch to Library to pull one in.</div>' +
    '<div class="rf-pills"><button type="button" class="rf-pill on" data-ptab="model">This model</button><button type="button" class="rf-pill" data-ptab="lib">Library</button></div>' +
    '<div class="rf-pm-grid" data-pgrid></div>' +
    '<div class="rf-pm-acts"><button type="button" class="ia-btn ia-btn--ghost" data-pclear>No photo</button><span class="sp"></span><button type="button" class="ia-btn" data-pcancel>Cancel</button><button type="button" class="ia-btn ia-btn--primary" data-pok>Use photo</button></div></div>';
  document.body.appendChild(pm);
  var pgrid = $('[data-pgrid]', pm), pTarget = null, pChosen = null, pTab = 'model', pModelId = null, libCache = null;

  function modelWrap(id) { return document.querySelector('[data-model-photos="' + id + '"]'); }
  function modelPhotos(id) { var w = modelWrap(id); return w ? parseList($('[data-mf="photos"]', w).value) : []; }
  function pnote(msg) { var d = document.createElement('div'); d.className = 'rf-pm-note'; d.textContent = msg; pgrid.appendChild(d); }
  function ptile(url) {
    var b = document.createElement('button'); b.type = 'button'; b.style.backgroundImage = "url('" + url + "')"; b.setAttribute('aria-label', 'Photo');
    if (url === pChosen) b.classList.add('sel');
    b.addEventListener('click', function () { pChosen = url; $$('button', pgrid).forEach(function (x) { x.classList.remove('sel'); }); b.classList.add('sel'); });
    pgrid.appendChild(b);
  }
  function drawTab() {
    $$('[data-ptab]', pm).forEach(function (t) { t.classList.toggle('on', t.getAttribute('data-ptab') === pTab); });
    pgrid.textContent = '';
    if (pTab === 'model') {
      var l = modelPhotos(pModelId);
      if (!l.length) pnote('No photos on this model yet — add them in the model editor (Edit → Photos), or pick one from the Library tab.');
      l.forEach(ptile); return;
    }
    if (libCache === null) {
      pnote('Loading library…');
      fetch(URL_FEED, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); }).then(function (d) { libCache = (d && d.media) || []; drawTab(); })
        .catch(function () { libCache = []; drawTab(); });
      return;
    }
    if (!libCache.length) { pnote('Nothing in the media library yet.'); return; }
    libCache.forEach(function (m) { if (m && m.url) ptile(m.url); });
  }
  function closeP() { pm.classList.remove('open'); pTarget = null; pChosen = null; }
  function openP(btn) {
    pTarget = btn; pChosen = btn.getAttribute('data-photo') || null;
    pModelId = btn.getAttribute('data-upick') || btn.getAttribute('data-apick'); pTab = 'model'; drawTab(); pm.classList.add('open');
  }
  $$('[data-ptab]', pm).forEach(function (t) { t.addEventListener('click', function () { pTab = t.getAttribute('data-ptab'); drawTab(); }); });
  function setBtnPhoto(btn, url) {
    btn.setAttribute('data-photo', url || '');
    if (url) { btn.style.backgroundImage = "url('" + url + "')"; btn.textContent = ''; btn.classList.add('has'); }
    else { btn.style.backgroundImage = ''; btn.textContent = '▣'; btn.classList.remove('has'); }
  }
  function applyPhoto(btn, url) {
    var line = btn.closest('.rf-u');
    if (line) { patch(unitUrl(line), 'photo_url', url || '', function () { setBtnPhoto(btn, url); }); return; }
    setBtnPhoto(btn, url);
    var form = btn.closest('form'), hid = form && $('[name="photo_url"]', form);
    if (hid) hid.value = url || '';
  }
  $('[data-pok]', pm).addEventListener('click', function () {
    var target = pTarget, url = pChosen, mid = pModelId;
    closeP(); if (!target || !url) return;
    // a library photo joins the model's set first (the server only accepts a unit
    // photo that is in the saved set), then the unit or batch takes it
    var wrap = modelWrap(mid), l = modelPhotos(mid);
    if (wrap && l.indexOf(url) === -1) {
      if (l.length >= 8) { toast('This model already has 8 photos — remove one first.', true); return; }
      l.push(url);
      var h = $('[data-mf="photos"]', wrap); h.value = JSON.stringify(l);
      if (wrap.__draw) wrap.__draw();
      patch(URL_MODEL + mid, 'photos', h.value, function () { applyPhoto(target, url); });
      return;
    }
    applyPhoto(target, url);
  });
  $('[data-pclear]', pm).addEventListener('click', function () { var t = pTarget; closeP(); if (t) applyPhoto(t, ''); });
  $('[data-pcancel]', pm).addEventListener('click', closeP);
  pm.addEventListener('click', function (e) { if (e.target === pm) closeP(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && pm.classList.contains('open')) closeP(); });
  function bindPhotoBtn(btn) {
    if (btn.dataset.pBound) return; btn.dataset.pBound = '1';
    btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openP(btn); });
  }
  $$('[data-upick],[data-apick]').forEach(bindPhotoBtn);

  /* ---------------------------------------------------------- model photo set (in the drawer; saved with Save model) */
  $$('[data-pset-wrap]').forEach(function (wrap) {
    var d = wrap.closest('[data-model-drawer]');
    var hidden = $('[data-mf="photos"]', wrap), mainEl = $('[data-pset-main]', wrap), strip = $('[data-pset]', wrap), file = $('[data-pset-file]', wrap);
    function list() { return parseList(hidden.value); }
    function dirty() { if (d && d.__markDirty) { d.__markDirty(hidden); d.__markDirty(mainEl); } }
    function setMain(url) { mainEl.value = url || ''; dirty(); draw(); }
    function save(l) {
      hidden.value = JSON.stringify(l);
      if (l.indexOf(mainEl.value) === -1) mainEl.value = l.length ? l[0] : '';
      dirty(); draw();
    }
    function draw() {
      var l = list(); strip.textContent = '';
      l.forEach(function (url, i) {
        var it = document.createElement('div'); it.className = 'rf-ph' + (url === mainEl.value ? ' main' : ''); it.style.backgroundImage = "url('" + url + "')";
        var st = document.createElement('button'); st.type = 'button'; st.className = 'st'; st.textContent = url === mainEl.value ? '★' : '☆';
        st.title = url === mainEl.value ? 'This is the main photo' : 'Make this the main photo'; st.setAttribute('aria-label', st.title);
        st.addEventListener('click', function () { setMain(url); });
        var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'rm'; rm.textContent = '×';
        rm.title = 'Remove from the set (units using it keep their photo until changed)'; rm.setAttribute('aria-label', 'Remove photo');
        rm.addEventListener('click', function () { var n = list(); n.splice(i, 1); save(n); });
        it.appendChild(st); it.appendChild(rm); strip.appendChild(it);
      });
      var acts = wrap.querySelector('.rf-photos-act');
      if (!acts) { acts = document.createElement('div'); acts.className = 'rf-photos-act'; wrap.appendChild(acts); }
      acts.textContent = '';
      if (l.length < 8) {
        var up = document.createElement('button'); up.type = 'button'; up.className = 'ia-btn ia-btn--sm'; up.textContent = '+ Upload';
        up.addEventListener('click', function () { file.click(); }); acts.appendChild(up);
        var lib = document.createElement('button'); lib.type = 'button'; lib.className = 'ia-btn ia-btn--sm'; lib.textContent = '+ From library';
        lib.addEventListener('click', function () { pTarget = null; pChosen = null; pModelId = wrap.getAttribute('data-model-photos'); pTab = 'lib'; drawTab(); pm.classList.add('open'); });
        acts.appendChild(lib);
      } else { var mx = document.createElement('span'); mx.className = 'rf-colon'; mx.textContent = '8 max'; acts.appendChild(mx); }
    }
    wrap.__draw = draw;
    file.addEventListener('change', function () {
      if (!file.files || !file.files[0]) return;
      var fd = new FormData(); fd.append('file', file.files[0]); fd.append('type', 'general');
      toast('Uploading…', false, true);
      fetch(URL_UP, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j || !j.url) { toast(j && j.message ? j.message : 'Upload failed.', true); return; }
          var l = list(); l.push(j.url); if (!mainEl.value) mainEl.value = j.url;
          save(l); toast('Added — Save model to keep it');
        })
        .catch(function () { toast('Upload failed — check your connection.', true); });
      file.value = '';
    });
    draw();
  });
})();
</script>
@endpush
