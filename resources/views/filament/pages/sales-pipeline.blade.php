{{-- MARKER-SALES-BOARD --}}
@php
    $cols  = $this->columns();
    $f     = $this->funnel();
    $cur   = $this->current();
    $today = now()->toDateString();
    $card  = 'border-radius:12px;padding:14px 16px;border:1px solid rgba(127,127,127,.22)';
    $muted = 'font-size:12px;opacity:.65';
    $input = 'rounded-lg border-gray-300 dark:bg-white/5 dark:border-white/10 text-sm';
    $badge = 'display:inline-block;border-radius:4px;padding:1px 7px;font-size:11px;font-weight:600;';
    $pri   = ['A' => 'background:rgba(248,113,113,.18);color:#f87171', 'B' => 'background:rgba(251,191,36,.18);color:#fbbf24', 'C' => 'background:rgba(154,154,163,.15);color:#9a9aa3', 'D' => 'background:rgba(154,154,163,.15);color:#9a9aa3'];
@endphp

<x-filament-panels::page>
<style>
  .spb-legend{border-radius:12px;padding:10px 14px;border:1px solid rgba(139,92,246,.35);background:rgba(139,92,246,.08);font-size:13px;margin-bottom:14px}
  .spb-funnel{display:grid;grid-template-columns:repeat(auto-fit,minmax(96px,1fr));gap:6px;margin-bottom:14px}
  .spb-funnel div{border:1px solid rgba(127,127,127,.22);border-radius:8px;padding:8px 10px;font-size:12px;opacity:.85}
  .spb-funnel div b{display:block;font-size:18px;font-weight:600}
  .spb-funnel div.lime{border-color:rgba(190,242,100,.4)}.spb-funnel div.lime b{color:#BEF264}
  .spb-funnel div.due b{color:#f87171}
  .spb-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
  .spb-filters select,.spb-filters input[type=text]{padding:5px 8px;font-size:13px}
  .spb-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(127,127,127,.35);border-radius:999px;padding:3px 10px;font-size:12px;cursor:pointer;opacity:.8}
  .spb-chip.on{border-color:rgb(139,92,246);background:rgba(139,92,246,.16);opacity:1}
  .spb-board{display:grid;grid-auto-flow:column;grid-auto-columns:232px;gap:12px;overflow-x:auto;padding-bottom:12px}
  .spb-col{border:1px solid rgba(127,127,127,.22);border-radius:12px;min-height:360px;display:flex;flex-direction:column}
  .spb-col h4{margin:0;padding:10px 12px;font-size:12px;font-weight:600;opacity:.7;display:flex;justify-content:space-between;border-bottom:1px solid rgba(127,127,127,.18)}
  .spb-col.over{outline:2px dashed rgb(139,92,246);outline-offset:-4px}
  .spb-drop{padding:8px;display:flex;flex-direction:column;gap:8px;flex:1}
  .spb-card{border:1px solid rgba(127,127,127,.25);border-radius:8px;padding:9px 10px;cursor:grab;font-size:13px;background:rgba(127,127,127,.06)}
  .spb-card:hover{border-color:rgba(139,92,246,.6)}
  .spb-card .t{font-weight:600;display:flex;justify-content:space-between;gap:6px}
  .spb-card .m{font-size:12px;opacity:.65;margin-top:2px}
  .spb-card .f{display:flex;justify-content:space-between;margin-top:6px;font-size:11px;opacity:.65}
  .spb-score{display:inline-block;min-width:26px;text-align:center;border-radius:4px;padding:0 5px;font-size:11px;font-weight:700;background:rgba(139,92,246,.2);color:#a78bfa}
  .spb-score.hi{background:rgba(190,242,100,.2);color:#BEF264}
  .spb-more{font-size:12px;opacity:.6;text-align:center;padding:6px}
  .spb-ov{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:40}
  .spb-dr{position:fixed;top:0;right:0;height:100vh;width:640px;max-width:100vw;background:rgb(24,24,27);color:#f4f4f5;border-left:1px solid rgba(127,127,127,.25);z-index:41;overflow:auto}
  html:not(.dark) .spb-dr{background:#fff;color:#111}
  .spb-dh{padding:16px 20px;border-bottom:1px solid rgba(127,127,127,.2);position:sticky;top:0;background:inherit;z-index:2}
  .spb-tabs{display:flex;gap:2px;border-bottom:1px solid rgba(127,127,127,.2);padding:0 20px}
  .spb-tabs button{background:none;border:0;border-bottom:2px solid transparent;padding:10px 12px;opacity:.65;cursor:pointer;font-size:13px}
  .spb-tabs button.on{opacity:1;border-bottom-color:rgb(139,92,246)}
  .spb-tp{padding:16px 20px}
  .spb-kv{display:grid;grid-template-columns:120px 1fr;gap:4px 10px;font-size:13px}
  .spb-kv b{font-weight:500;opacity:.6}
  .spb-tl{border-left:2px solid rgba(127,127,127,.3);margin-left:6px;padding-left:16px}
  .spb-tl .e{position:relative;padding-bottom:14px;font-size:13px}
  .spb-tl .e:before{content:"";position:absolute;left:-22px;top:5px;width:9px;height:9px;border-radius:50%;background:rgba(127,127,127,.5)}
  .spb-tl .e.hot:before{background:rgb(139,92,246)}
  .spb-tl .w{font-size:12px;opacity:.6}
  .spb-btn{border:1px solid rgba(127,127,127,.35);border-radius:6px;padding:6px 11px;font-size:13px;font-weight:500;cursor:pointer}
  .spb-btn.p{background:rgb(139,92,246);border-color:rgb(139,92,246);color:#fff}
  .spb-btn.sm{padding:4px 9px;font-size:12px}
  .spb-stagebar{display:flex;gap:3px;margin:12px 0 4px}
  .spb-stagebar div{flex:1;height:6px;border-radius:2px;background:rgba(127,127,127,.3)}
  .spb-stagebar div.on{background:rgb(139,92,246)}.spb-stagebar div.won{background:#BEF264}
  .spb-q{display:grid;grid-template-columns:1fr auto;gap:6px 12px;font-size:13px;align-items:center}
</style>

<div class="spb-legend">
  <b>What this is.</b> Every prospect from the Prospects list, as a card in its stage. Drag a card to change its stage (logged on the timeline, same as the list's Set stage). Click a card to open it.
  By default the board hides prospects nobody has touched yet — untick "Hide untouched" to see the whole imported list. Won and Lost columns are off unless "Show closed" is on.
</div>

<div class="spb-funnel">
  @foreach(\App\Models\SalesProspect::STAGES as $k => $v)
    <div><b>{{ $f['counts'][$k] ?? 0 }}</b>{{ $v }}</div>
  @endforeach
  <div class="due"><b>{{ $f['due'] }}</b>Due today</div>
  <div class="lime"><b>${{ number_format($f['wonMrr']) }}</b>Won MRR (quoted) · {{ $f['tenants'] }} linked</div>
</div>

<div class="spb-filters">
  <input type="text" class="{{ $input }}" wire:model.live.debounce.400ms="q" placeholder="Search shop or city" style="width:200px">
  <select class="{{ $input }}" wire:model.live="territoryId"><option value="">All territories</option>@foreach($this->territories() as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach<option value="none">No territory</option></select>
  <select class="{{ $input }}" wire:model.live="repId"><option value="">Any rep</option>@foreach($this->reps() as $r)<option value="{{ $r->id }}">{{ $r->name }} · {{ $r->agency?->name }}</option>@endforeach<option value="none">Unassigned</option></select>
  <select class="{{ $input }}" wire:model.live="priority"><option value="">Any priority</option><option value="A">A</option><option value="B">B</option><option value="C">C</option><option value="D">D</option></select>
  <span class="spb-chip {{ $dueOnly ? 'on' : '' }}" wire:click="$toggle('dueOnly')">Due today</span>
  <span class="spb-chip {{ $hideUntouched ? 'on' : '' }}" wire:click="$toggle('hideUntouched')">Hide untouched</span>
  <span class="spb-chip {{ $showClosed ? 'on' : '' }}" wire:click="$toggle('showClosed')">Show closed</span>
  <span style="{{ $muted }};margin-left:auto">{{ array_sum(array_map(fn ($c) => $c['total'], $cols)) }} shown</span>
</div>

<div class="spb-board" id="spb-board">
  @foreach($this->stages() as $key => $label)
    <div class="spb-col" data-stage="{{ $key }}">
      <h4>{{ $label }}<span>{{ $cols[$key]['total'] }}</span></h4>
      <div class="spb-drop">
        @foreach($cols[$key]['rows'] as $p)
          @php $due = $p->next_action_on && $p->next_action_on->toDateString() <= $today; @endphp
          <div class="spb-card" draggable="true" data-id="{{ $p->id }}" wire:key="c-{{ $p->id }}" wire:click="open('{{ $p->id }}')">
            <div class="t"><span>{{ $p->shop }}</span><span class="spb-score {{ $p->lead_score >= 75 ? 'hi' : '' }}">{{ $p->lead_score }}</span></div>
            <div class="m">{{ $p->city }}{{ $p->state ? ', ' . $p->state : '' }} · <span style="{{ $badge }}{{ $pri[$p->priority] ?? '' }}">{{ $p->priority }}</span>@if($p->quote_monthly) · ${{ number_format($p->quote_monthly) }}/mo @endif</div>
            @if($p->next_action)<div class="m" style="margin-top:4px;{{ $due ? 'color:#f87171;opacity:1' : '' }}">{{ $due ? 'Due · ' : $p->next_action_on?->format('M j') . ' · ' }}{{ $p->next_action }}</div>@endif
            <div class="f"><span>{{ $p->rep?->name ?? ($p->territory?->name ?? 'unassigned') }}</span><span>@if($p->tenant_id)<span style="{{ $badge }}background:rgba(190,242,100,.18);color:#BEF264">tenant</span>@endif</span></div>
          </div>
        @endforeach
        @if($cols[$key]['total'] > count($cols[$key]['rows']))
          <div class="spb-more">+{{ $cols[$key]['total'] - count($cols[$key]['rows']) }} more — narrow the filters</div>
        @endif
      </div>
    </div>
  @endforeach
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
        <button class="spb-btn sm" style="margin-left:auto;opacity:.5" disabled title="Comes in the next patch">Invite to trial</button>
      </div>
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
        <div class="spb-kv">
          <b>Address</b><span>{{ $cur->address ?: '—' }}{{ $cur->postcode ? ' ' . $cur->postcode : '' }}</span>
          <b>Phone</b><span>{{ $cur->phone ?: '—' }}</span>
          <b>Website</b><span>@if($cur->website)<a href="{{ $cur->website }}" target="_blank" rel="noopener" style="color:#a78bfa">{{ parse_url($cur->website, PHP_URL_HOST) ?: $cur->website }}</a>@else — @endif</span>
          <b>Hours</b><span>{{ $cur->hours ?: '—' }}</span>
          <b>Google</b><span>@if($cur->rating)★ {{ $cur->rating }} · {{ $cur->rating_count }} reviews @else — @endif @if($cur->business_status && $cur->business_status !== 'OPERATIONAL') · <span style="color:#f87171">{{ $cur->businessStatusLabel() }}</span>@endif @if($cur->google_maps_url) · <a href="{{ $cur->google_maps_url }}" target="_blank" rel="noopener" style="color:#a78bfa">map</a>@endif</span>
          <b>Contact</b><span>{{ $cur->owner_contact ?: '—' }}{{ $cur->email ? ' · ' . $cur->email : '' }}</span>
          <b>Type</b><span>{{ $cur->type ?: ($cur->primary_type ? str_replace('_', ' ', $cur->primary_type) : '—') }}</span>
          <b>Rep</b><span>{{ $cur->rep?->name ?? 'Unassigned' }}{{ $cur->rep?->agency ? ' · ' . $cur->rep->agency->name : '' }}</span>
          <b>Next action</b><span>@if($cur->next_action_on){{ $cur->next_action_on->format('M j') }} · {{ $cur->next_action }} <button class="spb-btn sm" wire:click="clearNext" style="margin-left:6px">Clear</button>@else — @endif</span>
          <b>Last contact</b><span>{{ $cur->last_contacted_at?->diffForHumans() ?? 'never' }}</span>
          <b>Source</b><span>{{ $cur->source ?: '—' }}</span>
          @if($cur->lost_reason)<b>Lost</b><span style="color:#f87171">{{ $cur->lost_reason }}</span>@endif
        </div>
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
    var board = document.getElementById('spb-board'); if (!board) { return; }
    var dragId = null;
    function lw() { var c = board.closest('[wire\\:id]'); return (c && window.Livewire) ? Livewire.find(c.getAttribute('wire:id')) : null; }
    board.addEventListener('dragstart', function (e) { var c = e.target.closest('.spb-card'); if (!c) { return; } dragId = c.getAttribute('data-id'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', dragId); } catch (x) {} });
    board.addEventListener('dragover', function (e) { var col = e.target.closest('.spb-col'); if (!col || !dragId) { return; } e.preventDefault(); board.querySelectorAll('.spb-col.over').forEach(function (x) { if (x !== col) { x.classList.remove('over'); } }); col.classList.add('over'); });
    board.addEventListener('dragleave', function (e) { var col = e.target.closest('.spb-col'); if (col && !col.contains(e.relatedTarget)) { col.classList.remove('over'); } });
    board.addEventListener('drop', function (e) { var col = e.target.closest('.spb-col'); if (!col || !dragId) { return; } e.preventDefault(); col.classList.remove('over'); var w = lw(); if (w) { w.call('moveStage', dragId, col.getAttribute('data-stage')); } dragId = null; });
    board.addEventListener('dragend', function () { dragId = null; board.querySelectorAll('.spb-col.over').forEach(function (x) { x.classList.remove('over'); }); });
    window.addEventListener('board-lost-prompt', function () { setTimeout(function () { var l = document.getElementById('spb-lost'); if (l) { l.style.display = ''; var i = l.querySelector('input'); if (i) { i.focus(); } } }, 60); });
  })();
</script>
</x-filament-panels::page>
