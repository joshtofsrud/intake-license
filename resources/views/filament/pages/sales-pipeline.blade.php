{{-- MARKER-SALES-BOARD --}}
{{-- MARKER-SALES-PROSPECTS2 — one Prospects page: Board or List, scoped to an industry. No cards: lanes and rows sit on the page. --}}
@php
    $cur   = $this->current();
    $today = now()->toDateString();
    $st    = $this->stats();
    $inds  = $this->industries();
    $hidden = $this->hiddenCount();
    $cols  = $mode === 'board' ? $this->columns() : [];
    $list  = $mode === 'list' ? $this->listRows() : null;
    // MARKER-PROSPECTS-TIDY — columns that say nothing on this page are left out
    $hasLoop = $list ? $list['rows']->contains(fn ($p) => ! empty($p->loop)) : false;
    $muted = 'font-size:12px;color:var(--sx-dim)';
    $input = 'sx-in';
    $card  = 'border:1px solid var(--sx-line-2);border-radius:10px;padding:12px 14px';
    $badge = 'display:inline-block;border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;';
    $pri   = ['A' => 'color:#f47c7c', 'B' => 'color:#f5b942', 'C' => 'color:#74747d', 'D' => 'color:#74747d'];
    $curInd = $industryId ? $inds->firstWhere('id', $industryId) : null;
@endphp

