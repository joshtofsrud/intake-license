{{-- MARKER-WEB-TEMPLATES: master admin › Site & content › Site templates. Styled on Filament's
     own colour variables (the same set as Search defaults) so it follows light and dark. --}}
@php
  use App\Support\SiteLook;
  use App\Support\ShopSearch\SearchConfig;
  $edit = $this->canEdit();
@endphp
<x-filament-panels::page>
@include('filament.pages._lcm-style')
<style>
  .stp-sw{display:inline-flex;height:14px;width:120px;border-radius:4px;overflow:hidden;box-shadow:0 0 0 1px var(--m-ring)}.stp-sw i{flex:1}
  .stp-cc{display:flex;align-items:center;gap:8px}
  .stp-cc input[type=color]{width:30px;height:28px;border:0;padding:0;background:none;cursor:pointer;border-radius:6px;box-shadow:0 0 0 1px var(--m-inring)}
  .stp-cc input[type=color]::-webkit-color-swatch-wrapper{padding:2px}.stp-cc input[type=color]::-webkit-color-swatch{border:0;border-radius:4px}
  .stp-cc .lcm-in{width:100px;font-family:ui-monospace,monospace;font-size:13px}
  .stp-fl{display:flex;flex-direction:column;gap:6px;font-weight:500;font-size:14px;color:var(--m-tx)}
  .stp-seg{display:inline-flex;border-radius:8px;box-shadow:0 0 0 1px var(--m-inring);overflow:hidden;background:var(--m-card)}
  .stp-seg button{border:0;background:transparent;color:var(--m-mu);font-weight:500;font-size:13px;padding:6px 11px;cursor:pointer}
  .stp-seg button+button{box-shadow:-1px 0 0 var(--m-inring)}
  .stp-seg button.on{background:rgba(var(--primary-500),.14);color:rgb(var(--primary-600))}.dark .stp-seg button.on{color:rgb(var(--primary-400))}
  .stp-frame{border-radius:12px;overflow:hidden;background:#000;box-shadow:0 0 0 1px var(--m-ring);margin:0 auto;width:100%;transition:width .2s}
  .stp-frame.phone{width:390px}
  .stp-chrome{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#1b1b1b;border-bottom:1px solid #2a2a2a}
  .stp-chrome i{width:9px;height:9px;border-radius:50%;background:#3a3a3a}
  .stp-chrome span{flex:1;margin-left:8px;background:#0f0f0f;border-radius:6px;font-size:11px;color:#8a8a8a;padding:4px 10px}
  .stp-scroll{height:680px;overflow:auto}
  .stp-bar{position:sticky;bottom:12px;z-index:5}
</style>

<div class="lcm">
@if(! $editing)
  {{-- ============================== list ============================== --}}
  <div style="display:flex;align-items:flex-start;gap:16px">
    <p class="mu" style="font-size:14px;margin-top:-8px;flex:1">The looks every shop can pick from in Website › Templates. A shop's own changes stay theirs; everything they haven't changed follows the template when you publish.</p>
    @if($edit)<button type="button" class="lcm-btn" wire:click="openNew">New template</button>@endif
  </div>
  <section class="lcm-sec">
    <div class="lcm-tbl"><table>
      <thead><tr><th>Template</th><th>Looks</th><th class="n">Shops using it</th><th>Can people read it?</th><th>Plans</th><th>Status</th><th>Updated</th><th></th></tr></thead>
      <tbody>
      @forelse($this->rows() as $r)
        <tr wire:key="tpl-{{ $r['key'] }}">
          <td><div style="font-weight:600">{{ $r['name'] }}</div><div class="mu sm" style="max-width:40ch">{{ $r['description'] }}</div></td>
          <td><div style="display:flex;flex-direction:column;gap:4px">
            @foreach(['dark', 'light'] as $m)<span class="stp-sw" title="{{ ucfirst($m) }}">@foreach(['bg','s1','tx','mu','ac'] as $k)<i style="background:{{ $r['modes'][$m][$k] ?? '#000' }}"></i>@endforeach</span>@endforeach
          </div></td>
          <td class="n">{{ $r['shops'] }}</td>
          <td>@if($r['fails'])<span class="lcm-badge b-bad">{{ $r['fails'] }} hard to read</span>@else<span class="lcm-badge b-ok">All pairs read well</span>@endif</td>
          <td class="sm">{{ collect($r['plans'])->map(fn ($p) => SearchConfig::TIERS[$p] ?? $p)->join(', ') ?: 'None' }}</td>
          <td><span class="lcm-badge {{ $r['status'] === 'Published' ? 'b-ok' : ($r['status'] === 'Draft' ? 'b-gray' : 'b-warn') }}">{{ $r['status'] }}</span></td>
          <td class="mu sm">{{ $r['updated']?->diffForHumans() }}</td>
          <td class="n"><button type="button" class="lcm-btn g s" wire:click="edit('{{ $r['key'] }}')">{{ $edit ? 'Edit' : 'View' }}</button></td>
        </tr>
      @empty
        <tr><td colspan="8" class="mu">No templates yet.</td></tr>
      @endforelse
      </tbody>
    </table></div>
  </section>
  <div class="lcm-note"><b>What publishing does</b><div>Shops using a template see a published change on their live site straight away, except for any piece they changed themselves. Drafts are invisible to shops. A template can't be published while any colour pair is hard to read.</div></div>

@else
  {{-- ============================== editor ============================== --}}
  @php $t = $this->template(); $fails = $this->fails(); $using = $this->shopsUsing(); $usingN = $this->shopsUsingCount(); $dirty = $this->isDirty(); @endphp
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:-8px">
    <button type="button" class="lcm-btn g s" wire:click="back">← Site templates</button>
    @if($t)<span class="lcm-badge {{ ! $t->isPublished() ? 'b-gray' : ($t->hasDraft() ? 'b-warn' : 'b-ok') }}">{{ ! $t->isPublished() ? 'Draft' : ($t->hasDraft() ? 'Published · unpublished changes' : 'Published') }}</span>@endif
    <span style="flex:1"></span>
    <span class="mu sm">{{ $usingN ? 'Used by ' . $usingN . ' shop' . ($usingN === 1 ? '' : 's') . ': ' . implode(', ', $using) . ($usingN > count($using) ? '…' : '') : 'No shops use it yet' }}</span>
  </div>

  <div class="lcm-tabs" role="tablist">
    @foreach(['details' => 'Details', 'colours' => 'Colours', 'pieces' => 'Pieces', 'preview' => 'Preview'] as $k => $l)
      <button type="button" wire:click="show('{{ $k }}')" class="{{ $tab === $k ? 'on' : '' }}">{{ $l }}@if($k === 'colours' && $fails) <span class="lcm-badge b-bad" style="margin-left:6px">{{ count($fails) }}</span>@endif</button>
    @endforeach
  </div>

  @if($tab === 'details')
    <section class="lcm-sec">
      <header><h3>Details</h3><p>What shops see when they pick a template.</p></header>
      <div class="lcm-sec p" style="box-shadow:none;display:grid;gap:16px;max-width:640px">
        <label class="stp-fl">Name<input class="lcm-in" style="width:100%" wire:model.live.debounce.400ms="work.name" maxlength="80" @disabled(! $edit)></label>
        <label class="stp-fl">Description<textarea class="lcm-ta" rows="3" wire:model.live.debounce.400ms="work.description" maxlength="400" @disabled(! $edit)></textarea><span class="mu sm" style="font-weight:400">One or two sentences, shown under the name in the shop's template list.</span></label>
        <div class="stp-fl">Plans that can pick it
          <div style="display:flex;gap:22px;margin-top:4px;font-weight:400">
            @foreach(SearchConfig::TIERS as $tier => $tl)
              <label style="display:flex;gap:8px;align-items:center"><label class="lcm-tg"><input type="checkbox" wire:model.live="work.plans.{{ $tier }}" @disabled(! $edit)><i></i></label>{{ $tl }}</label>
            @endforeach
          </div>
          <span class="mu sm" style="font-weight:400">A shop that already uses it keeps it if its plan changes; it just can't switch back once it picks another.</span>
        </div>
      </div>
    </section>
  @endif

  @if($tab === 'colours')
    <section class="lcm-sec">
      <header><h3>Colours</h3><p>Every template has a dark and a light version. Each shop picks which one its visitors see.</p></header>
      <div class="lcm-tbl"><table>
        <thead><tr><th>Role</th><th>Used for</th><th>Dark</th><th>Light</th></tr></thead>
        <tbody>
        @foreach(SiteLook::ROLES as $r => [$label, $help])
          <tr wire:key="role-{{ $r }}">
            <td style="font-weight:500">{{ $label }}</td><td class="mu sm">{{ $help }}</td>
            @foreach(['dark', 'light'] as $m)
              <td><span class="stp-cc">
                <input type="color" value="{{ $work['modes'][$m][$r] }}" wire:model.live.debounce.300ms="work.modes.{{ $m }}.{{ $r }}" @disabled(! $edit)>
                <input class="lcm-in" wire:model.live.debounce.500ms="work.modes.{{ $m }}.{{ $r }}" maxlength="7" @disabled(! $edit)>
              </span></td>
            @endforeach
          </tr>
        @endforeach
        </tbody>
      </table></div>
    </section>
    <section class="lcm-sec">
      <header><h3>Can people read it?</h3><p>Checked against the accessibility standard: 4.5 to 1 for text, 3 to 1 for the accent on the page. Publishing is blocked until every pair reads well.</p></header>
      <div class="lcm-tbl"><table>
        <thead><tr><th>Pair</th><th>Dark</th><th>Light</th></tr></thead>
        <tbody>
        @foreach(SiteLook::PAIRS as [$a, $b, $label, $need])
          <tr>
            <td>{{ $label }}<div class="mu sm">needs {{ $need }} to 1</div></td>
            @foreach(['dark', 'light'] as $m)
              @php $x = SiteLook::hex($work['modes'][$m][$a] ?? null); $y = SiteLook::hex($work['modes'][$m][$b] ?? null); $ra = ($x && $y) ? SiteLook::ratio($x, $y) : 0; @endphp
              <td><span class="lcm-badge {{ $ra >= $need ? 'b-ok' : 'b-bad' }}">{{ $ra >= $need ? 'Reads well' : 'Hard to read' }}</span> <span class="mu sm">{{ number_format($ra, 1) }} to 1</span></td>
            @endforeach
          </tr>
        @endforeach
        </tbody>
      </table></div>
    </section>
  @endif

  @if($tab === 'pieces')
    <section class="lcm-sec">
      <header><h3>Pieces</h3><p>The template's choice for each piece. Shops can change any of these for themselves.</p></header>
      <div class="b">
        @foreach(SiteLook::PIECES as $k => [$label, $help, $opts])
          <div class="lcm-row" wire:key="piece-{{ $k }}">
            <div><div class="t">{{ $label }}</div>@if($help)<div class="d">{{ $help }}</div>@endif</div>
            <div class="lcm-ctl">
              <select class="lcm-sel" style="min-width:210px" wire:model.live="work.pieces.{{ $k }}" @disabled(! $edit)>
                @foreach(($opts ?? array_combine(SiteLook::FONTS, SiteLook::FONTS)) as $v => $l)
                  <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
              </select>
            </div>
          </div>
        @endforeach
      </div>
    </section>
  @endif

  @if($tab === 'preview')
    <div x-data="{ page: 'home', mode: 'dark', phone: false, draw() { window.SiteSample && window.SiteSample.render(this.$refs.site, @js($work), this.page, this.mode) } }"
         x-init="draw(); $watch('page', () => draw()); $watch('mode', () => draw())" wire:key="pv-{{ md5(json_encode($work)) }}" style="display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <span class="stp-seg"><button type="button" :class="page==='home'&&'on'" @click="page='home'">Homepage</button><button type="button" :class="page==='shop'&&'on'" @click="page='shop'">Shop</button><button type="button" :class="page==='search'&&'on'" @click="page='search'">Search</button></span>
        <span class="stp-seg"><button type="button" :class="mode==='dark'&&'on'" @click="mode='dark'">Dark</button><button type="button" :class="mode==='light'&&'on'" @click="mode='light'">Light</button></span>
        <span class="stp-seg"><button type="button" :class="!phone&&'on'" @click="phone=false">Desktop</button><button type="button" :class="phone&&'on'" @click="phone=true">Phone</button></span>
        <span style="flex:1"></span><span class="mu sm">{{ $work['name'] }}{{ $dirty ? ' · unsaved changes shown' : '' }}</span>
      </div>
      <div class="stp-frame" :class="phone&&'phone'"><div class="stp-chrome"><i></i><i></i><i></i><span>sample-shop.intake.works</span></div><div class="stp-scroll"><div x-ref="site"></div></div></div>
      <div class="mu sm">Sample shop content. A shop's own preview uses its real pages, products and logo.</div>
    </div>
  @endif

  <section class="lcm-sec stp-bar">
    <div class="lcm-save">
      <span class="sm" style="color:{{ $dirty ? 'rgb(var(--warning-500))' : 'var(--m-mu)' }}">{{ $dirty ? 'Unsaved changes' : 'No unsaved changes' }}</span>
      <span style="flex:1"></span>
      @if($fails)<span class="sm" style="color:rgb(var(--danger-500))">Can't publish: {{ count($fails) }} pair{{ count($fails) === 1 ? '' : 's' }} hard to read (Colours tab)</span>@endif
      @if($edit)
        @if($t && $t->hasDraft() && ! $dirty)<button type="button" class="lcm-btn g" x-on:click="$dispatch('open-modal', { id: 'st-throw' })">Throw away draft</button>@endif
        <button type="button" class="lcm-btn g" wire:click="discard" @disabled(! $dirty)>Discard</button>
        <button type="button" class="lcm-btn g" wire:click="saveDraft" @disabled(! $dirty)>Save draft</button>
        <button type="button" class="lcm-btn" wire:click="askPublish" @disabled($fails || (! $dirty && $t && $t->isPublished() && ! $t->hasDraft()))>{{ $t && $t->isPublished() ? 'Publish changes' : 'Publish' }}</button>
      @endif
    </div>
  </section>
@endif
</div>

@include('filament.pages._site-sample')

<x-filament::modal id="st-new" width="md">
  <x-slot name="heading">New template</x-slot>
  <div class="lcm lcm-modal" style="gap:0">
    <label>Name</label><input class="lcm-in" wire:model="new.name" maxlength="60" placeholder="e.g. Trailhead">
    @error('new.name')<div class="lcm-err">{{ $message }}</div>@enderror
    <label>Start from</label>
    <select class="lcm-sel" wire:model="new.from">
      @foreach(\App\Models\WebTemplate::orderBy('sort')->get() as $tt)<option value="{{ $tt->key }}">A copy of {{ $tt->name }}</option>@endforeach
    </select>
    @error('new.from')<div class="lcm-err">{{ $message }}</div>@enderror
    <p class="mu sm" style="margin-top:10px">It starts as a draft. Shops can't see it until you publish.</p>
  </div>
  <x-slot name="footerActions">
    <x-filament::button color="gray" x-on:click="close()">Cancel</x-filament::button>
    <x-filament::button wire:click="create">Create draft</x-filament::button>
  </x-slot>
</x-filament::modal>

<x-filament::modal id="st-throw" width="md">
  <x-slot name="heading">Throw away the unpublished changes?</x-slot>
  <p class="text-sm" style="color:rgb(var(--gray-500))">Shops keep seeing the published version. The editor goes back to what's published.</p>
  <x-slot name="footerActions">
    <x-filament::button color="gray" x-on:click="close()">Cancel</x-filament::button>
    <x-filament::button color="danger" wire:click="discardDraft" x-on:click="close()">Throw away</x-filament::button>
  </x-slot>
</x-filament::modal>

<x-filament::modal id="st-publish" width="md">
  <x-slot name="heading">Publish {{ $work['name'] ?? '' }}?</x-slot>
  @php $pu = $editing ? $this->shopsUsingCount() : 0; @endphp
  <p class="text-sm" style="color:rgb(var(--gray-500))">
    @if($pu)
      This changes the live site of {{ $pu }} shop{{ $pu === 1 ? '' : 's' }} straight away ({{ implode(', ', $editing ? $this->shopsUsing() : []) }}). Pieces a shop changed for itself stay as they are.
    @else
      No shops use it yet. Once published, shops on the chosen plans can pick it in Website › Templates.
    @endif
  </p>
  <x-slot name="footerActions">
    <x-filament::button color="gray" x-on:click="close()">Cancel</x-filament::button>
    <x-filament::button wire:click="publish">Publish</x-filament::button>
  </x-slot>
</x-filament::modal>
</x-filament-panels::page>
