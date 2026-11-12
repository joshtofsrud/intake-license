{{-- MARKER-LAUNCH-CHECKLIST: master admin › Launch checklists. Styled on Filament's own colour
     variables so it follows the panel's light and dark modes. --}}
@php
  use App\Models\Launch\LaunchStep;
  use App\Support\Launch\Launch;
  use App\Support\Launch\LaunchDefaults;
  $edit = $this->canEdit();
  $levels = LaunchStep::LEVELS;
  $tiers = LaunchStep::TIERS;
  $badge = fn (string $s) => match ($s) {
    'ok', 'na', 'confirmed' => ['b-ok', match ($s) { 'na' => 'Not needed', 'confirmed' => 'Confirmed', default => 'Passing' }],
    'warn' => ['b-warn', 'Needs a look'], 'bad' => ['b-bad', 'Not done'], 'todo' => ['b-gray', 'Not confirmed'],
    'skipped' => ['b-gray', 'Skipped'], 'waived' => ['b-warn', 'Waived by Intake'], default => ['b-gray', $s],
  };
@endphp
<x-filament-panels::page>
<style>
  .lcm{--m-card:#fff;--m-ring:rgba(9,9,11,.08);--m-tx:rgb(var(--gray-950));--m-mu:rgb(var(--gray-500));--m-row:rgba(9,9,11,.06);--m-head:rgb(var(--gray-50));--m-in:#fff;--m-inring:rgba(9,9,11,.18);display:flex;flex-direction:column;gap:24px}
  .dark .lcm{--m-card:rgb(var(--gray-900));--m-ring:rgba(255,255,255,.1);--m-tx:#fff;--m-mu:rgb(var(--gray-400));--m-row:rgba(255,255,255,.06);--m-head:rgba(255,255,255,.04);--m-in:rgba(255,255,255,.05);--m-inring:rgba(255,255,255,.2)}
  .lcm-tabs{display:flex;gap:4px;overflow-x:auto;padding:4px;border-radius:12px;background:var(--m-card);box-shadow:0 0 0 1px var(--m-ring);width:max-content;max-width:100%}
  .lcm-tabs button,.lcm-tabs a{border:0;background:transparent;color:var(--m-mu);font-weight:500;font-size:14px;padding:7px 12px;border-radius:8px;cursor:pointer;white-space:nowrap}
  .lcm-tabs button:hover,.lcm-tabs a:hover{background:var(--m-row)}
  .lcm-tabs .on{background:rgba(var(--primary-500),.12);color:rgb(var(--primary-600))}.dark .lcm-tabs .on{color:rgb(var(--primary-400))}
  .lcm-bar2{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
  .lcm-sec{background:var(--m-card);border-radius:12px;box-shadow:0 0 0 1px var(--m-ring),0 1px 2px rgba(0,0,0,.05)}
  .lcm-sec>header{padding:14px 22px;border-bottom:1px solid var(--m-row)}.lcm-sec>header h3{font-weight:600;font-size:16px;line-height:24px;color:var(--m-tx)}.lcm-sec>header p{font-size:13.5px;color:var(--m-mu);margin-top:2px}
  .lcm-sec .b{padding:6px 22px}.lcm-sec.p{padding:16px 22px}
  .lcm-row{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:14px 0;border-bottom:1px solid var(--m-row)}.lcm-row:last-child{border:0}
  .lcm-row .t{font-size:14px;font-weight:500;color:var(--m-tx)}.lcm-row .d{font-size:13px;color:var(--m-mu);margin-top:2px;max-width:62ch}
  .lcm-ctl{display:flex;gap:12px;align-items:center;flex:none;font-size:13.5px;color:var(--m-tx)}
  .lcm-tbl{overflow-x:auto}.lcm-tbl table{width:100%;border-collapse:collapse;font-size:14px;color:var(--m-tx)}
  .lcm-tbl th{text-align:left;font-weight:600;font-size:13.5px;padding:12px;background:var(--m-head);white-space:nowrap}.lcm-tbl td{padding:10px 12px;border-top:1px solid var(--m-row);vertical-align:middle}
  .lcm-tbl th:first-child,.lcm-tbl td:first-child{padding-left:22px}.lcm-tbl th:last-child,.lcm-tbl td:last-child{padding-right:22px}
  .lcm-tbl .n{text-align:right;white-space:nowrap}.lcm .mu{color:var(--m-mu)}.lcm .sm{font-size:13px}
  .lcm-in,.lcm-sel,.lcm-ta{background:var(--m-in);color:var(--m-tx);border:0;border-radius:8px;box-shadow:inset 0 0 0 1px var(--m-inring);padding:6px 10px;font-size:14px;outline:none;max-width:100%}
  .lcm-sel{padding-right:30px;appearance:none;-webkit-appearance:none;background-repeat:no-repeat;background-position:right .45rem center;background-size:1.1em}.lcm-sel option{background:rgb(var(--gray-900));color:#fff}
  .lcm-in:focus,.lcm-sel:focus,.lcm-ta:focus{box-shadow:inset 0 0 0 2px rgb(var(--primary-500))}
  .lcm-ta,.lcm-modal .lcm-in,.lcm-modal .lcm-sel{width:100%;display:block}
  .lcm-tg{position:relative;display:inline-block;width:44px;height:24px;flex:none}.lcm-tg input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer;z-index:1}.lcm-tg i{position:absolute;inset:0;border-radius:99px;background:rgb(var(--gray-300));transition:.15s}.dark .lcm-tg i{background:rgb(var(--gray-700))}.lcm-tg i:before{content:'';position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.3);transition:.15s}.lcm-tg input:checked+i{background:rgb(var(--primary-600))}.lcm-tg input:checked+i:before{transform:translateX(20px)}.lcm-tg input:disabled+i{opacity:.4}
  .lcm-btn{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:14px;border-radius:8px;padding:6px 12px;cursor:pointer;border:0;background:rgb(var(--primary-600));color:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2)}.lcm-btn:hover{background:rgb(var(--primary-500))}
  .lcm-btn.g{background:var(--m-card);color:var(--m-tx);box-shadow:0 0 0 1px var(--m-inring)}.lcm-btn.g:hover{background:var(--m-row)}
  .lcm-btn.r{background:transparent;color:rgb(var(--danger-500));box-shadow:0 0 0 1px rgba(var(--danger-500),.5)}.lcm-btn.r:hover{background:rgba(var(--danger-500),.1)}
  .lcm-btn.s{padding:3px 9px;font-size:13px}.lcm-btn:disabled{opacity:.45;cursor:default}
  .lcm-badge{display:inline-flex;align-items:center;font-weight:500;font-size:12px;padding:2px 8px;border-radius:6px;white-space:nowrap}
  .b-ok{background:rgba(var(--success-500),.12);color:rgb(var(--success-600));box-shadow:inset 0 0 0 1px rgba(var(--success-600),.25)}.dark .b-ok{color:rgb(var(--success-400))}
  .b-bad{background:rgba(var(--danger-500),.12);color:rgb(var(--danger-600));box-shadow:inset 0 0 0 1px rgba(var(--danger-600),.25)}.dark .b-bad{color:rgb(var(--danger-400))}
  .b-warn{background:rgba(var(--warning-500),.12);color:rgb(var(--warning-700));box-shadow:inset 0 0 0 1px rgba(var(--warning-600),.3)}.dark .b-warn{color:rgb(var(--warning-400))}
  .b-pri{background:rgba(var(--primary-500),.12);color:rgb(var(--primary-600));box-shadow:inset 0 0 0 1px rgba(var(--primary-600),.25)}.dark .b-pri{color:rgb(var(--primary-400))}
  .b-gray{background:rgba(var(--gray-500),.1);color:rgb(var(--gray-600));box-shadow:inset 0 0 0 1px rgba(var(--gray-500),.25)}.dark .b-gray{color:rgb(var(--gray-300))}
  .lcm-bar{height:6px;border-radius:99px;background:var(--m-row);overflow:hidden;min-width:90px}.lcm-bar i{display:block;height:100%;background:rgb(var(--primary-500))}
  .lcm-note{border-radius:12px;padding:12px 18px;font-size:13.5px;background:rgba(var(--primary-500),.07);box-shadow:inset 0 0 0 1px rgba(var(--primary-500),.25);color:var(--m-tx)}.lcm-note b{color:rgb(var(--primary-600))}.dark .lcm-note b{color:rgb(var(--primary-400))}.lcm-note div{margin-top:5px;color:var(--m-mu)}
  .lcm-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:16px}.lcm-stat{padding:14px 18px}.lcm-stat .l{font-size:13px;color:var(--m-mu)}.lcm-stat .v{font-size:28px;font-weight:700;color:var(--m-tx);line-height:1.2}
  .lcm-save{display:flex;gap:12px;align-items:center;padding:14px 22px;border-top:1px solid var(--m-row)}
  .lcm-two{display:grid;grid-template-columns:1fr 1fr;gap:24px}
  .lcm-modal label{display:block;font-weight:500;font-size:14px;margin:14px 0 5px}
  .lcm-err{color:rgb(var(--danger-500));font-size:13px;margin-top:6px}
  @media(max-width:900px){.lcm-two{grid-template-columns:1fr}.lcm-row{flex-direction:column;align-items:flex-start}}
</style>

<div class="lcm">
  <div class="lcm-bar2">
    <div class="lcm-tabs">
      <button type="button" wire:click="show('templates')" class="{{ $tab === 'templates' ? 'on' : '' }}">Checklist templates</button>
      <button type="button" wire:click="show('shop')" class="{{ $tab === 'shop' ? 'on' : '' }}">One shop</button>
      <button type="button" wire:click="show('stuck')" class="{{ $tab === 'stuck' ? 'on' : '' }}">Where shops get stuck</button>
    </div>
    <div class="lcm-tabs">
      @foreach(LaunchDefaults::MODULES as $mk => $ml)
        <button type="button" wire:click="setModule('{{ $mk }}')" class="{{ $module === $mk ? 'on' : '' }}">{{ $ml }}</button>
      @endforeach
    </div>
  </div>

  {{-- ================= TEMPLATES ================= --}}
  @if($tab === 'templates')
    <div class="lcm-sec"><header><h3>How it behaves</h3></header><div class="b">
      <div class="lcm-row"><div><div class="t">Hold "Go live" until required steps are done</div><div class="d">Off means the checklist is advice only: shops can go live any time and required steps just show as warnings.</div></div><label class="lcm-tg"><input type="checkbox" wire:model.live="behaviour.hold_go_live" @disabled(! $edit)><i></i></label></div>
      <div class="lcm-row"><div><div class="t">Shops already selling or booking when this shipped</div><div class="d">They started as live, with the checklist as a health view. Nothing they ran was paused. This records the choice for anyone reading it later.</div></div>
        <select class="lcm-sel" wire:model.live="behaviour.grandfather" @disabled(! $edit)><option value="live">Treat as live (recommended)</option><option value="checklist">Ask them to complete the checklist</option></select></div>
      <div class="lcm-row"><div><div class="t">New required steps reach shops that are already live</div><div class="d">Off: a new required step only holds new shops; live shops see it as recommended. On: live shops see it as "needs attention" but are never paused for it.</div></div><label class="lcm-tg"><input type="checkbox" wire:model.live="behaviour.new_req_for_live" @disabled(! $edit)><i></i></label></div>
      <div class="lcm-row"><div><div class="t">When a live required step stops being true</div><div class="d">The shop's checklist and a banner on every page of their admin always show it.</div></div>
        <div class="lcm-ctl" style="flex-wrap:wrap">
          <label><input type="checkbox" wire:model.live="behaviour.email_owner" @disabled(! $edit)> Email the owner</label>
          <label><input type="checkbox" wire:model.live="behaviour.add_issue" @disabled(! $edit)> Add to Issues</label>
          <label><input type="checkbox" wire:model.live="behaviour.email_intake" @disabled(! $edit)> Email Intake's alert address</label>
        </div></div>
      <div class="lcm-row"><div><div class="t">Remind owners stuck in preview after</div><div class="d">One email, then one more at twice the number of days. Never more.</div></div><div class="lcm-ctl"><input class="lcm-in" type="number" min="1" max="120" wire:model.live.debounce.400ms="behaviour.remind_days" style="width:80px" @disabled(! $edit)> days</div></div>
      <div class="lcm-row"><div><div class="t">Show the dashboard nudge</div><div class="d">A single line on the shop's dashboard while required steps are left. Gone when everything required is done.</div></div><label class="lcm-tg"><input type="checkbox" wire:model.live="behaviour.nudge" @disabled(! $edit)><i></i></label></div>
    </div></div>

    @php $checkOpts = $this->checkOptions(); $helpOpts = $this->helpOptions(); @endphp
    <div class="lcm-sec">
      <header><h3>Steps in this checklist</h3><p>Order, wording and level apply to every shop straight away. A shop's earlier ticks and skips are kept.</p></header>
      <div class="lcm-tbl"><table>
        <thead><tr><th style="width:90px">Order</th><th>Step</th><th>Checked by</th><th>Level</th><th title="If this stops being true on a live shop, checkout or booking is paused until it passes again">Pause if it fails</th><th>Help article</th><th>Plans</th><th>On</th><th></th></tr></thead>
        <tbody>
          @foreach($steps as $i => $s)
            <tr wire:key="st-{{ $s['id'] ?? 'new' . $i }}">
              <td style="white-space:nowrap"><button type="button" class="lcm-btn g s" wire:click="move({{ $i }}, -1)" @disabled(! $edit || $i === 0) aria-label="Move up">▲</button> <button type="button" class="lcm-btn g s" wire:click="move({{ $i }}, 1)" @disabled(! $edit || $i === count($steps) - 1) aria-label="Move down">▼</button></td>
              <td style="min-width:190px"><b>{{ $s['title'] }}</b><div class="mu sm">{{ $s['group_name'] }}</div></td>
              <td><select class="lcm-sel" style="max-width:240px" wire:model.live="steps.{{ $i }}.check" @disabled(! $edit)>@foreach($checkOpts as $ck => $cl)<option value="{{ $ck }}">{{ $cl }}</option>@endforeach</select></td>
              <td><select class="lcm-sel" wire:model.live="steps.{{ $i }}.level" @disabled(! $edit)>@foreach($levels as $lk => $ll)<option value="{{ $lk }}">{{ $ll }}</option>@endforeach</select></td>
              <td>@if($s['check'] === 'confirm' || $s['level'] !== 'req')<span class="mu sm">Not offered</span>@else<label class="lcm-tg"><input type="checkbox" wire:model.live="steps.{{ $i }}.pause_on_fail" @disabled(! $edit)><i></i></label>@endif</td>
              <td><select class="lcm-sel" style="max-width:190px" wire:model.live="steps.{{ $i }}.help_key" @disabled(! $edit)><option value="">None</option>@foreach($helpOpts as $hk => $hl)<option value="{{ $hk }}">{{ $hl }}</option>@endforeach @if($s['help_key'] && ! isset($helpOpts[$s['help_key']]))<option value="{{ $s['help_key'] }}">{{ $s['help_key'] }} (no article yet)</option>@endif</select></td>
              <td><select class="lcm-sel" wire:model.live="steps.{{ $i }}.min_tier" @disabled(! $edit)>@foreach($tiers as $tk => $tl)<option value="{{ $tk }}">{{ $tl }}</option>@endforeach</select></td>
              <td><label class="lcm-tg"><input type="checkbox" wire:model.live="steps.{{ $i }}.enabled" @disabled(! $edit)><i></i></label></td>
              <td class="n">@if($edit)<button type="button" class="lcm-btn g s" wire:click="openEdit({{ $i }})">Edit text</button>@endif</td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
      <div class="lcm-save">
        @if($edit)<button type="button" class="lcm-btn g" wire:click="openAdd">Add a step</button>@endif
        <span style="flex:1"></span>
        <span class="mu sm" style="{{ $dirty ? 'color:rgb(var(--warning-500))' : '' }}">{{ $dirty ? 'Unsaved changes' : 'No unsaved changes' }}</span>
        @if($edit)
          <button type="button" class="lcm-btn g" wire:click="discard" @disabled(! $dirty)>Discard</button>
          <button type="button" class="lcm-btn" wire:click="save" @disabled(! $dirty)>Save changes</button>
        @endif
      </div>
      @error('steps.*') <div class="lcm-err" style="padding:0 22px 14px">{{ $message }}</div> @enderror
    </div>

    <div class="lcm-note"><b>How this works</b>
      <div><b>Automatic checks</b> are written into Intake (the "Checked by" list). Each reads the shop's own data and re-runs when the shop opens the checklist, and every 15 minutes for shops in preview or live. A brand-new kind of check needs a code change; the wording, order and level of any step do not.</div>
      <div><b>You confirm</b> steps are free text the shop owner ticks themselves. Intake records who and when.</div>
      <div><b>Pause if it fails</b> is only offered on required automatic steps and only acts on a live shop, when a step that was passing stops. It never touches orders or bookings already made, and lifts itself once the step passes again. You, the owner and Issues are told each time.</div>
      <div><b>Plans</b> hides a step from shops whose plan does not include it, and it never counts toward their progress.</div>
      <div><b>Turning a step off</b> removes it from every shop's checklist. A shop's earlier ticks are kept if you turn it back on.</div>
    </div>
  @endif

  {{-- ================= ONE SHOP ================= --}}
  @if($tab === 'shop')
    @php $shop = $this->shop(); @endphp
    @if(! $shop)
      <div class="lcm-sec"><header><h3>Pick a shop</h3></header><div class="p" style="padding:16px 22px">
        <input class="lcm-in" style="width:min(420px,100%)" placeholder="Search by name or subdomain" wire:model.live.debounce.300ms="shopSearch">
        <div class="lcm-tbl" style="margin:12px -22px 0"><table><tbody>
          @foreach($this->shopMatches() as $m)
            <tr><td><b>{{ $m->name }}</b> <span class="mu sm">{{ $m->subdomain }}</span></td><td class="n"><button type="button" class="lcm-btn g s" wire:click="pickShop('{{ $m->id }}')">Open</button></td></tr>
          @endforeach
        </tbody></table></div>
      </div></div>
    @else
      @php $data = $this->shopData(); $ev = $data[$module] ?? null; @endphp
      <div class="lcm-tabs">
        <a href="{{ \App\Filament\Resources\TenantResource::getUrl('edit', ['record' => $shop->id]) }}">Tenant record</a>
        <a class="on">Launch checklist</a>
        <button type="button" wire:click="$set('shopId', null)">Another shop</button>
      </div>

      @if(! $data)
        <div class="lcm-note"><b>No checklists</b><div>This shop has neither the online store nor rentals on its plan, so there is nothing to launch.</div></div>
      @else
        <div class="lcm-two">
          @foreach(LaunchDefaults::MODULES as $mk => $ml)
            @php $d = $data[$mk] ?? null; @endphp
            <div class="lcm-sec p" style="{{ $mk === $module ? 'box-shadow:0 0 0 2px rgba(var(--primary-500),.6)' : '' }}">
              <div class="mu sm">{{ $ml }}</div>
              @if(! $d)
                <div style="font-size:16px;font-weight:600;margin:4px 0;color:var(--m-tx)">Not on this plan</div>
              @else
                @php $stt = $d['state']; $rl = count($d['req_left']); @endphp
                <div style="font-size:18px;font-weight:600;margin:4px 0 8px;color:var(--m-tx)">
                  @if($stt->state === 'paused') Live · {{ $mk === 'rent' ? 'booking' : 'checkout' }} paused
                  @elseif($stt->state === 'live') Live since {{ $stt->live_since?->format('M j') }}
                  @else Preview @endif
                  @if($d['broken'])<span class="lcm-badge b-bad" style="margin-left:6px">{{ count($d['broken']) }} broken</span>
                  @elseif($rl)<span class="lcm-badge b-warn" style="margin-left:6px">{{ $rl }} required left</span>@endif
                  @if($stt->grandfathered)<span class="lcm-badge b-gray" style="margin-left:6px">Started as live</span>@endif
                  @if($stt->forced_live)<span class="lcm-badge b-pri" style="margin-left:6px">Allowed by Intake</span>@endif
                </div>
                <div class="lcm-bar"><i style="width:{{ $d['total'] ? round($d['done'] / $d['total'] * 100) : 0 }}%"></i></div>
                <div class="mu sm" style="margin-top:6px">{{ $d['done'] }} of {{ $d['total'] }} done{{ $stt->state === 'preview' && $stt->created_at ? ' · in preview for ' . (int) $stt->created_at->diffInDays(now()) . ' days' : '' }}
                  @if($mk !== $module) · <a href="#" wire:click.prevent="setModule('{{ $mk }}')" style="text-decoration:underline">Show steps</a>@endif</div>
              @endif
            </div>
          @endforeach
        </div>

        @if($ev)
          @php $stt = $ev['state']; @endphp
          @if($edit)
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <button type="button" class="lcm-btn g" wire:click="rerun">Re-run all checks</button>
              @if($stt->state === 'preview' && ! $stt->forced_live && $ev['req_left'])<button type="button" class="lcm-btn g" wire:click="ask('force')">Let this shop go live anyway…</button>@endif
              @if($stt->state === 'preview' && $ev['req_left'])<button type="button" class="lcm-btn g" wire:click="ask('nudge')">Send a nudge…</button>@endif
              @if($stt->state === 'paused')
                <button type="button" class="lcm-btn" wire:click="ask('lift')">Lift the pause…</button>
              @elseif($stt->state === 'live')
                <button type="button" class="lcm-btn r" wire:click="ask('pause')">Pause {{ $module === 'rent' ? 'booking' : 'checkout' }}…</button>
              @endif
            </div>
          @endif
          @if($stt->state === 'paused')
            <div class="lcm-note" style="box-shadow:inset 0 0 0 1px rgba(var(--danger-500),.4);background:rgba(var(--danger-500),.07)"><b style="color:rgb(var(--danger-500))">Paused {{ $stt->paused_at?->diffForHumans() }}</b><div>{{ $stt->pause_reason }}{{ $stt->pause_step ? '. It lifts by itself once the step passes again.' : '. Paused by hand: only Intake lifts it.' }}</div></div>
          @endif

          <div class="lcm-sec"><header><h3>{{ Launch::label($module) }} steps</h3></header>
            <div class="lcm-tbl"><table>
              <thead><tr><th>Step</th><th>Level</th><th>State</th><th>Who / detail</th><th></th></tr></thead>
              <tbody>
                @foreach($ev['rows'] as $r)
                  @php [$bc, $bl] = $badge($r['status']); if ($r['status'] === 'bad' && in_array($stt->state, ['live','paused'], true) && $r['level'] === 'req') $bl = 'Broken' . (isset(((array) $stt->broken)[$r['key']]) ? ' since ' . \Illuminate\Support\Carbon::parse(((array) $stt->broken)[$r['key']])->format('M j, g:i a') : ''); @endphp
                  <tr>
                    <td>{{ $r['step']->title }}</td>
                    <td><span class="lcm-badge {{ $r['level'] === 'req' ? 'b-pri' : 'b-gray' }}">{{ $levels[$r['level']] }}</span></td>
                    <td><span class="lcm-badge {{ $bc }}">{{ $bl }}</span></td>
                    <td class="mu sm" style="max-width:360px">
                      @if($r['mark']){{ $r['mark']->by_name }}{{ $r['mark']->reason ? ' · "' . $r['mark']->reason . '"' : '' }} · {{ $r['mark']->updated_at?->format('M j') }}
                      @else{{ $r['step']->isConfirm() ? 'The shop confirms' : $r['line'] }}@endif
                    </td>
                    <td class="n">
                      @if($edit)
                        @if($r['mark'] && $r['mark']->kind === 'waived')<button type="button" class="lcm-btn g s" wire:click="ask('clear', '{{ $r['key'] }}')">Remove waiver…</button>
                        @elseif($r['mark'])<button type="button" class="lcm-btn g s" wire:click="ask('clear', '{{ $r['key'] }}')">{{ $r['mark']->kind === 'skipped' ? 'Reset skip…' : 'Reset tick…' }}</button>
                        @elseif(! in_array($r['status'], ['ok', 'na'], true))<button type="button" class="lcm-btn g s" wire:click="ask('waive', '{{ $r['key'] }}')">Waive…</button>@endif
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table></div>
          </div>

          <div class="lcm-sec"><header><h3>History</h3></header>
            <div class="lcm-tbl"><table><tbody>
              @forelse($this->history() as $h)
                <tr><td class="mu" style="width:170px;white-space:nowrap">{{ $h->created_at?->format('M j, g:i a') }}</td><td>{{ $h->message }}</td></tr>
              @empty
                <tr><td class="mu">Nothing yet. Going live, ticks, skips, waivers, pauses and reminders are all recorded here.</td></tr>
              @endforelse
            </tbody></table></div>
          </div>

          <div class="lcm-note"><b>What these buttons do</b>
            <div><b>Waive</b> removes one step for this shop only; it counts as done, is labelled "Waived by Intake" on the shop's checklist, and is recorded here with your reason.</div>
            <div><b>Let this shop go live anyway</b> lets the shop use Go live while required steps are open. Required steps stay visible on their checklist.</div>
            <div><b>Pause</b> is the same pause the automatic rule applies, but only Intake lifts it. The shop sees a plain notice; visitors can still look. Orders and bookings already made are untouched. The owner is emailed.</div>
            <div><b>Re-run all checks</b> only reads; it changes nothing but the state shown.</div>
          </div>
        @endif
      @endif
    @endif
  @endif

  {{-- ================= WHERE STUCK ================= --}}
  @if($tab === 'stuck')
    @php $sk = $this->stuck(); @endphp
    <div class="lcm-stats">
      <div class="lcm-sec lcm-stat"><div class="l">Shops with {{ strtolower(Launch::label($module)) }} on</div><div class="v">{{ $sk['on'] }}</div></div>
      <div class="lcm-sec lcm-stat"><div class="l">Live</div><div class="v">{{ $sk['live'] }}</div></div>
      <div class="lcm-sec lcm-stat"><div class="l">In preview</div><div class="v">{{ $sk['preview'] }}</div></div>
      <div class="lcm-sec lcm-stat"><div class="l">Live with a broken step or paused</div><div class="v" style="{{ $sk['broken'] ? 'color:rgb(var(--danger-500))' : '' }}">{{ $sk['broken'] }}</div></div>
    </div>
    <div class="lcm-sec"><header><h3>Required steps shops in preview have not finished</h3><p>{{ $sk['prev_total'] }} {{ $sk['prev_total'] === 1 ? 'shop has' : 'shops have' }} opened the checklist and not gone live.</p></header>
      <div class="lcm-tbl"><table>
        <thead><tr><th>Step</th><th class="n">Shops not done</th><th style="width:200px"></th><th class="n">Typical wait</th></tr></thead>
        <tbody>
          @foreach($sk['rows'] as $r)
            <tr><td>{{ $r['title'] }}</td><td class="n">{{ $r['not_done'] }}</td><td><div class="lcm-bar"><i style="width:{{ $sk['prev_total'] ? round($r['not_done'] / $sk['prev_total'] * 100) : 0 }}%"></i></div></td><td class="n">{{ $r['median'] === null ? '—' : ($r['median'] . ' ' . ($r['median'] === 1 ? 'day' : 'days')) }}</td></tr>
          @endforeach
        </tbody>
      </table></div>
    </div>
    <div class="lcm-sec"><header><h3>In preview longer than {{ $sk['days'] }} days</h3></header>
      <div class="lcm-tbl"><table>
        <thead><tr><th>Shop</th><th>Days</th><th>Required left</th><th>Reminded</th><th></th></tr></thead>
        <tbody>
          @forelse($sk['long'] as $l)
            <tr>
              <td>{{ $l['name'] }}</td><td>{{ $l['days'] }}</td><td class="sm">{{ $l['left'] ? implode(', ', $l['left']) : 'None (ready to go live)' }}</td><td class="mu sm">{{ $l['reminded'] }}</td>
              <td class="n" style="white-space:nowrap"><button type="button" class="lcm-btn g s" wire:click="pickShop('{{ $l['id'] }}')">Open shop</button>
                @if($edit && $l['left'])<button type="button" class="lcm-btn g s" wire:click="askNudgeFor('{{ $l['id'] }}')">Send a nudge…</button>@endif</td>
            </tr>
          @empty
            <tr><td colspan="5" class="mu">No shop has been in preview that long.</td></tr>
          @endforelse
        </tbody>
      </table></div>
    </div>
    <div class="lcm-note"><b>Reading this</b><div>Counts come from each shop's last check (every 15 minutes, or when the shop opens its checklist). "Typical wait" is the median days from a shop first opening the checklist to finishing that step, for shops that finished it. A nudge is the same email as the automatic reminder and is logged on the shop.</div></div>
  @endif
</div>

{{-- ================= MODALS (Filament's own) ================= --}}
<x-filament::modal id="lc-edit" width="lg">
  <x-slot name="heading">Edit step text</x-slot>
  <div class="lcm lcm-modal" style="gap:0">
    <label>Title (what the shop sees)</label><input class="lcm-in" wire:model="edit.title" maxlength="120">
    @error('edit.title')<div class="lcm-err">{{ $message }}</div>@enderror
    <label>Group</label><input class="lcm-in" wire:model="edit.group_name" maxlength="60">
    <label>Explanation</label><textarea class="lcm-ta" rows="2" wire:model="edit.explain" maxlength="400"></textarea>
    <label>"How it's checked" (shown when the shop opens the step)</label><textarea class="lcm-ta" rows="3" wire:model="edit.how" maxlength="600"></textarea>
    <p class="mu sm" style="margin-top:10px">Applies when you press Save changes.</p>
  </div>
  <x-slot name="footerActions">
    <x-filament::button color="gray" x-on:click="close()">Cancel</x-filament::button>
    <x-filament::button wire:click="useEdit">Use this text</x-filament::button>
  </x-slot>
</x-filament::modal>

<x-filament::modal id="lc-add" width="lg">
  <x-slot name="heading">Add a step to {{ Launch::label($module) }}</x-slot>
  <div class="lcm lcm-modal" style="gap:0">
    <label>Title (what the shop sees)</label><input class="lcm-in" wire:model="add.title" maxlength="120" placeholder="e.g. Set your pickup instructions">
    @error('add.title')<div class="lcm-err">{{ $message }}</div>@enderror
    <label>Group</label><input class="lcm-in" wire:model="add.group_name" maxlength="60">
    <label>One line of explanation</label><textarea class="lcm-ta" rows="2" wire:model="add.explain" maxlength="400"></textarea>
    <label>Checked by</label><select class="lcm-sel" wire:model="add.check">@foreach($this->checkOptions() as $ck => $cl)<option value="{{ $ck }}">{{ $cl }}</option>@endforeach</select>
    <label>Level</label><select class="lcm-sel" wire:model="add.level">@foreach($levels as $lk => $ll)<option value="{{ $lk }}">{{ $ll }}</option>@endforeach</select>
    <p class="mu sm" style="margin-top:10px">Need a check that is not in this list? It is a small code change; add it as "You confirm" meanwhile. The step is added when you press Save changes.</p>
  </div>
  <x-slot name="footerActions">
    <x-filament::button color="gray" x-on:click="close()">Cancel</x-filament::button>
    <x-filament::button wire:click="addStep">Add step</x-filament::button>
  </x-slot>
</x-filament::modal>

@php
  $sn = $this->shop()?->name ?? 'this shop';
  $nn = $nudgeFor ? (\App\Models\Tenant::find($nudgeFor)?->name ?? $sn) : $sn;
  $tt = ($target && ! is_numeric($target)) ? Launch::stepTitle($module, $target) : '';
@endphp
@foreach([
  'waive' => ['Waive "' . $tt . '" for ' . $sn, 'The step counts as done for this shop only. They will see it as "Waived by Intake".', true, 'Waive', 'doWaive'],
  'clear' => ['Reset "' . $tt . '" for ' . $sn . '?', 'The step goes back to its own state on their checklist. The history keeps what was there.', false, 'Reset', 'doClear'],
  'force' => ['Let ' . $sn . ' go live anyway?', 'The shop can use Go live while required steps are open. Their checklist still shows them.', true, 'Allow', 'doForce'],
  'pause' => ['Pause ' . ($module === 'rent' ? 'online booking' : 'checkout') . ' for ' . $sn . '?', 'Customers see a short "paused" notice and can still look. Orders and bookings already made are untouched. The owner is emailed. Only Intake lifts this pause.', true, 'Pause', 'doPause'],
  'lift'  => ['Lift the pause for ' . $sn . '?', ($module === 'rent' ? 'Online booking' : 'Checkout') . ' reopens straight away.', false, 'Lift the pause', 'doLift'],
  'nudge' => ['Send ' . $nn . ' a nudge?', 'Emails the owner the same reminder as the automatic one, listing the required steps left. It is logged on the shop.', false, 'Send nudge', 'doNudge'],
] as $mid => [$mh, $mb, $needReason, $mok, $mact])
  <x-filament::modal id="lc-{{ $mid }}" width="md">
    <x-slot name="heading">{{ $mh }}</x-slot>
    <div class="lcm lcm-modal" style="gap:0">
      <p class="mu sm">{{ $mb }}</p>
      @if($needReason)
        <label>Reason (kept in the history)</label><input class="lcm-in" wire:model="reason" maxlength="300" placeholder="Required">
        @error('reason')<div class="lcm-err">{{ $message }}</div>@enderror
      @endif
    </div>
    <x-slot name="footerActions">
      <x-filament::button color="gray" x-on:click="close()">Cancel</x-filament::button>
      <x-filament::button :color="$mid === 'pause' ? 'danger' : 'primary'" wire:click="{{ $mact }}">{{ $mok }}</x-filament::button>
    </x-slot>
  </x-filament::modal>
@endforeach

<x-filament::modal id="lc-rerun" width="md">
  <x-slot name="heading">Checks re-run</x-slot>
  <p class="text-sm" style="opacity:.75">All {{ is_numeric($target) ? $target : '' }} steps for this shop were read just now. Nothing was changed.</p>
  <x-slot name="footerActions"><x-filament::button x-on:click="close()">Done</x-filament::button></x-slot>
</x-filament::modal>
</x-filament-panels::page>