<x-filament-panels::page>
<style>
  .sx-root, .spb-dr { --sx-line:rgba(255,255,255,.075); --sx-line-2:rgba(255,255,255,.14); --sx-dim:#a3a3ab; --sx-faint:#74747d;
    --sx-violet:#8b5cf6; --sx-vsoft:rgba(139,92,246,.17); --sx-vtext:#b4a0fb; --sx-lime:#BEF264; --sx-amber:#f5b942; --sx-red:#f47c7c; font-size:14px; }
  .sx-in { background-color:rgba(255,255,255,.04); border:1px solid var(--sx-line-2); border-radius:7px; padding:6px 10px; font-size:13px; color:inherit; }
  select.sx-in { padding-right:32px; background-repeat:no-repeat; } /* MARKER-SALES-SELECT-FIX */
  .sx-in option { background:#26272c; }
  .sx-head { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .sx-seg { display:inline-flex; border:1px solid var(--sx-line-2); border-radius:8px; padding:2px; }
  .sx-seg button { background:none; border:0; padding:5px 13px; border-radius:6px; font-weight:500; font-size:13px; color:var(--sx-dim); cursor:pointer; }
  .sx-seg button.on { background:var(--sx-vsoft); color:#fff; }
  .sx-inds { display:flex; gap:18px; border-bottom:1px solid var(--sx-line); margin-top:4px; overflow-x:auto; }
  .sx-inds button { background:none; border:0; border-bottom:2px solid transparent; padding:9px 0; color:var(--sx-dim); cursor:pointer; white-space:nowrap; font-weight:500; }
  .sx-inds button.on { color:#fff; border-bottom-color:var(--sx-violet); }
  .sx-inds button span { color:var(--sx-faint); font-weight:400; margin-left:4px; }
  .sx-tally { display:flex; gap:30px; flex-wrap:wrap; margin-top:16px; font-size:13px; color:var(--sx-dim); }
  .sx-tally b { display:block; font-size:22px; font-weight:650; color:#fff; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
  .sx-tally .lime { color:var(--sx-lime); } .sx-tally .amber { color:var(--sx-amber); }
  .sx-lede { color:var(--sx-faint); font-size:13px; line-height:1.55; margin:12px 0 0; max-width:80ch; }
  .sx-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:12px 0; margin-top:14px; border-top:1px solid var(--sx-line); border-bottom:1px solid var(--sx-line); }
  .sx-tog { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:var(--sx-dim); cursor:pointer; user-select:none; }
  .sx-tog input { accent-color:var(--sx-violet); }
  /* MARKER-PROSPECTS-TIDY — the page's own checkbox, not the browser's white box */
  .sx-root input[type=checkbox] { -webkit-appearance:none; appearance:none; width:16px; height:16px; margin:0; flex:none;
    border:1px solid var(--sx-line-2); border-radius:4px; background:rgba(255,255,255,.04); display:inline-grid; place-content:center;
    cursor:pointer; vertical-align:middle; box-shadow:none; transition:background .12s, border-color .12s; }
  .sx-root input[type=checkbox]:hover { border-color:rgba(255,255,255,.3); }
  .sx-root input[type=checkbox]:focus-visible { outline:2px solid var(--sx-vsoft); outline-offset:1px; }
  .sx-root input[type=checkbox]::before { content:""; width:9px; height:9px; transform:scale(0); transition:transform .1s;
    background:#fff; clip-path:polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%); }
  .sx-root input[type=checkbox]:checked { background:var(--sx-violet); border-color:var(--sx-violet); }
  .sx-root input[type=checkbox]:checked::before { transform:scale(1); }
  /* MARKER-PROSPECTS-TIDY — one line per value; only the shop name wraps */
  .sx-t td { white-space:nowrap; }
  .sx-t td.sx-shop { white-space:normal; min-width:170px; max-width:260px; }
  .sx-t td.sx-contact { line-height:1.55; }
  .sx-t td.sx-contact a { margin-right:10px; }
  .sx-count { margin-left:auto; color:var(--sx-faint); font-size:12.5px; }
  .sx-hidden { display:flex; gap:14px; align-items:center; padding:12px 0; border-bottom:1px solid var(--sx-line); font-size:13.5px; color:var(--sx-dim); }
  .sx-hidden b { color:#fff; }
  .sx-btn { border:1px solid var(--sx-line-2); background:none; border-radius:7px; padding:6px 12px; font-weight:500; font-size:13px; cursor:pointer; white-space:nowrap; }
  .sx-btn.p { background:var(--sx-violet); border-color:var(--sx-violet); color:#fff; }
  .sx-btn.sm { padding:4px 9px; font-size:12.5px; }
  .sx-board { display:grid; overflow-x:auto; }
  .spb-col { border-right:1px solid var(--sx-line); min-height:460px; min-width:180px; }
  .spb-col:last-child { border-right:0; }
  .spb-col.over { background:rgba(139,92,246,.07); }
  .sx-lh { padding:14px 14px 10px; }
  .sx-lh .n { font-size:24px; font-weight:650; letter-spacing:-.025em; line-height:1; font-variant-numeric:tabular-nums; }
  .sx-lh .l { display:flex; justify-content:space-between; gap:6px; font-size:12.5px; color:var(--sx-dim); margin-top:5px; }
  .sx-lh .l b { color:var(--sx-lime); font-weight:500; }
  .spb-card { display:block; width:100%; text-align:left; background:none; border:0; border-top:1px solid var(--sx-line); padding:10px 14px; cursor:grab; position:relative; }
  .spb-card:hover { background:rgba(255,255,255,.035); }
  .spb-card.sel { background:var(--sx-vsoft); }
  .spb-card .s { font-weight:550; font-size:13.5px; line-height:1.3; padding-right:30px; }
  .spb-card .c, .spb-card .w { font-size:12px; color:var(--sx-faint); margin-top:2px; }
  .spb-card .a { font-size:12px; color:var(--sx-dim); margin-top:5px; }
  .spb-card .a.due { color:var(--sx-amber); }
  .spb-card .sc { position:absolute; top:10px; right:12px; font-size:12px; font-weight:600; color:var(--sx-faint); font-variant-numeric:tabular-nums; }
  .spb-card .sc.hi { color:var(--sx-lime); }
  .spb-more { font-size:12px; color:var(--sx-faint); padding:10px 14px; border-top:1px solid var(--sx-line); }
  .sx-t { width:100%; border-collapse:collapse; font-size:13.5px; }
  .sx-t th { text-align:left; font-weight:500; color:var(--sx-faint); font-size:12.5px; padding:10px 10px; border-bottom:1px solid var(--sx-line-2); white-space:nowrap; }
  .sx-t td { padding:10px; border-bottom:1px solid var(--sx-line); vertical-align:top; }
  .sx-t tr.r { cursor:pointer; } .sx-t tr.r:hover td { background:rgba(255,255,255,.03); } .sx-t tr.r.sel td { background:var(--sx-vsoft); }
  .sx-t input[type=checkbox] { accent-color:var(--sx-violet); }
  .sx-t .num { text-align:right; font-variant-numeric:tabular-nums; }
  /* MARKER-PROSPECTS-SORT */
  .sx-t th.sx-sort { cursor:pointer; user-select:none; }
  .sx-t th.sx-sort:hover { color:#fff; }
  .sx-t th.sx-sort.on { color:#fff; }
  .sx-t th .sx-arr { font-size:10px; margin-left:3px; opacity:.8; }
  .sx-bulk { display:flex; align-items:center; gap:8px; padding:10px 0; flex-wrap:wrap; font-size:13px; color:var(--sx-dim); }
  .sx-pager { display:flex; align-items:center; gap:10px; padding:12px 0; color:var(--sx-faint); font-size:13px; }
  /* drawer */
  .spb-ov { position:fixed; inset:0; background:rgba(0,0,0,.42); z-index:40; }
  .spb-dr { position:fixed; top:0; right:0; height:100vh; width:600px; max-width:100vw; background:#26272c; color:#ececee; z-index:41; overflow:auto; box-shadow:-30px 0 60px rgba(0,0,0,.35); }
  html:not(.dark) .spb-dr { background:#fff; color:#111; }
  .spb-dh { padding:20px 24px 14px; border-bottom:1px solid var(--sx-line); }
  .spb-tabs { display:flex; gap:20px; border-bottom:1px solid var(--sx-line); padding:0 24px; }
  .spb-tabs button { background:none; border:0; border-bottom:2px solid transparent; padding:10px 0; color:var(--sx-dim); cursor:pointer; font-size:13px; font-weight:500; }
  .spb-tabs button.on { color:inherit; border-bottom-color:var(--sx-violet); }
  .spb-tp { padding:18px 24px 26px; }
  .spb-kv { display:grid; grid-template-columns:120px 1fr; gap:8px 12px; font-size:13.5px; }
  .spb-kv b { font-weight:400; color:var(--sx-faint); }
  .spb-tl { border-left:2px solid var(--sx-line-2); margin-left:6px; padding-left:16px; }
  .spb-tl .e { position:relative; padding:0 0 14px; font-size:13px; }
  .spb-tl .e:before { content:""; position:absolute; left:-22px; top:5px; width:9px; height:9px; border-radius:50%; background:var(--sx-faint); }
  .spb-tl .e.hot:before { background:var(--sx-violet); }
  .spb-btn { border:1px solid var(--sx-line-2); background:none; border-radius:7px; padding:6px 11px; font-size:13px; font-weight:500; cursor:pointer; }
  .spb-btn.p { background:var(--sx-violet); border-color:var(--sx-violet); color:#fff; }
  .spb-btn.sm { padding:3px 9px; font-size:12px; }
  .spb-stagebar { display:flex; gap:3px; margin:14px 0 4px; }
  .spb-stagebar div { flex:1; height:6px; border-radius:3px; background:rgba(255,255,255,.1); }
  .spb-stagebar div.on { background:var(--sx-violet); } .spb-stagebar div.won { background:var(--sx-lime); }
  .spb-q { display:grid; grid-template-columns:1fr auto; gap:6px 12px; font-size:13px; align-items:center; }
  .sx-play { border-top:1px solid var(--sx-line); margin-top:18px; padding-top:14px; font-size:13px; }
  .sx-play ol { margin:6px 0 0; padding-left:18px; color:var(--sx-dim); line-height:1.6; }
</style>

<div class="sx-root">
  <div class="sx-head">
    <div class="sx-seg" role="tablist" aria-label="View">
      <button class="{{ $mode === 'board' ? 'on' : '' }}" wire:click="$set('mode', 'board')">Board</button>
      <button class="{{ $mode === 'list' ? 'on' : '' }}" wire:click="$set('mode', 'list')">List</button>
    </div>
    <span style="margin-left:auto"></span>
    <a class="sx-btn" href="{{ \App\Filament\Pages\SalesFindShops::getUrl() }}">Find shops</a>
    <a class="sx-btn p" href="{{ \App\Filament\Resources\SalesProspectResource::getUrl('create') }}">New prospect</a>
  </div>

  <div class="sx-inds" role="tablist" aria-label="Industry">
    <button class="{{ $industryId === '' ? 'on' : '' }}" wire:click="$set('industryId', '')">All<span>{{ number_format(\App\Models\SalesProspect::count()) }}</span></button>
    @foreach($inds as $ind)
      <button class="{{ $industryId === $ind->id ? 'on' : '' }}" wire:click="$set('industryId', '{{ $ind->id }}')">{{ $ind->name }}<span>{{ number_format($ind->prospects_count) }}</span></button>
    @endforeach
  </div>

  <div class="sx-tally">
    <div><b>{{ number_format($st['total']) }}</b>prospects, {{ $st['a'] }} A-priority</div>
    <div><b>{{ number_format($st['verified']) }}</b>verified, <span class="amber" style="color:var(--sx-amber)">{{ $st['total'] - $st['verified'] }} to check</span></div>
    <div><b class="{{ $st['due'] ? 'amber' : '' }}">{{ $st['due'] }}</b>due today or overdue</div>
    <div><b>{{ $st['trials'] }}</b>active trials, {{ $st['won'] }} won</div>
    <div><b>{{ $st['tenants'] }}</b>linked to a tenant</div>
    <div><b class="lime">${{ number_format($st['value']) }}</b>a month, A and B weighted</div>
  </div>

  <p class="sx-lede">
    Board and List show the same prospects with the same filters{{ $curInd ? ', limited to ' . $curInd->name : '' }}.
    On the board, drag a shop to another stage; the move is added to its timeline.
    @if($hideUntouched && $site === '') Shops nobody has worked yet are hidden. @endif
    @if($site !== '') The Website pass filter shows every matching shop, worked or not. @endif
    @if(! $showClosed) Won and lost are hidden. @endif
  </p>

  <div class="sx-bar">
    <input type="text" class="sx-in" style="width:210px" wire:model.live.debounce.400ms="q" placeholder="Shop or city">
    <select class="sx-in" wire:model.live="territoryId"><option value="">All territories</option><option value="none">No territory</option>@foreach($this->territories() as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
    <select class="sx-in" wire:model.live="repId"><option value="">Any rep</option><option value="none">House (no rep)</option>@foreach($this->reps() as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
    <select class="sx-in" wire:model.live="priority"><option value="">Any priority</option>@foreach(\App\Models\SalesProspect::PRIORITIES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select>
    {{-- MARKER-PROSPECTS-PLACE — pick any number of states; ZIPs or ZIP starts --}}
    @php $stCounts = $this->stateCounts(); @endphp
    <div x-data="{ open: false }" style="position:relative" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
      <button type="button" class="sx-in" style="cursor:pointer;min-width:120px;text-align:left" x-on:click="open = !open">
        {{ $states ? (count($states) <= 3 ? implode(', ', $states) : count($states) . ' states') : 'All states' }} ▾
      </button>
      <div x-show="open" x-cloak style="position:absolute;z-index:40;top:calc(100% + 4px);left:0;width:340px;max-height:360px;overflow:auto;background:#141416;border:1px solid var(--sx-line-2);border-radius:10px;padding:10px;box-shadow:0 16px 40px rgba(0,0,0,.5)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:12px;color:var(--sx-dim)">
          <span>{{ count($states) ? count($states) . ' picked' : 'Pick one or more' }}</span>
          @if($states)<button type="button" wire:click="clearStates" style="background:none;border:0;color:#a78bfa;cursor:pointer;font:inherit">Clear</button>@endif
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:2px 8px">
          @foreach($stCounts as $code => $n)
            <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;padding:3px 2px;cursor:pointer">
              <input type="checkbox" value="{{ $code }}" wire:model.live="states" style="accent-color:var(--sx-violet)">
              <span>{{ $code }}</span><span style="color:var(--sx-dim);font-size:11px">{{ number_format($n) }}</span>
            </label>
          @endforeach
        </div>
      </div>
    </div>
    <input type="text" class="sx-in" style="width:150px" wire:model.live.debounce.500ms="zip" placeholder="ZIP, e.g. 992, 83814" title="One or more ZIP codes, or their first digits, separated by commas">

    {{-- MARKER-SALES-SITE-FILTER --}}
    <select class="sx-in" wire:model.live="site">
      <option value="">Any contact info</option>
      <option value="found">Website pass found something</option>
      <option value="email">Has email</option>
      <option value="phone">Has phone</option>
      <option value="instagram">Has Instagram</option>
      <option value="facebook">Has Facebook</option>
      <option value="owner">Has owner name</option>
      <option value="brands">Has brands</option>
      <option value="nothing">Website read, nothing usable</option>
      <option value="unread">Website not read yet</option>
    </select>
    <label class="sx-tog"><input type="checkbox" wire:model.live="dueOnly"> Due today</label>
    <label class="sx-tog"><input type="checkbox" wire:model.live="hideUntouched"> Hide untouched</label>
    <label class="sx-tog"><input type="checkbox" wire:model.live="showClosed"> Show won and lost</label>
  </div>

  @if($hidden)
    <div class="sx-hidden">
      <span style="flex:1"><b>{{ number_format($hidden) }} {{ $hidden === 1 ? 'prospect is' : 'prospects are' }} hidden.</b> Nobody has worked {{ $hidden === 1 ? 'it' : 'them' }} yet, and "Hide untouched" is on. Log a call, set a stage, a rep or a next action and a shop shows here.</span>
      <button class="sx-btn sm" wire:click="$set('hideUntouched', false)">Show them</button>
    </div>
  @endif

  @if($mode === 'board')
    <div class="sx-board" id="spb-board" style="grid-template-columns:repeat({{ count($cols) }}, minmax(180px, 1fr))">
      @foreach($this->stages() as $key => $label)
        @php $mrr = $cols[$key]['rows']->sum('quote_monthly'); @endphp
        <div class="spb-col" data-stage="{{ $key }}">
          <div class="sx-lh"><div class="n">{{ number_format($cols[$key]['total']) }}</div><div class="l"><span>{{ $label }}</span>@if($mrr)<b>${{ number_format($mrr) }}/mo</b>@endif</div></div>
          @foreach($cols[$key]['rows'] as $p)
            @php $due = $p->next_action_on && $p->next_action_on->toDateString() <= $today; @endphp
            <button type="button" class="spb-card {{ $openId === $p->id ? 'sel' : '' }}" draggable="true" data-id="{{ $p->id }}" wire:key="c-{{ $p->id }}" wire:click="open('{{ $p->id }}')">
              <div class="s">{{ $p->shop }}</div>
              <span class="sc {{ $p->lead_score >= 75 ? 'hi' : '' }}" title="Lead score">{{ $p->lead_score }}</span>
              <div class="c"><span style="{{ $pri[$p->priority] ?? '' }};font-weight:700;font-size:11px">{{ $p->priority }}</span> {{ $p->city }}{{ $p->state ? ', ' . $p->state : '' }}</div>
              @if($p->next_action)<div class="a {{ $due ? 'due' : '' }}">{{ $due ? 'Due' : $p->next_action_on?->format('M j') }}{{ $p->next_action_on ? ', ' : '' }}{{ $p->next_action }}</div>@endif
              <div class="w">{{ $p->rep?->name ?? 'House' }}{{ $p->tenant_id ? ', on a trial account' : '' }}</div>
            </button>
          @endforeach
          @if($cols[$key]['total'] > count($cols[$key]['rows']))
            <div class="spb-more">{{ number_format($cols[$key]['total'] - count($cols[$key]['rows'])) }} more. Narrow the filters or use List.</div>
          @elseif($cols[$key]['total'] === 0)
            <div class="spb-more">Nothing here</div>
          @endif
        </div>
      @endforeach
    </div>
  @else
    @php $pageIds = $list['rows']->pluck('id')->all(); @endphp
    @if($selected)
      <div class="sx-bulk">
        <b style="color:#fff">{{ count($selected) }} selected</b>
        <select class="sx-in" wire:model.live="bulkAction">
          <option value="">Choose an action</option><option value="stage">Set stage</option><option value="rep">Assign rep</option>
          <option value="industry">Set industry</option><option value="territory">Assign by territory rules</option>
          <option value="verify">Mark verified</option><option value="pull">Pull details from Places</option>
          <option value="email">Email selected (opens Platform email)</option>
        </select>
        @if($bulkAction === 'stage')<select class="sx-in" wire:model="bulkValue"><option value="">Stage…</option>@foreach(\App\Models\SalesProspect::STAGES as $k => $v)@if($k !== 'lost')<option value="{{ $k }}">{{ $v }}</option>@endif @endforeach</select>@endif
        @if($bulkAction === 'rep')<select class="sx-in" wire:model="bulkValue"><option value="">House (no rep)</option>@foreach($this->reps() as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>@endif
        @if($bulkAction === 'industry')<select class="sx-in" wire:model="bulkValue"><option value="">None</option>@foreach($inds as $ind)<option value="{{ $ind->id }}">{{ $ind->name }}</option>@endforeach</select>@endif
        @if($confirmPull)
          <span style="color:var(--sx-amber)">This makes {{ count($selected) }} Places lookups, about ${{ number_format($this->pullCostCents() / 100, 2) }}.</span>
          <button class="sx-btn p sm" wire:click="applyBulk">Pull details</button><button class="sx-btn sm" wire:click="cancelPull">Cancel</button>
        @elseif($bulkAction)
          <button class="sx-btn p sm" wire:click="applyBulk" wire:loading.attr="disabled">Apply</button>
        @endif
        <button class="sx-btn sm" style="margin-left:auto" wire:click="$set('selected', [])">Clear selection</button>
      </div>
    @endif
    <table class="sx-t">
      <thead><tr>
        <th style="width:28px"><input type="checkbox" aria-label="Select this page" @checked($pageIds && ! array_diff($pageIds, $selected)) wire:click="toggleAllOnPage({{ json_encode($pageIds) }})"></th>
        {{-- MARKER-PROSPECTS-SORT — click a heading to sort; again to reverse; a third time for the default (due first, then score) --}}
        @foreach(['shop' => ['Shop', ''], 'place' => ['Location', ''], 'contact' => ['Contact', ''], 'industry' => ['Industry', ''], 'loop' => ['Loop', ''], 'priority' => ['Pri', ''], 'verified' => ['Verified', ''], 'score' => ['Score', 'num'], 'rep' => ['Rep', ''], 'stage' => ['Stage', ''], 'next' => ['Next action', ''], 'quote' => ['Quote', 'num']] as $sk => [$sLabel, $sCls])
          @continue(($sk === 'loop' && ! $hasLoop) || ($sk === 'industry' && $industryId))
          <th class="sx-sort {{ $sCls }} {{ $sortBy === $sk ? 'on' : '' }}" wire:click="sortList('{{ $sk }}')" title="Sort by {{ strtolower($sLabel) }}">{{ $sLabel }}@if($sortBy === $sk)<span class="sx-arr">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif</th>
        @endforeach
      </tr></thead>
      <tbody>
        @forelse($list['rows'] as $p)
          @php $due = $p->next_action_on && $p->next_action_on->toDateString() <= $today; @endphp
          <tr class="r {{ $openId === $p->id ? 'sel' : '' }}" wire:key="l-{{ $p->id }}">
            <td wire:click.stop><input type="checkbox" value="{{ $p->id }}" wire:model.live="selected" aria-label="Select {{ $p->shop }}"></td>
            <td wire:click="open('{{ $p->id }}')" class="sx-shop">{{ $p->shop }}</td>
            {{-- MARKER-PROSPECTS-PLACE --}}
            <td wire:click="open('{{ $p->id }}')" style="white-space:nowrap">{{ $p->city }}{{ $p->state ? ', ' . $p->state : '' }}<div style="{{ $muted }}">{{ $p->postcode }}</div></td>
            {{-- MARKER-SALES-SITE-FILTER — email, phone and socials at a glance; links don't open the drawer --}}
            <td wire:click="open('{{ $p->id }}')" class="sx-contact" style="font-size:12.5px">
              @if($p->email)<a href="mailto:{{ $p->email }}" onclick="event.stopPropagation()" style="color:#a78bfa">{{ $p->email }}</a><br>@endif
              @if($p->phone)<span>{{ $p->phone }}</span><br>@endif
              @php $soc = (array) ($p->socials ?? []); @endphp
              @foreach(['instagram' => 'Instagram', 'facebook' => 'Facebook'] as $net => $netName)
                @if(! empty($soc[$net]))<a href="{{ $soc[$net] }}" target="_blank" rel="noopener" onclick="event.stopPropagation()" style="color:#a78bfa;margin-right:8px">{{ $netName }}</a>@endif
              @endforeach
              @if(! $p->email && ! $p->phone && empty($soc['instagram']) && empty($soc['facebook']))<span style="color:var(--sx-dim)">—</span>@endif
            </td>
            @unless($industryId)<td wire:click="open('{{ $p->id }}')" style="color:var(--sx-dim)">{{ $p->channel?->name ?? 'None' }}</td>@endunless
            @if($hasLoop)<td wire:click="open('{{ $p->id }}')" style="color:var(--sx-dim)">{{ $p->loop ? 'L' . $p->loop : '' }}</td>@endif
            <td wire:click="open('{{ $p->id }}')"><span style="{{ $pri[$p->priority] ?? '' }};font-weight:700;font-size:12px">{{ $p->priority }}</span></td>
            <td wire:click="open('{{ $p->id }}')" style="color:{{ $p->verified ? 'var(--sx-lime)' : 'var(--sx-amber)' }}">{{ $p->verified ? 'Yes' : 'To check' }}</td>
            <td wire:click="open('{{ $p->id }}')" class="num" style="{{ $p->lead_score >= 75 ? 'color:var(--sx-lime)' : '' }}">{{ $p->lead_score }}</td>
            <td wire:click="open('{{ $p->id }}')" style="color:var(--sx-dim)">{{ $p->rep?->name ?? 'House' }}</td>
            <td wire:click="open('{{ $p->id }}')">{{ \App\Models\SalesProspect::STAGES[$p->stage] ?? $p->stage }}</td>
            <td wire:click="open('{{ $p->id }}')" style="color:{{ $due ? 'var(--sx-amber)' : 'var(--sx-dim)' }}">@if($p->next_action){{ $due ? 'Due' : $p->next_action_on?->format('M j') }}, {{ $p->next_action }}@endif</td>
            <td wire:click="open('{{ $p->id }}')" class="num">{{ $p->quote_monthly ? '$' . number_format($p->quote_monthly) : '' }}</td>
          </tr>
        @empty
          <tr><td colspan="13" style="color:var(--sx-dim);padding:22px 10px">No prospects match these filters.</td></tr>
        @endforelse
      </tbody>
    </table>
    <div class="sx-pager">
      <span>{{ number_format($list['total']) }} prospects</span>
      @if($list['pages'] > 1)
        <span style="margin-left:auto">Page {{ $listPage }} of {{ $list['pages'] }}</span>
        <button class="sx-btn sm" wire:click="$set('listPage', {{ max(1, $listPage - 1) }})" @disabled($listPage <= 1)>Previous</button>
        <button class="sx-btn sm" wire:click="$set('listPage', {{ min($list['pages'], $listPage + 1) }})" @disabled($listPage >= $list['pages'])>Next</button>
      @endif
    </div>
  @endif
</div>

@if($cur)
  <div class="spb-ov" wire:click="close"></div>
  <div class="spb-dr" wire:key="dr-{{ $cur->id }}">
    <div class="spb-dh">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
        <div>
          <div style="font-size:18px;font-weight:600">{{ $cur->shop }}</div>
          <div style="{{ $muted }}">{{ $cur->city }}{{ $cur->state ? ', ' . $cur->state : '' }} · {{ $cur->territory?->name ?? 'no territory' }} · score {{ $cur->lead_score }}@if($cur->tenant_id) · <span style="color:#BEF264">tenant: {{ $cur->tenant?->name }}</span>@endif</div>
        </div>
        <div style="display:flex;gap:6px"><a class="spb-btn sm" style="text-decoration:none" href="{{ $this->editUrl($cur->id) }}">Full record</a><button class="spb-btn sm" wire:click="close">Close</button></div>
      </div>
      @php $keys = array_keys(\App\Models\SalesProspect::STAGES); $idx = $cur->stageIndex(); @endphp
      <div class="spb-stagebar">@foreach(array_slice($keys, 0, 7) as $i => $k)<div class="{{ $i <= $idx ? ($cur->stage === 'won' ? 'won' : 'on') : '' }}" title="{{ \App\Models\SalesProspect::STAGES[$k] }}"></div>@endforeach</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center">
        <select class="{{ $input }}" style="width:auto" wire:change="setStage($event.target.value)">@foreach(\App\Models\SalesProspect::STAGES as $k => $v)<option value="{{ $k }}" @selected($cur->stage === $k)>{{ $v }}</option>@endforeach</select>
        <select class="{{ $input }}" style="width:auto" wire:change="setPriority($event.target.value)">@foreach(\App\Models\SalesProspect::PRIORITIES as $k => $v)<option value="{{ $k }}" @selected($cur->priority === $k)>Priority {{ $v }}</option>@endforeach</select>
        <select class="{{ $input }}" style="width:auto" wire:change="setRep($event.target.value)"><option value="">Unassigned</option>@foreach($this->reps() as $r)<option value="{{ $r->id }}" @selected($cur->sales_rep_id === $r->id)>{{ $r->name }} · {{ $r->agency?->name }}</option>@endforeach</select>
        @if(! $cur->tenant_id && ! in_array($cur->stage, ['won', 'lost'], true))
          <button class="spb-btn sm" style="margin-left:auto;background:#BEF264;border-color:#BEF264;color:#0a0a0a" wire:click="openInvite">{{ $cur->invited_at ? 'Re-send trial invite' : 'Invite to trial' }}</button>
        @endif
      </div>
      {{-- MARKER-SALES-EMAIL --}}
      <div style="display:flex;gap:8px;margin-top:10px;align-items:center">
        @if($cur->email)
          <button class="spb-btn sm" wire:click="openEmail">Email {{ $cur->email }}</button>
        @else
          <span style="{{ $muted }}">Add an email address on Profile to email this shop.</span>
        @endif
      </div>
      @if($showEmail)
        <div style="{{ $card }};margin-top:10px">
          <div style="font-weight:600;font-size:13px;margin-bottom:4px">Email {{ $cur->owner_contact ?: $cur->shop }}</div>
          <div style="{{ $muted }};margin-bottom:8px">Sent from Intake's platform email with an unsubscribe link and your postal address. Replies arrive in the platform inbox. It's added to this shop's timeline.</div>
          <input type="text" class="sx-in" style="width:100%;margin-bottom:8px" wire:model="emailSubject" placeholder="Subject">
          @error('emailSubject')<div style="color:#f47c7c;font-size:12px;margin:-4px 0 6px">{{ $message }}</div>@enderror
          <textarea class="sx-in" rows="7" style="width:100%" wire:model="emailBody"></textarea>
          @error('emailBody')<div style="color:#f47c7c;font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
          <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px"><button class="spb-btn" wire:click="$set('showEmail', false)">Cancel</button><button class="spb-btn p" wire:click="sendEmail" wire:loading.attr="disabled">Send</button></div>
        </div>
      @endif
      {{-- MARKER-SALES-INVITE --}}
      @if($cur->invited_at && ! $cur->tenant_id)
        <div style="{{ $muted }};margin-top:8px">Invite sent {{ $cur->invited_at->diffForHumans() }} to {{ $cur->invite_email }} · {{ ucfirst($cur->invite_plan) }} · <a href="{{ \App\Services\Sales\ProspectConversion::signupUrl($cur) }}" target="_blank" rel="noopener" style="color:#a78bfa">signup link</a></div>
      @endif
      @if($showInvite)
        <div style="{{ $card }};margin-top:10px">
          <div style="font-weight:600;font-size:13px;margin-bottom:8px">Invite to trial</div>
          <div style="{{ $muted }};margin-bottom:8px">Emails the owner a signup link carrying this prospect. Their trial starts through the normal signup (card on file, nothing charged until the trial ends). The card moves to Trial when they sign up and to Won on the first paid invoice; the agency's commission accrues from that invoice.</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <div><div style="{{ $muted }}">Owner email</div><input type="email" class="{{ $input }}" style="width:100%" wire:model="inviteEmail"></div>
            <div><div style="{{ $muted }}">Owner name</div><input type="text" class="{{ $input }}" style="width:100%" wire:model="inviteName"></div>
            <div><div style="{{ $muted }}">Plan</div><select class="{{ $input }}" style="width:100%" wire:model="invitePlan">@foreach($this->tiers() as $key => $cents)<option value="{{ $key }}">{{ ucfirst($key) }} · ${{ number_format($cents / 100) }}/mo</option>@endforeach</select></div>
            <div style="grid-column:1/-1"><div style="{{ $muted }}">Personal note (optional — replaces the default paragraph)</div><textarea class="{{ $input }}" rows="3" style="width:100%" wire:model="inviteMessage"></textarea></div>
          </div>
          @error('inviteEmail')<div style="color:#f87171;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
          <div style="display:flex;gap:8px;margin-top:10px;justify-content:flex-end"><button class="spb-btn" wire:click="$set('showInvite', false)">Cancel</button><button class="spb-btn" style="background:#BEF264;border-color:#BEF264;color:#0a0a0a" wire:click="sendInvite" wire:loading.attr="disabled">Send invite</button></div>
        </div>
      @endif
      @if(! $cur->tenant_id)
        <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
          <span style="{{ $muted }}">Already a tenant?</span>
          <select class="{{ $input }}" style="width:auto;flex:1" wire:model="linkTenantId"><option value="">Link an existing tenant…</option>@foreach($this->linkableTenants() as $t)<option value="{{ $t->id }}">{{ $t->name }} · {{ $t->subdomain }}</option>@endforeach</select>
          <button class="spb-btn sm" wire:click="linkTenant">Link</button>
        </div>
      @else
        <div style="{{ $muted }};margin-top:8px">Tenant <b>{{ $cur->tenant?->name }}</b> · {{ $cur->tenant?->subscription_status ?? 'no billing' }}@if($cur->tenant?->trial_ends_at) · trial ends {{ $cur->tenant->trial_ends_at->format('M j') }}@endif · <a href="{{ \App\Filament\Resources\TenantResource::getUrl('edit', ['record' => $cur->tenant_id]) }}" style="color:#a78bfa">open tenant</a></div>
      @endif
      <div id="spb-lost" style="{{ $cur->stage === 'lost' ? '' : 'display:none' }};margin-top:10px">
        <div style="{{ $muted }}">Why lost</div>
        <div style="display:flex;gap:8px"><input type="text" class="{{ $input }}" wire:model="lostReason" style="flex:1" placeholder="e.g. corporate POS contract through 2028"><button class="spb-btn" wire:click="setStage('lost')">Mark lost</button></div>
      </div>
    </div>

    <div class="spb-tabs">
      @foreach(['profile' => 'Profile', 'timeline' => 'Timeline (' . $cur->activities->count() . ')', 'quote' => 'Quote', 'notes' => 'Notes'] as $k => $v)
        <button class="{{ $tab === $k ? 'on' : '' }}" wire:click="setTab('{{ $k }}')">{{ $v }}</button>
      @endforeach
    </div>

    @if($tab === 'profile')
      <div class="spb-tp">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:8px">
          <span style="{{ $muted }}">{{ $cur->enriched_at ? 'Details pulled ' . $cur->enriched_at->diffForHumans() : 'Phone and hours not yet pulled.' }}</span>
          <button class="spb-btn sm" wire:click="enrich" wire:loading.attr="disabled"><span wire:loading.remove wire:target="enrich">{{ $cur->enriched_at ? 'Refresh from Places' : 'Pull details from Places' }}</span><span wire:loading wire:target="enrich">Pulling…</span></button>
        </div>
        {{-- MARKER-SALES-PROSPECTS2 — industry and contact are editable here; the email is what prospect email goes to. --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 10px;margin-bottom:8px">
          <div><div style="{{ $muted }}">Industry</div>
            <select class="sx-in" style="width:100%" wire:change="setIndustry($event.target.value)"><option value="">None</option>@foreach($inds as $ind)<option value="{{ $ind->id }}" @selected($cur->channel_id === $ind->id)>{{ $ind->name }}</option>@endforeach</select></div>
          <div></div>
          <div><div style="{{ $muted }}">Contact name</div><input type="text" class="sx-in" style="width:100%" wire:model="contactName"></div>
          <div><div style="{{ $muted }}">Email</div><input type="email" class="sx-in" style="width:100%" wire:model="contactEmail"></div>
        </div>
        @error('contactEmail')<div style="color:#f47c7c;font-size:12px;margin-bottom:6px">{{ $message }}</div>@enderror
        <div style="text-align:right;margin-bottom:16px"><button class="spb-btn sm p" wire:click="saveContact">Save contact</button></div>
        <div class="spb-kv">
          <b>Address</b><span>{{ $cur->address ?: '—' }}{{ $cur->postcode ? ' ' . $cur->postcode : '' }}</span>
          <b>Phone</b><span>{{ $cur->phone ?: '—' }}</span>
          <b>Website</b><span>@if($cur->website)<a href="{{ $cur->website }}" target="_blank" rel="noopener" style="color:#a78bfa">{{ parse_url($cur->website, PHP_URL_HOST) ?: $cur->website }}</a>@else — @endif</span>
          {{-- MARKER-SALES-SITE-FILTER — what the website pass found --}}
          <b>Socials</b><span>@php $curSoc = (array) ($cur->socials ?? []); @endphp
            @forelse($curSoc as $net => $url)<a href="{{ $url }}" target="_blank" rel="noopener" style="color:#a78bfa;margin-right:10px">{{ ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'strava' => 'Strava', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'x' => 'X'][$net] ?? ucfirst($net) }}</a>@empty — @endforelse</span>
          <b>Brands</b><span>{{ $cur->brands ? implode(', ', (array) $cur->brands) : '—' }}</span>
          <b>Website pass</b><span style="color:var(--sx-dim)">@if($cur->site_scanned_at){{ ['ok' => 'Read', 'nothing_found' => 'Read, nothing usable', 'unreachable' => "Site didn't answer", 'not_shop_site' => "Link isn't the shop's own site", 'name_mismatch' => "Site doesn't mention this shop", 'social_only' => 'Link is a social page', 'no_site' => 'No website', 'error' => 'Could not read'][$cur->site_scan_status] ?? $cur->site_scan_status }} · {{ $cur->site_scanned_at->diffForHumans() }}@else Not read yet @endif</span>
          <b>Hours</b><span>{{ $cur->hours ?: '—' }}</span>
          <b>Google</b><span>@if($cur->rating)★ {{ $cur->rating }} · {{ $cur->rating_count }} reviews @else — @endif @if($cur->business_status && $cur->business_status !== 'OPERATIONAL') · <span style="color:#f87171">{{ $cur->businessStatusLabel() }}</span>@endif @if($cur->google_maps_url) · <a href="{{ $cur->google_maps_url }}" target="_blank" rel="noopener" style="color:#a78bfa">map</a>@endif</span>
          <b>Type</b><span>{{ $cur->type ?: ($cur->primary_type ? str_replace('_', ' ', $cur->primary_type) : '—') }}</span>
          <b>Rep</b><span>{{ $cur->rep?->name ?? 'Unassigned' }}{{ $cur->rep?->agency ? ' · ' . $cur->rep->agency->name : '' }}</span>
          <b>Next action</b><span>@if($cur->next_action_on){{ $cur->next_action_on->format('M j') }} · {{ $cur->next_action }} <button class="spb-btn sm" wire:click="clearNext" style="margin-left:6px">Clear</button>@else — @endif</span>
          <b>Last contact</b><span>{{ $cur->last_contacted_at?->diffForHumans() ?? 'never' }}</span>
          <b>Source</b><span>{{ $cur->source ?: '—' }}</span>
          @if($cur->lost_reason)<b>Lost</b><span style="color:#f87171">{{ $cur->lost_reason }}</span>@endif
        </div>
        @if($cur->channel)
          <div class="sx-play"><b>{{ $cur->channel->name }} pitch</b>
            @if($cur->channel->best_ask)<div style="margin-top:4px;color:var(--sx-dim)">Best opening ask: {{ $cur->channel->best_ask }}</div>@endif
            @if($cur->channel->playbook)<ol>@foreach((array) $cur->channel->playbook as $step)<li>{{ is_array($step) ? implode(' ', array_map('strval', $step)) : $step }}</li>@endforeach</ol>@endif
          </div>
        @endif
      </div>
    @elseif($tab === 'timeline')
      <div class="spb-tp">
        <div style="{{ $card }};margin-bottom:16px">
          <div style="display:flex;gap:8px">
            <select class="{{ $input }}" style="width:auto" wire:model="logType"><option value="call">Call</option><option value="email">Email</option><option value="demo">Demo</option><option value="follow_up">Visit / follow-up</option><option value="note">Note</option></select>
            <input type="text" class="{{ $input }}" style="flex:1" wire:model="logBody" wire:keydown.enter="saveLog" placeholder="What happened">
          </div>
          <div style="display:flex;gap:8px;margin-top:8px">
            <input type="date" class="{{ $input }}" style="width:auto" wire:model="logNext">
            <input type="text" class="{{ $input }}" style="flex:1" wire:model="logNextAction" placeholder="Next action">
            <button class="spb-btn p" wire:click="saveLog">Save</button>
          </div>
          @error('logBody')<div style="color:#f87171;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
        </div>
        <div class="spb-tl">
          @forelse($cur->activities as $a)
            <div class="e {{ in_array($a->type, ['stage_change', 'demo'], true) ? 'hot' : '' }}">
              <div>@if($a->type === 'stage_change'){{ \App\Models\SalesProspect::STAGES[$a->stage_from] ?? $a->stage_from }} → {{ \App\Models\SalesProspect::STAGES[$a->stage_to] ?? $a->stage_to }}@if($a->body) · {{ $a->body }}@endif @else<b style="text-transform:capitalize">{{ str_replace('_', ' ', $a->type) }}</b>@if($a->body) · {{ $a->body }}@endif @endif</div>
              <div class="w">{{ $a->occurred_at?->format('M j, Y g:ia') }}</div>
            </div>
          @empty
            <div style="{{ $muted }}">Nothing logged yet.</div>
          @endforelse
        </div>
      </div>
    @elseif($tab === 'quote')
      <div class="spb-tp">
        <div style="{{ $muted }};margin-bottom:6px">Base tier</div>
        <div style="margin-bottom:12px">
          <span class="spb-chip {{ ! $quoteTier ? 'on' : '' }}" wire:click="$set('quoteTier', null)">None</span>
          @foreach($this->tiers() as $key => $cents)
            <span class="spb-chip {{ $quoteTier === $key ? 'on' : '' }}" wire:click="$set('quoteTier', '{{ $key }}')">{{ ucfirst($key) }} ${{ number_format($cents / 100) }}</span>
          @endforeach
        </div>
        <div style="{{ $muted }};margin-bottom:6px">Add-ons</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px">
          @foreach($this->addons() as $a)
            <label class="spb-chip {{ in_array($a->code, $quoteAddons, true) ? 'on' : '' }}"><input type="checkbox" class="rounded" value="{{ $a->code }}" wire:model.live="quoteAddons"> {{ $a->name }} ${{ number_format($a->price_cents / 100) }}</label>
          @endforeach
        </div>
        <div class="spb-q">
          <span style="font-weight:600;font-size:15px;border-top:1px solid rgba(127,127,127,.3);padding-top:8px">Monthly</span><span style="font-weight:600;font-size:15px;border-top:1px solid rgba(127,127,127,.3);padding-top:8px">${{ number_format($this->quotePreview()) }}</span>
          @if($cur->rep?->agency)<span style="{{ $muted }}">{{ $cur->rep->agency->name }} year-one commission ({{ (int) round($cur->rep->agency->commission_year1 * 100) }}%)</span><span style="{{ $muted }}">${{ number_format($this->quotePreview() * (float) $cur->rep->agency->commission_year1) }}/mo</span>@endif
          @if($cur->quote_monthly)<span style="{{ $muted }}">Saved quote</span><span style="{{ $muted }}">${{ number_format($cur->quote_monthly) }}/mo</span>@endif
        </div>
        <div style="margin-top:14px"><button class="spb-btn p" wire:click="saveQuote">Save quote</button></div>
      </div>
    @else
      <div class="spb-tp">
        <textarea class="{{ $input }}" rows="10" style="width:100%" wire:model="notes" placeholder="Owner's name, what they use today, objections, best time to call…"></textarea>
        <div style="margin-top:10px"><button class="spb-btn p" wire:click="saveNotes">Save notes</button></div>
      </div>
    @endif
  </div>
@endif

<script>
  // MARKER-SALES-BOARD — drag/drop is delegated on the board container so it survives Livewire re-renders.
  (function () {
    // MARKER-SALES-PROSPECTS2 — listen on the document: the board isn't on the page in List view.
    if (window.__spbDrag) { return; } window.__spbDrag = true;
    var board = document;
    var dragId = null;
    function lw() { var r = document.querySelector('.sx-root'); var c = r ? r.closest('[wire\\:id]') : null; return (c && window.Livewire) ? Livewire.find(c.getAttribute('wire:id')) : null; }
    board.addEventListener('dragstart', function (e) { var c = e.target.closest('.spb-card'); if (!c) { return; } dragId = c.getAttribute('data-id'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', dragId); } catch (x) {} });
    board.addEventListener('dragover', function (e) { var col = e.target.closest('.spb-col'); if (!col || !dragId) { return; } e.preventDefault(); board.querySelectorAll('.spb-col.over').forEach(function (x) { if (x !== col) { x.classList.remove('over'); } }); col.classList.add('over'); });
    board.addEventListener('dragleave', function (e) { var col = e.target.closest('.spb-col'); if (col && !col.contains(e.relatedTarget)) { col.classList.remove('over'); } });
    board.addEventListener('drop', function (e) { var col = e.target.closest('.spb-col'); if (!col || !dragId) { return; } e.preventDefault(); col.classList.remove('over'); var w = lw(); if (w) { w.call('moveStage', dragId, col.getAttribute('data-stage')); } dragId = null; });
    board.addEventListener('dragend', function () { dragId = null; board.querySelectorAll('.spb-col.over').forEach(function (x) { x.classList.remove('over'); }); });
    window.addEventListener('board-lost-prompt', function () { setTimeout(function () { var l = document.getElementById('spb-lost'); if (l) { l.style.display = ''; var i = l.querySelector('input'); if (i) { i.focus(); } } }, 60); });
  })();
</script>
</x-filament-panels::page>
