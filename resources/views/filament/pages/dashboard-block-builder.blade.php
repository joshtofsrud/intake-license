<x-filament-panels::page>
@include('filament.partials.dashboards-style')
<style>
  .bb { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1.05fr); gap:28px; align-items:start; }
  @media (max-width:1100px) { .bb { grid-template-columns:1fr; } }
  .bb-step { display:grid; grid-template-columns:26px 1fr; gap:12px; padding:16px 0; border-bottom:1px solid var(--sx-line); }
  .bb-step .no { width:22px; height:22px; border-radius:50%; border:1px solid var(--sx-line-2); display:grid; place-items:center; font-size:11.5px; color:var(--sx-dim); }
  .bb-step h4 { font-size:14px; font-weight:600; color:var(--db-txt); margin:1px 0 4px; }
  .bb-step .q { font-size:12.5px; color:var(--sx-faint); margin-bottom:10px; line-height:1.5; }
  .bb-seg { display:inline-flex; border:1px solid var(--sx-line-2); border-radius:8px; overflow:hidden; flex-wrap:wrap; }
  .bb-seg button { background:none; border:0; padding:6px 12px; font-size:13px; color:var(--sx-dim); cursor:pointer; }
  .bb-seg button + button { border-left:1px solid var(--sx-line-2); }
  .bb-seg button.on { background:var(--sx-vsoft); color:var(--db-txt); font-weight:500; }
  .bb-seg button[disabled] { opacity:.4; cursor:default; }
  .bb-src { display:grid; gap:6px; }
  .bb-src button { display:flex; gap:10px; align-items:flex-start; text-align:left; background:none; border:1px solid var(--sx-line); border-radius:8px; padding:9px 12px; cursor:pointer; color:inherit; }
  .bb-src button.on { border-color:var(--sx-violet); background:var(--sx-vsoft); }
  .bb-src .rb { width:14px; height:14px; border-radius:50%; border:1.5px solid var(--sx-line-2); margin-top:2px; flex:none; }
  .bb-src button.on .rb { border:4px solid var(--sx-violet); }
  .bb-src .t { display:block; font-size:13.5px; font-weight:500; color:var(--db-txt); }
  .bb-src .s { display:block; font-size:12px; color:var(--sx-faint); }
  .bb-rule { display:grid; grid-template-columns:1fr 110px 1fr 26px; gap:8px; margin-bottom:8px; align-items:center; }
  .bb-rule .x { background:none; border:0; color:var(--sx-faint); font-size:18px; cursor:pointer; }
  .bb-row2 { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:10px; }
  .bb-chk { display:inline-flex; gap:8px; align-items:center; font-size:13px; color:var(--sx-dim); cursor:pointer; }
  .bb-chk input { accent-color:#8b5cf6; }
  .bb-pv-h { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:10px; font-size:12.5px; color:var(--sx-faint); }
  .bb-frame { border:1px solid var(--sx-line-2); border-radius:10px; background:#141416; padding:22px 26px; overflow:hidden; color:#ececef;
    --ia-text-muted:#8b8b93; --ia-border:rgba(255,255,255,.08); --ia-accent:#f5a524; --ia-accent-soft:rgba(245,165,36,.08); --ia-hover:rgba(255,255,255,.03); }
  .bb-frame .ia-btn { display:inline-flex; padding:8px 14px; border-radius:8px; font-size:14px; font-weight:500; text-decoration:none; }
  .bb-frame .ia-btn--primary { background:#f5a524; color:#1a1206; }
  .bb-frame .cb { margin:0; }
  .bb-ghost { border:1px dashed var(--sx-line-2); border-radius:8px; padding:18px; font-size:13px; color:var(--sx-faint); }
  .bb-say { font-size:13.5px; line-height:1.55; color:var(--sx-dim); padding:14px 0 6px; }
  .bb-facts { display:grid; grid-template-columns:110px 1fr; gap:6px 12px; font-size:12.5px; padding:6px 0 0; }
  .bb-facts span:nth-child(odd) { color:var(--sx-faint); }
  .bb-top { display:flex; gap:10px; align-items:center; flex-wrap:wrap; padding-bottom:12px; }
</style>

<div class="sx-root">
  <div class="db-note" style="margin-bottom:8px"><a class="db-lnk" href="{{ \App\Filament\Pages\DashboardBlocks::getUrl() }}">Blocks</a> / {{ $isNew ? 'New block' : $name }}</div>

  <div class="db-legend">
    @if ($isNew)
      <b>A new block isn't on any dashboard yet.</b> Save it, then add it to a template in the editor. Shops see it once that template is published.
    @elseif ($usage)
      <b>This block is on {{ collect($usage)->map(fn ($u) => $u['name'] . ($u['live'] ? '' : ' (draft)'))->implode(', ') }}.</b>
      @if ($liveShops) Saving changes it on up to {{ number_format($liveShops) }} {{ \Illuminate\Support\Str::plural('shop', $liveShops) }}' dashboards on their next load, without republishing. @else No live dashboard shows it yet. @endif
    @else
      <b>This block isn't on any template.</b> Add it to one in the editor.
    @endif
    The preview uses the chosen shop's real figures; nothing is saved until you press Save.
  </div>

  <div class="bb-top">
    <input type="text" class="db-in" style="max-width:340px;font-size:15px;font-weight:600" wire:model.live.debounce.400ms="name" maxlength="80" placeholder="Name this block" @disabled(! $canEdit)>
    @if (! $isNew && $dirty)<span class="db-tag pend">Unsaved changes</span>@endif
    <span style="margin-left:auto"></span>
    @if (! $isNew)
      <a class="db-btn" href="{{ \App\Filament\Pages\DashboardPreview::getUrl(['b' => $k, 'w' => $width]) }}" @if ($dirty) title="Shows the saved block; save to preview your changes" @endif>Preview on a dashboard</a>
    @endif
    <a class="db-btn" href="{{ \App\Filament\Pages\DashboardBlocks::getUrl() }}">{{ $dirty ? 'Cancel' : 'Back' }}</a>
    @if ($canEdit)
      <button type="button" class="db-btn p" wire:click="save" @disabled(! $dirty || trim($name) === '')
        @if (! $isNew && $liveShops) data-confirm="Save changes to {{ $name }}?&#10;It changes on up to {{ number_format($liveShops) }} {{ \Illuminate\Support\Str::plural('shop', $liveShops) }}' dashboards on their next load." @endif>{{ $isNew ? 'Save block' : 'Save changes' }}</button>
    @endif
  </div>

  <div class="bb">
    <div>
      <div class="bb-step"><span class="no">1</span><div>
        <h4>What kind of block</h4>
        <div class="bb-seg">
          @foreach (['data' => 'Shows data', 'action' => 'A button', 'note' => 'A note to shops'] as $kk => $kl)
            <button type="button" class="{{ $kind === $kk ? 'on' : '' }}" wire:click="$set('kind', '{{ $kk }}')" @disabled(! $canEdit)>{{ $kl }}</button>
          @endforeach
        </div>
      </div></div>

      @if ($kind === 'data')
        <div class="bb-step"><span class="no">2</span><div>
          <h4>Reads</h4>
          <div class="q">Every block reads only the shop it's on. There's no way to see another shop's records.</div>
          <div class="bb-src">
            @foreach ($sources as $sk => $s)
              @php $sGate = \App\Support\SectionRegistry::all()[$s['area']]['gate'] ?? null;
                   $sNeeds = ['retail_enabled' => 'Retail', 'rentals_visible' => 'Rentals', 'classes_active' => 'Classes', 'gift_cards_visible' => 'Gift cards', 'multi_location_active' => 'more than one location'][$sGate] ?? null; @endphp
              <button type="button" class="{{ $source === $sk ? 'on' : '' }}" wire:click="setSource('{{ $sk }}')" @disabled(! $canEdit)>
                <span class="rb"></span><span><span class="t">{{ $s['label'] }}@if ($sNeeds) <span class="db-tag draft" style="margin-left:4px">Shops with {{ $sNeeds }}</span>@endif</span><span class="s">{{ $s['desc'] }} · seen by people who can open {{ \App\Support\SectionRegistry::all()[$s['area']]['label'] ?? $s['area'] }}</span></span>
              </button>
            @endforeach
          </div>
        </div></div>

        <div class="bb-step"><span class="no">3</span><div>
          <h4>Only include</h4>
          <div class="q">{{ $src['date'] ? 'Leave empty to include everything in the period.' : 'Leave empty to include every record.' }}</div>
          @foreach ($rules as $i => $r)
            @php $fdef = $src['fields'][$r[0]] ?? null; @endphp
            @if ($fdef)
              <div class="bb-rule" wire:key="rule-{{ $i }}-{{ $r[0] }}">
                <select class="db-in" wire:change="ruleField({{ $i }}, $event.target.value)" @disabled(! $canEdit)>
                  @foreach ($src['fields'] as $fk => $fd)<option value="{{ $fk }}" @selected($fk === $r[0])>{{ $fd['label'] }}</option>@endforeach
                </select>
                <select class="db-in" wire:model.live="rules.{{ $i }}.1" @disabled(! $canEdit)>
                  @if ($fdef['type'] === 'enum')
                    <option value="is">is</option><option value="is_not">is not</option>
                  @else
                    <option value="more">is over</option><option value="less">is under</option>
                  @endif
                </select>
                @if ($fdef['type'] === 'enum')
                  <select class="db-in" wire:model.live="rules.{{ $i }}.2" @disabled(! $canEdit)>
                    @foreach ($fdef['options'] as $ok => $ol)<option value="{{ $ok }}">{{ $ol }}</option>@endforeach
                  </select>
                @else
                  <input type="number" min="0" step="{{ $fdef['type'] === 'money' ? '0.01' : '1' }}" class="db-in" placeholder="{{ $fdef['type'] === 'money' ? '$ amount' : 'number' }}" wire:model.live.debounce.400ms="rules.{{ $i }}.2" @disabled(! $canEdit)>
                @endif
                <button type="button" class="x" wire:click="removeRule({{ $i }})" aria-label="Remove rule" @disabled(! $canEdit)>×</button>
              </div>
            @endif
          @endforeach
          @if ($canEdit && count($rules) < 6)<button type="button" class="db-lnk" wire:click="addRule">+ Add a rule</button>@endif
          @if (count($rules) !== count($cfg['rules']))<div class="db-note db-warn" style="margin-top:6px">A rule without an amount is left out until it has one.</div>@endif
        </div></div>

        @php $moneyFields = collect($src['fields'])->filter(fn ($f) => in_array($f['type'], ['money', 'number'], true)); @endphp
        <div class="bb-step"><span class="no">4</span><div>
          <h4>Shows</h4>
          <div class="bb-seg" style="margin-bottom:10px">
            @foreach (\App\Support\Dashboard\CustomBlocks::MEASURES as $mk => $ml)
              <button type="button" class="{{ $cfg['measure'] === $mk ? 'on' : '' }}" wire:click="$set('measure', '{{ $mk }}')" @disabled(! $canEdit || (in_array($mk, ['sum', 'avg'], true) && $moneyFields->isEmpty()))>{{ $ml }}</button>
            @endforeach
          </div>
          @if (in_array($cfg['measure'], ['sum', 'avg'], true))
            <div class="bb-row2" style="margin-top:0;margin-bottom:10px">
              <select class="db-in" style="max-width:240px" wire:model.live="field" @disabled(! $canEdit)>
                @foreach ($moneyFields as $fk => $fd)<option value="{{ $fk }}">{{ $fd['label'] }}</option>@endforeach
              </select>
            </div>
          @endif
          <div class="bb-row2">
            <select class="db-in" style="max-width:200px" wire:model.live="period" @disabled(! $canEdit)>
              @foreach ($src['periods'] as $pk)<option value="{{ $pk }}">{{ \App\Support\Dashboard\CustomBlocks::PERIODS[$pk] }}</option>@endforeach
            </select>
            @if ($cfg['measure'] !== 'list' && $src['date'] && ! in_array($cfg['period'], ['all_time', 'now'], true))
              <label class="bb-chk"><input type="checkbox" wire:model.live="compare" @disabled(! $canEdit)> Compare with the period before</label>
            @endif
          </div>
          @if (in_array($source, ['appointments', 'rentals', 'class_sessions'], true))<div class="db-note" style="margin-top:6px">{{ $src['label'] }} count on the day they're booked for, so This week and Next 7 days include what's ahead.</div>@endif
          @if (! $src['date'])<div class="db-note" style="margin-top:6px">{{ $src['label'] }} have no period: the block shows them as they are when the dashboard opens.</div>@endif
        </div></div>

        <div class="bb-step"><span class="no">5</span><div>
          <h4>Looks like</h4>
          <div class="bb-seg">
            @php $okDisplays = \App\Support\Dashboard\CustomBlocks::displaysFor($cfg['measure'], $cfg['period'], $src['date'] !== null); @endphp
            @foreach (\App\Support\Dashboard\CustomBlocks::DISPLAYS as $dk => $dl)
              @php $ok = in_array($dk, $okDisplays, true); @endphp
              @continue(in_array($dk, ['rows', 'table'], true) !== ($cfg['measure'] === 'list'))
              <button type="button" class="{{ $cfg['display'] === $dk ? 'on' : '' }}" wire:click="$set('display', '{{ $dk }}')" @disabled(! $canEdit || ! $ok)>{{ $dl }}</button>
            @endforeach
          </div>
          @if ($cfg['display'] === 'progress')
            <div class="bb-row2">
              <span class="db-note">Goal</span>
              <input type="number" min="0" step="{{ \App\Support\Dashboard\CustomBlocks::isMoney($cfg) ? '0.01' : '1' }}" class="db-in" style="max-width:160px"
                placeholder="{{ \App\Support\Dashboard\CustomBlocks::isMoney($cfg) ? '$ amount' : 'number' }}" wire:model.live.debounce.400ms="goal" @disabled(! $canEdit)>
              @if (! $cfg['goal'])<span class="db-note db-warn">Shows as a plain number until it has a goal.</span>@endif
            </div>
          @endif
          <div class="db-note" style="margin-top:6px">{{ $cfg['measure'] === 'list' ? 'Shows up to six records, each opening its own page.' : 'Trend and bars draw one point a day across the period, so they need a period of more than a day. Progress needs a goal, the same for every shop.' }}</div>
        </div></div>

        <div class="bb-step"><span class="no">6</span><div>
          <h4>Line under the title <span class="db-sub" style="display:inline;font-weight:400">optional</span></h4>
          <input type="text" class="db-in" maxlength="140" wire:model.live.debounce.400ms="line" placeholder="e.g. Jobs that are done but not paid for" @disabled(! $canEdit)>
        </div></div>

      @elseif ($kind === 'action')
        <div class="bb-step"><span class="no">2</span><div>
          <h4>The button opens</h4>
          <div class="q">Pages the app already has. The block only shows to people whose role can open that page, at shops that have it.</div>
          <div class="bb-src">
            @foreach (\App\Support\Dashboard\CustomBlocks::ACTIONS as $ak => $a)
              @php $aSec = \App\Support\SectionRegistry::sectionForRoute($a['route']); @endphp
              <button type="button" class="{{ $action === $ak ? 'on' : '' }}" wire:click="$set('action', '{{ $ak }}')" @disabled(! $canEdit)>
                <span class="rb"></span><span><span class="t">{{ $a['label'] }}</span><span class="s">Seen by people who can open {{ \App\Support\SectionRegistry::all()[$aSec]['label'] ?? 'it' }}</span></span>
              </button>
            @endforeach
          </div>
        </div></div>
        <div class="bb-step"><span class="no">3</span><div>
          <h4>Line under the title <span class="db-sub" style="display:inline;font-weight:400">optional</span></h4>
          <input type="text" class="db-in" maxlength="140" wire:model.live.debounce.400ms="line" @disabled(! $canEdit)>
        </div></div>

      @else
        <div class="bb-step"><span class="no">2</span><div>
          <h4>The note</h4>
          <div class="q">Plain text, for a heads-up you want every shop on a template to read. Everyone at the shop sees it.</div>
          <textarea class="db-in" rows="5" maxlength="600" wire:model.live.debounce.400ms="text" @disabled(! $canEdit)></textarea>
        </div></div>
        <div class="bb-step"><span class="no">3</span><div>
          <h4>Show until <span class="db-sub" style="display:inline;font-weight:400">optional</span></h4>
          <div class="q">After this day it stops showing by itself. Leave empty to show it until you remove it.</div>
          <input type="date" class="db-in" style="max-width:200px" wire:model.live="until" @disabled(! $canEdit)>
        </div></div>
      @endif
    </div>

    <div>
      <div class="bb-pv-h">
        <span>Preview at</span>
        <select class="db-in" style="max-width:240px" wire:model.live="shop">
          @foreach ($shops as $sid => $sn)<option value="{{ $sid }}">{{ $sn }}</option>@endforeach
        </select>
        <span style="margin-left:auto">Width</span>
        <span class="bb-seg">
          @foreach ([4 => '⅓', 6 => '½', 8 => '⅔', 12 => 'Full'] as $w => $wl)
            <button type="button" class="{{ $width === $w ? 'on' : '' }}" wire:click="$set('width', {{ $w }})">{{ $wl }}</button>
          @endforeach
        </span>
      </div>
      <div class="bb-frame" style="max-width:{{ round(1040 * $width / 12) + 52 }}px">
        @if ($previewError)
          <div class="bb-ghost">{{ $previewError }}</div>
        @elseif ($lacks)
          <div class="bb-ghost"><b>{{ $shops[$shop] ?? 'This shop' }}</b> doesn't have {{ $lacks }} turned on, so it never sees this block.</div>
        @elseif ($preview)
          @include('tenant.dashboard.blocks._custom-style')
          @include('tenant.dashboard.blocks.custom', ['cbPreview' => $preview, 'dashBlock' => ['b' => 'preview']])
          @if ($kind === 'note' && $cfg['text'] === '')<div class="bb-ghost">Write the note to see it here.</div>@endif
        @endif
      </div>
      <div class="db-note" style="margin-top:6px">Drawn at its real width on a desktop dashboard. Links don't open from the preview.</div>
      <div class="bb-say">{{ $sentence }}</div>
      <div class="bb-facts">
        @if ($kind === 'data')
          <span>Reads</span><span>{{ $src['label'] }}, this shop only</span>
          <span>Updates</span><span>Worked out when someone opens the dashboard, then kept for 5 minutes for that shop</span>
          <span>Clicking it</span><span>{{ $cfg['measure'] === 'list' ? 'Each record opens its own page, and the' : 'The' }} header links to {{ $src['opens_label'] }}</span>
        @endif
        <span>Seen by</span><span>{{ $who ? 'People whose role can open ' . $who : 'Everyone at the shop' }}</span>
      </div>
    </div>
  </div>
</div>
</x-filament-panels::page>
