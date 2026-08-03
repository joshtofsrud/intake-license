{{-- MARKER-TITLE-CONTROL --}}
<x-filament-panels::page>

<style>
  .tc-grid{display:grid;grid-template-columns:300px 1fr;gap:16px;align-items:start}
  .tc-rail{display:flex;flex-direction:column;gap:14px;position:sticky;top:16px}
  .tc-defs{border:1px solid #3b3320;border-radius:10px;background:rgba(217,164,65,.05);overflow:hidden}
  .tc-defs .h{padding:9px 12px;background:rgba(217,164,65,.10);border-bottom:1px solid #3b3320;
    font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#d9a441}
  .tc-lvl{padding:10px 12px;border-bottom:1px solid rgba(217,164,65,.14);cursor:pointer;width:100%;text-align:left;background:none}
  .tc-lvl:last-child{border-bottom:0}
  .tc-lvl:hover{background:rgba(217,164,65,.07)}
  .tc-lvl.on{background:rgba(124,92,255,.14);box-shadow:inset 2px 0 0 rgb(var(--primary-500))}
  .tc-lvl .n{font-size:12.5px;font-weight:600;display:flex;align-items:center;gap:7px}
  .tc-lvl .s{display:block;font-size:10.5px;opacity:.55;margin-top:3px;line-height:1.5}
  .tc-lvl.locked{opacity:.55;cursor:default}
  .tc-step{display:inline-block;width:13px;opacity:.45;font-size:10px}

  .tc-q{border:1px solid rgba(255,255,255,.10);border-radius:10px;overflow:hidden}
  .tc-q .h{padding:9px 12px;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.10);
    font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.55}
  .tc-qi{padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.06);font-size:12px;cursor:pointer;width:100%;text-align:left;background:none}
  .tc-qi:hover{background:rgba(255,255,255,.04)}
  .tc-qi.on{background:rgba(124,92,255,.14);box-shadow:inset 2px 0 0 rgb(var(--primary-500))}
  .tc-qi .s{display:block;font-size:10.5px;opacity:.5;margin-top:2px}

  .tc-chain{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:11.5px;margin-bottom:14px}
  .tc-node{border:1px solid rgba(255,255,255,.12);border-radius:7px;padding:5px 10px;background:none;cursor:pointer}
  .tc-node.on{border-color:rgb(var(--primary-500));background:rgba(124,92,255,.12)}
  .tc-node.def{border-color:#3b3320;background:rgba(217,164,65,.06)}
  .tc-node .t{font-size:9.5px;opacity:.5;display:block}

  .tc-split{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  @media (max-width:1500px){.tc-split{grid-template-columns:1fr}}

  .tc-card{border:1px solid rgba(255,255,255,.10);border-radius:10px;padding:13px}
  .tc-lbl{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;opacity:.55;
    margin-bottom:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .tc-tpl{width:100%;background:rgba(0,0,0,.28);border:1px solid rgba(255,255,255,.12);border-radius:8px;
    padding:9px 11px;font-family:ui-monospace,Menlo,monospace;font-size:12.5px;color:inherit}
  .tc-tpl.inh{border-style:dashed;opacity:.72}

  .tc-pill{font-size:9.5px;border-radius:4px;padding:2px 6px;font-weight:600}
  .tc-pill.own{background:rgba(74,222,128,.16);color:#4ade80}
  .tc-pill.inh{background:rgba(255,255,255,.09);opacity:.75}
  .tc-pill.warn{background:rgba(217,164,65,.18);color:#d9a441}
  .tc-link{text-decoration:underline;text-underline-offset:2px;cursor:pointer}

  .tc-tok{display:flex;justify-content:space-between;gap:10px;width:100%;text-align:left;background:none;
    padding:5px 8px;border-radius:6px;font-size:11.5px;cursor:pointer;border:1px solid transparent}
  .tc-tok:hover{background:rgba(124,92,255,.12);border-color:rgba(124,92,255,.35)}
  .tc-tok .k{font-family:ui-monospace,monospace}
  .tc-tok .v{opacity:.5;font-size:11px;max-width:52%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .tc-tok .v.none{opacity:.28;font-style:italic}
  .tc-grp{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.4;margin:9px 0 4px}

  .tc-prow{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06);font-size:12px;line-height:1.65}
  .tc-prow:last-child{border-bottom:0}
  .tc-was{opacity:.42;text-decoration:line-through}
  .tc-now{color:#4ade80}
  .tc-sku{font-family:ui-monospace,monospace;font-size:10.5px;opacity:.45;display:block;margin-bottom:3px}
</style>

@php
  // Each of these runs a query or the composer. Livewire does not memoize a
  // getXProperty between reads, so a bare $this->preview in three places
  // would recompose the samples three times.
  $sources = $this->sources;
  $deps    = $this->dependents;
  $counts  = $this->counts;
  $preview = $this->preview;
  $tokens  = $this->tokens;

  $seenWas = [];
  $seenNow = [];
  foreach ($preview as $p) {
      $w = trim((string) $p['was']);
      $n = trim((string) $p['now']);
      if ($w !== '') { $seenWas[$w] = ($seenWas[$w] ?? 0) + 1; }
      if ($n !== '') { $seenNow[$n] = ($seenNow[$n] ?? 0) + 1; }
  }
  $dupes = [
      'was' => count(array_filter($seenWas, fn ($c) => $c > 1)),
      'now' => count(array_filter($seenNow, fn ($c) => $c > 1)),
  ];
@endphp

<div class="tc-grid">

  {{-- ============================ rail ============================ --}}
  <div class="tc-rail">

    <div class="tc-defs">
      <div class="h">Defaults — the ladder</div>

      <div class="tc-lvl locked">
        <div class="n"><span class="tc-step">0</span> Built-in fallback</div>
        <span class="s"><code>{brand} {model}</code> · used only when nothing below has a value. Not editable.</span>
      </div>

      <button type="button" class="tc-lvl {{ $level === 'global' ? 'on' : '' }}" wire:click="selectGlobal">
        <div class="n"><span class="tc-step">1</span> Global default</div>
        <span class="s">Every distributor · <b>{{ number_format($deps['*'] ?? 0) }}</b> categories inherit from here</span>
      </button>

      @foreach ($this->distributors as $code)
        <button type="button"
                class="tc-lvl {{ $level === 'dist' && $distributor === $code ? 'on' : '' }}"
                wire:click="selectDistributor('{{ $code }}')">
          <div class="n"><span class="tc-step">2</span> {{ $code }} · any category</div>
          <span class="s">Overrides global for {{ $code }} · <b>{{ number_format($deps[$code] ?? 0) }}</b> categories inherit from here</span>
        </button>
      @endforeach
    </div>

    <div class="tc-q">
      <div class="h">Categories — {{ number_format($this->queueLeft) }} to review</div>

      <div style="padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;gap:6px;flex-wrap:wrap">
        <select wire:model.live="queueDistributor" class="fi-input" style="font-size:11.5px;padding:4px 8px;border-radius:6px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.12);color:inherit">
          <option value="">All</option>
          @foreach ($this->distributors as $code)
            <option value="{{ $code }}">{{ $code }}</option>
          @endforeach
        </select>
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Find category…"
               style="flex:1;min-width:110px;font-size:11.5px;padding:4px 8px;border-radius:6px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.12);color:inherit">
      </div>

      <div style="padding:7px 12px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;gap:5px;flex-wrap:wrap">
        <button type="button" wire:click="$set('filter','review')"
                class="tc-pill {{ $filter === 'review' ? 'warn' : 'inh' }}">Needs work {{ $counts['review'] }}</button>
        <button type="button" wire:click="$set('filter','own')"
                class="tc-pill {{ $filter === 'own' ? 'warn' : 'inh' }}">Has rule {{ $counts['own'] }}</button>
        <button type="button" wire:click="$set('filter','all')"
                class="tc-pill {{ $filter === 'all' ? 'warn' : 'inh' }}">All {{ $counts['all'] }}</button>
      </div>

      <div style="max-height:430px;overflow:auto">
        @forelse ($this->scopes as $scope)
          <button type="button"
                  class="tc-qi {{ $level === 'scope' && $scopeId === $scope->id ? 'on' : '' }}"
                  wire:click="selectScope({{ $scope->id }})">
            <div style="display:flex;align-items:center;gap:6px">
              <span style="opacity:.5;font-size:10.5px">{{ $scope->distributor_code }}</span>
              <span>{{ $scope->category_key ?: 'any category' }}</span>
              @if ($scope->reviewed)
                <span style="margin-left:auto;color:#4ade80;font-size:10.5px">done</span>
              @endif
            </div>
            <span class="s">
              {{ number_format($scope->item_count) }} items
              @if ($scope->has_own_rule) · own rule @else · inherits @endif
            </span>
          </button>
        @empty
          <div style="padding:14px;font-size:12px;opacity:.6">Nothing matches.</div>
        @endforelse
      </div>

      <div style="padding:8px 12px;border-top:1px solid rgba(255,255,255,.08)">
        {{ $this->scopes->links() }}
      </div>
    </div>
  </div>

  {{-- ============================ main ============================ --}}
  <div>

    <div class="tc-chain">
      @foreach ($this->chain as $node)
        @if ($node['level'] === 'fallback')
          <span class="tc-node" style="opacity:.5;cursor:default">built-in</span>
        @elseif ($node['level'] === 'global')
          <button type="button" class="tc-node def {{ $node['active'] ? 'on' : '' }}" wire:click="selectGlobal">
            <span class="t">level 1</span>Global</button>
        @elseif ($node['level'] === 'dist')
          <button type="button" class="tc-node def {{ $node['active'] ? 'on' : '' }}"
                  wire:click="selectDistributor('{{ $node['code'] }}')">
            <span class="t">level 2</span>{{ $node['label'] }}</button>
        @else
          <span class="tc-node on"><span class="t">editing · level 3</span>{{ $node['label'] }}</span>
        @endif
        @if (! $loop->last)
          <span style="opacity:.35">&rarr;</span>
        @endif
      @endforeach
    </div>

    @if ($level !== 'scope')
      <div style="border:1px solid #3b3320;background:rgba(217,164,65,.06);border-radius:9px;padding:11px 13px;font-size:12.5px;line-height:1.6;margin-bottom:14px">
        <b style="color:#d9a441">Editing a default.</b>
        {{ number_format($level === 'global' ? ($deps['*'] ?? 0) : ($deps[$distributor] ?? 0)) }}
        categories inherit their titles from here — changing it changes all of them.
        The preview below samples across several categories rather than one.
      </div>
    @endif

    <div class="tc-split">

      {{-- ---------------- templates ---------------- --}}
      <div style="display:flex;flex-direction:column;gap:12px">

        <div class="tc-card">
          <div class="tc-lbl">
            Display title
            @if ($sources['title_template']['own'])
              <span class="tc-pill own">set here</span>
            @else
              <span class="tc-pill inh">inherited · {{ $sources['title_template']['from'] }}</span>
            @endif
          </div>
          <input type="text" class="tc-tpl {{ $sources['title_template']['own'] ? '' : 'inh' }}"
                 wire:model.live.debounce.500ms="title_template"
                 wire:focus="focusField('title_template')">
          <div style="font-size:11.5px;opacity:.55;margin-top:6px">Short — what the cashier scans.</div>
        </div>

        <div class="tc-card">
          <div class="tc-lbl">
            Subtitle
            @if ($sources['subtitle_template']['own'])
              <span class="tc-pill own">set here</span>
            @else
              <span class="tc-pill inh">inherited · {{ $sources['subtitle_template']['from'] }}</span>
            @endif
          </div>
          <input type="text" class="tc-tpl {{ $sources['subtitle_template']['own'] ? '' : 'inh' }}"
                 wire:model.live.debounce.500ms="subtitle_template"
                 wire:focus="focusField('subtitle_template')">
          <div style="font-size:11.5px;opacity:.55;margin-top:6px">The line that confirms the right item.</div>
        </div>

        <div class="tc-card">
          <div class="tc-lbl">
            Search text
            @if ($sources['search_template']['own'])
              <span class="tc-pill own">set here</span>
            @else
              <span class="tc-pill inh">inherited · {{ $sources['search_template']['from'] }}</span>
            @endif
          </div>
          <input type="text" class="tc-tpl {{ $sources['search_template']['own'] ? '' : 'inh' }}"
                 wire:model.live.debounce.500ms="search_template"
                 wire:focus="focusField('search_template')">
          <div style="font-size:11.5px;opacity:.55;margin-top:6px">Never shown — indexed so odd searches still find it.</div>
        </div>

        {{-- ---------------- tokens ---------------- --}}
        <div class="tc-card">
          <div class="tc-lbl">
            Tokens
            <span style="font-weight:400;text-transform:none;letter-spacing:0;opacity:.7">
              click to add to <b>{{ str_replace('_template', '', $activeField) }}</b>
            </span>
          </div>

          <input type="text" wire:model.live.debounce.250ms="tokenSearch"
                 placeholder="Search tokens and attributes…"
                 style="width:100%;margin-bottom:9px;font-size:12px;padding:6px 10px;border-radius:7px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.12);color:inherit">

          <div style="font-size:11.5px;opacity:.55;line-height:1.6;margin-bottom:8px">
            Values shown are from the first sample row. Separate tokens with
            <code>|</code> to try them in order — <code>{size|attr:Labeled Size}</code>
            uses the first that has a value.
          </div>

          <div style="max-height:330px;overflow:auto">
            @forelse ($tokens as $group => $items)
              <div class="tc-grp">{{ $group }}</div>
              @foreach ($items as $t)
                <button type="button" class="tc-tok" wire:click="insertToken(@js($t['token']))">
                  <span class="k">{{ $t['token'] }}</span>
                  <span class="v {{ $t['value'] === '' ? 'none' : '' }}">
                    {{ $t['value'] === '' ? 'empty here' : \Illuminate\Support\Str::limit($t['value'], 40) }}
                  </span>
                </button>
              @endforeach
            @empty
              <div style="font-size:12px;opacity:.6;padding:6px 0">No token matches “{{ $tokenSearch }}”.</div>
            @endforelse
          </div>
        </div>
      </div>

      {{-- ---------------- preview ---------------- --}}
      <div style="display:flex;flex-direction:column;gap:12px">
        <div style="border:1px solid rgba(255,255,255,.10);border-radius:10px;overflow:hidden">
          <div style="padding:10px 13px;border-bottom:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.03);font-size:12px;display:flex;align-items:center;gap:9px;flex-wrap:wrap">
            <b>Preview</b>
            <span style="opacity:.55">{{ count($preview) }} sample rows</span>
            @if ($dupes['was'] || $dupes['now'])
              <span class="tc-pill {{ $dupes['now'] < $dupes['was'] ? 'own' : 'warn' }}">
                {{ $dupes['was'] }} &rarr; {{ $dupes['now'] }} duplicate titles
              </span>
            @endif
          </div>

          <div style="max-height:560px;overflow:auto">
            @forelse ($preview as $p)
              <div class="tc-prow">
                <span class="tc-sku">
                  {{ $p['dist'] }} · {{ $p['sku'] }}
                  @if ($level !== 'scope' && $p['category'])
                    · {{ \Illuminate\Support\Str::limit($p['category'], 40) }}
                  @endif
                </span>

                @if ($p['empty'])
                  <div class="tc-was">{{ $p['was'] }}</div>
                  <div style="color:#f87171">produces an empty title — every token in the rule is blank on this item</div>
                @elseif ($p['unchanged'])
                  <div>{{ $p['was'] }}</div>
                  <div style="opacity:.5;font-size:11.5px">unchanged — this rule resolves to what it already had</div>
                @else
                  <div class="tc-was">{{ $p['was'] }}</div>
                  <div class="tc-now">{{ $p['now'] }}</div>
                @endif
              </div>
            @empty
              <div style="padding:16px;font-size:12px;opacity:.6">
                No sample rows for this scope. Run <code>catalog:scan-titles</code> to rebuild the samples.
              </div>
            @endforelse
          </div>
        </div>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span style="font-size:11.5px;opacity:.55">Saving stores the rule; recompose rewrites stored titles.</span>
          <span style="flex:1"></span>
          @if ($level === 'scope')
            <x-filament::button size="sm" color="gray" wire:click="saveAndNext">Save &amp; next</x-filament::button>
          @endif
          <x-filament::button size="sm" color="gray" wire:click="save">Save only</x-filament::button>
          <x-filament::button size="sm" wire:click="saveAndRecompose">Save &amp; recompose</x-filament::button>
        </div>
      </div>
    </div>
  </div>
</div>

</x-filament-panels::page>
