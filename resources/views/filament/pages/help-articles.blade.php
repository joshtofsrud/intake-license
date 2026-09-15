<x-filament-panels::page>
@php
  // MARKER-HELP-ADMIN
  $sellable = fn ($a) => $a->price_cents > 0 || $a->price_display_override;
@endphp

<style>
  .hc-wrap{--hc-line:var(--ia-border,rgba(127,127,127,.22));--hc-accent:#8b7cf6}
  .hc-note{border:1px solid var(--hc-line);border-radius:12px;padding:11px 14px;font-size:12.5px;
    line-height:1.6;opacity:.85;margin-bottom:16px}
  .hc-cols{display:grid;grid-template-columns:230px 1fr 330px;gap:14px;align-items:start}
  @media(max-width:1100px){.hc-cols{grid-template-columns:1fr}}
  .hc-panel{border:1px solid var(--hc-line);border-radius:12px;overflow:hidden}
  .hc-panel h3{font-size:12px;font-weight:600;letter-spacing:.02em;opacity:.7;margin:0;
    padding:12px 14px;border-bottom:1px solid var(--hc-line);display:flex;gap:8px;align-items:center}
  .hc-panel h3 .sp{margin-left:auto;font-weight:400;opacity:.6}
  .hc-row{display:flex;align-items:center;gap:10px;padding:10px 13px;
    border-bottom:1px solid rgba(127,127,127,.12);cursor:pointer}
  .hc-row:last-child{border-bottom:0}
  .hc-row.on{background:rgba(139,124,246,.12);box-shadow:inset 2px 0 0 var(--hc-accent)}
  .hc-row .grab{cursor:grab;opacity:.4;letter-spacing:-2px;font-size:12px}
  .hc-row .n{margin-left:auto;opacity:.5;font-size:12px}
  .hc-row.dragging{opacity:.35}
  .hc-row.over{box-shadow:inset 0 0 0 1px var(--hc-accent)}
  .hc-t{flex:1;min-width:0}
  .hc-t .m{font-size:11.5px;opacity:.5;margin-top:1px}
  .hc-pill{font-size:11px;padding:3px 8px;border-radius:99px;border:1px solid var(--hc-line);white-space:nowrap}
  .hc-pill--gate{background:rgba(139,124,246,.12);border-color:rgba(139,124,246,.4)}
  .hc-pill--draft{background:rgba(240,196,106,.1);border-color:rgba(240,196,106,.35)}
  .hc-body{padding:13px 14px}
  .hc-f{margin-bottom:12px}
  .hc-f label{display:block;font-size:11.5px;opacity:.65;margin-bottom:4px}
  .hc-f input[type=text],.hc-f select{width:100%;background:transparent;border:1px solid var(--hc-line);
    border-radius:8px;color:inherit;font:inherit;font-size:13px;padding:7px 9px}
  .hc-hint{font-size:11px;opacity:.55;margin-top:4px;line-height:1.5}
  .hc-checks{display:flex;flex-wrap:wrap;gap:6px}
  .hc-check{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;border:1px solid var(--hc-line);
    border-radius:99px;padding:5px 10px;cursor:pointer}
  .hc-check.on{background:rgba(139,124,246,.12);border-color:rgba(139,124,246,.45)}
  .hc-check.noprice{opacity:.55}
  .hc-groups{display:flex;flex-direction:column;gap:6px}
  .hc-group{border:1px solid var(--hc-line);border-radius:10px;padding:0 10px}
  .hc-group>summary{cursor:pointer;font-size:12.5px;padding:8px 0;list-style:none;display:flex;gap:8px;align-items:center}
  .hc-group>summary::before{content:'›';display:inline-block;transition:transform .15s;opacity:.6}
  .hc-group[open]>summary::before{transform:rotate(90deg)}
  .hc-group>summary span{margin-left:auto;font-size:11px;opacity:.5}
  .hc-group .hc-checks{padding:0 0 10px}
  .hc-unpriced{margin:0 0 10px}
  .hc-unpriced>summary{cursor:pointer;font-size:11.5px;opacity:.55;list-style:none;padding:2px 0 6px}
  .hc-unpriced .hc-checks{padding:0}
  .hc-reach{border:1px solid var(--hc-line);border-radius:10px;padding:11px 12px}
  .hc-reach .big{font-size:19px;font-weight:700}
  .hc-reach .who{font-size:11.5px;opacity:.7;margin-top:6px;line-height:1.7}
  .hc-reach .no{opacity:.45;text-decoration:line-through}
  .hc-empty{padding:24px 14px;text-align:center;opacity:.5;font-size:13px}
  .hc-add{padding:10px 12px;border-top:1px solid var(--hc-line)}
  .hc-add input{width:100%;background:transparent;border:1px solid var(--hc-line);border-radius:8px;
    color:inherit;font:inherit;font-size:13px;padding:6px 9px}
</style>

<div class="hc-wrap">

  <div class="hc-note">
    <b>Who sees what.</b> A shop reads an article only if it holds every add-on the article lists and is on
    a high enough plan. The count on each row is how many of your {{ $tenants->count() }} shops can read it
    now, so a wrong gate shows up here rather than in a support message.
    A locked article names the add-on that unlocks it — and if that add-on has no price configured, the
    article hides instead, because a shop should never be shown something it has no way to buy.
  </div>

  <div class="hc-cols">

    {{-- categories --}}
    <div class="hc-panel">
      <h3>Categories <span class="sp">drag to reorder</span></h3>
      <div id="hcCats">
        @forelse($cats as $c)
          <div class="hc-row {{ $c->id === $selCat ? 'on' : '' }}" draggable="true" data-cat="{{ $c->id }}"
               wire:click="selectCategory('{{ $c->id }}')">
            <span class="grab" aria-hidden="true">⋮⋮</span>
            <span>{{ $c->name }}</span>
            <span class="n">{{ $counts[$c->id] ?? 0 }}</span>
          </div>
        @empty
          <div class="hc-empty">No categories yet.</div>
        @endforelse
      </div>
      <div class="hc-add">
        <input type="text" placeholder="Add a category…" wire:model="newCategory" wire:keydown.enter="addCategory">
      </div>
    </div>

    {{-- articles --}}
    <div class="hc-panel">
      <h3>
        {{ optional($cats->firstWhere('id', $selCat))->name ?? 'Articles' }}
        <span class="sp">drag to reorder, or onto a category to move</span>
        <button type="button" class="hc-pill" wire:click="createArticle" style="cursor:pointer">+ New</button>
      </h3>
      <div id="hcArts">
        @forelse($arts as $a)
          @php $r = $reaches[$a->id]; $gated = count($a->help_addons ?? []) || $a->help_min_tier; @endphp
          <div class="hc-row {{ $a->id === optional($sel)->id ? 'on' : '' }}" draggable="true" data-art="{{ $a->id }}"
               wire:click="selectArticle('{{ $a->id }}')">
            <span class="grab" aria-hidden="true">⋮⋮</span>
            <span class="hc-t">
              <span>{{ $a->title }}</span>
              <span class="m">{{ $a->help_key ?: 'no screen key' }}</span>
            </span>
            @unless($a->is_published)<span class="hc-pill hc-pill--draft">Draft</span>@endunless
            <span class="hc-pill {{ $gated ? 'hc-pill--gate' : '' }}">{{ $r['count'] }} of {{ $r['total'] }}</span>
          </div>
        @empty
          <div class="hc-empty">Nothing here yet. Drag an article in, or make one.</div>
        @endforelse
      </div>
    </div>

    {{-- inspector --}}
    <div class="hc-panel">
      <h3>Article</h3>
      <div class="hc-body">
        @if($sel)
          <div class="hc-f">
            <label>Title</label>
            <input type="text" value="{{ $sel->title }}"
                   wire:change="setField('{{ $sel->id }}','title',$event.target.value)">
          </div>

          <div class="hc-f">
            <label>Category</label>
            <select wire:change="setField('{{ $sel->id }}','help_category_id',$event.target.value)">
              @foreach($cats as $c)
                <option value="{{ $c->id }}" @selected($c->id === $sel->help_category_id)>{{ $c->name }}</option>
              @endforeach
            </select>
            <div class="hc-hint">Or drag it onto a category on the left.</div>
          </div>

          <div class="hc-f">
            <label>Screen key</label>
            <input type="text" value="{{ $sel->help_key }}"
                   wire:change="setField('{{ $sel->id }}','help_key',$event.target.value)">
            <div class="hc-hint">The help button on that screen opens this article.</div>
          </div>

          <div class="hc-f">
            <label>Lowest plan that can read it</label>
            <select wire:change="setField('{{ $sel->id }}','help_min_tier',$event.target.value)">
              <option value="">Every plan</option>
              @foreach($tiers as $t)
                <option value="{{ $t }}" @selected($t === $sel->help_min_tier)>{{ ucfirst($t) }}</option>
              @endforeach
            </select>
          </div>

          <div class="hc-f">
            <label>Add-ons the shop must have</label>
            {{-- MARKER-HELP-PICKER — grouped, filtered, and the unpriced ones
                 tucked behind a disclosure so the list reads at a glance. --}}
            @php
              $chosen  = $sel->help_addons ?? [];
              $labels  = ['communication' => 'Communication', 'operations' => 'Operations',
                          'feature' => 'Features', 'retail' => 'Retail', 'team' => 'Team'];
            @endphp
            @if(count($chosen))
              <div class="hc-checks" style="margin-bottom:10px">
                @foreach($addonGroups->flatten(1)->whereIn('code', $chosen) as $ad)
                  <span class="hc-check on" wire:click="toggleAddon('{{ $sel->id }}','{{ $ad->code }}')" title="Click to remove">
                    {{ $ad->name }} ×
                  </span>
                @endforeach
              </div>
            @endif
            <div class="hc-groups">
              @foreach($addonGroups as $cat => $list)
                @php
                  $priced   = $list->filter(fn ($a) => $sellable($a) && ! in_array($a->code, $chosen, true));
                  $unpriced = $list->filter(fn ($a) => ! $sellable($a) && ! in_array($a->code, $chosen, true));
                @endphp
                @if($priced->count() || $unpriced->count())
                  <details class="hc-group" {{ $loop->first ? 'open' : '' }}>
                    <summary>{{ $labels[$cat] ?? ucfirst($cat) }} <span>{{ $priced->count() }}</span></summary>
                    <div class="hc-checks">
                      @foreach($priced as $ad)
                        <span class="hc-check" wire:click="toggleAddon('{{ $sel->id }}','{{ $ad->code }}')">{{ $ad->name }}</span>
                      @endforeach
                    </div>
                    @if($unpriced->count())
                      <details class="hc-unpriced">
                        <summary>{{ $unpriced->count() }} with no price set</summary>
                        <div class="hc-checks">
                          @foreach($unpriced as $ad)
                            <span class="hc-check noprice" wire:click="toggleAddon('{{ $sel->id }}','{{ $ad->code }}')"
                                  title="No price configured — an article gated on this hides instead of locking.">{{ $ad->name }}</span>
                          @endforeach
                        </div>
                      </details>
                    @endif
                  </details>
                @endif
              @endforeach
            </div>
            <div class="hc-hint">All of them, not any. One-time services and credits aren't listed — they can't gate a feature.</div>
          </div>

          <div class="hc-f">
            <label>Shops that don't qualify</label>
            <select wire:change="setField('{{ $sel->id }}','help_locked_mode',$event.target.value)">
              <option value="lock" @selected($sel->help_locked_mode === 'lock')>See the title, locked</option>
              <option value="hide" @selected($sel->help_locked_mode === 'hide')>See nothing</option>
            </select>
            <div class="hc-hint">
              Locked names the add-on that unlocks it — a quiet upsell. Hidden means the article doesn't
              exist for them, including by direct link.
            </div>
          </div>

          <div class="hc-f">
            <label>Who can read it now</label>
            <div class="hc-reach">
              <div class="big">{{ $selReach['count'] }} of {{ $selReach['total'] }} shops</div>
              <div class="who">
                {{ implode(', ', array_slice($selReach['can'], 0, 6)) }}
                @if(count($selReach['can']) > 6) +{{ count($selReach['can']) - 6 }} more @endif
                @if(count($selReach['cannot']))
                  <br><span class="no">{{ implode(', ', array_slice($selReach['cannot'], 0, 4)) }}</span>
                  @if(count($selReach['cannot']) > 4) +{{ count($selReach['cannot']) - 4 }} more @endif
                @endif
              </div>
            </div>
          </div>

          <div class="hc-f" style="margin-bottom:0;display:flex;gap:8px;align-items:center">
            <label class="hc-check {{ $sel->is_published ? 'on' : '' }}" style="margin:0">
              <input type="checkbox" @checked($sel->is_published)
                     wire:change="setField('{{ $sel->id }}','is_published',$event.target.checked)"> Published
            </label>
            <a class="hc-pill" href="{{ route('admin.marketing-pages.edit-content', $sel->id) }}">Open in builder</a>
          </div>
        @else
          <div class="hc-empty">Pick an article, or make one.</div>
        @endif
      </div>
    </div>

  </div>
</div>

<script>
// MARKER-HELP-ADMIN — drag to organise. Ordering writes as it happens.
document.addEventListener('DOMContentLoaded', function () {
  var dragArt = null, dragCat = null;

  function ids(sel, attr) {
    return Array.prototype.map.call(document.querySelectorAll(sel), function (n) { return n.dataset[attr]; });
  }

  document.addEventListener('dragstart', function (e) {
    var a = e.target.closest('[data-art]'), c = e.target.closest('[data-cat]');
    if (a) { dragArt = a.dataset.art; a.classList.add('dragging'); }
    else if (c) { dragCat = c.dataset.cat; }
  });

  document.addEventListener('dragend', function () {
    dragArt = null; dragCat = null;
    document.querySelectorAll('.hc-row').forEach(function (n) { n.classList.remove('dragging', 'over'); });
  });

  document.addEventListener('dragover', function (e) {
    var t = e.target.closest('[data-art],[data-cat]');
    if (!t || (!dragArt && !dragCat)) return;
    e.preventDefault();
    document.querySelectorAll('.hc-row').forEach(function (n) { n.classList.remove('over'); });
    t.classList.add('over');
  });

  document.addEventListener('drop', function (e) {
    var t = e.target.closest('[data-art],[data-cat]');
    if (!t) return;
    e.preventDefault();

    if (dragArt && t.dataset.cat) {
      window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id'))
        .call('moveArticle', dragArt, t.dataset.cat);
      return;
    }

    var list = t.dataset.art ? document.getElementById('hcArts') : document.getElementById('hcCats');
    var moving = t.dataset.art
      ? list.querySelector('[data-art="' + dragArt + '"]')
      : list.querySelector('[data-cat="' + dragCat + '"]');
    if (!moving || moving === t) return;

    var r = t.getBoundingClientRect();
    list.insertBefore(moving, (e.clientY - r.top) < r.height / 2 ? t : t.nextSibling);

    var comp = window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id'));
    if (t.dataset.art) comp.call('reorderArticles', ids('#hcArts [data-art]', 'art'));
    else comp.call('reorderCategories', ids('#hcCats [data-cat]', 'cat'));
  });
});
</script>
</x-filament-panels::page>
