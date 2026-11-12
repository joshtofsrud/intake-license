{{-- MARKER-LAUNCH-CHECKLIST: one module's launch checklist. $ev = Launch::evaluate(); used as the
     E-commerce page's first tab and on Rentals › Launch checklist. --}}
@php
  use App\Support\Launch\Launch;
  $lcMod   = $ev['module'];
  $lcThing = Launch::thing($lcMod);
  $lcSt    = $ev['state'];
  $lcLive  = in_array($lcSt->state, ['live', 'paused'], true);
  $lcRoute = fn (string $a) => route($lcMod === 'rent' ? 'tenant.launch.rent.' . $a : 'tenant.launch.' . $a, ['module' => $lcMod]);
  $lcLeft  = count($ev['req_left']);
  $lcBroke = $ev['broken'];
  $lcGroups = [];
  foreach ($ev['rows'] as $r) { $lcGroups[$r['step']->group_name][] = $r; }
  $lcDone  = fn ($r) => in_array($r['status'], ['ok', 'na', 'confirmed', 'skipped', 'waived'], true);
  $lcWhen  = fn ($d) => $d ? tlocal_datetime($d, 'M j') : '';
@endphp
<style>
.lc{--lc-ok:#3B6D11;--lc-okbg:#EAF3DE;--lc-okdot:#7aa82a;--lc-warn:#854F0B;--lc-warnbg:#FAEEDA;--lc-warndot:#EF9F27;--lc-bad:#A32D2D;--lc-badbg:#FCEBEB;--lc-req:#185FA5;--lc-reqbg:#E6F1FB}
body.ia-theme-c .lc{--lc-ok:#97C459;--lc-okbg:rgba(99,153,34,.22);--lc-warn:#EF9F27;--lc-warnbg:rgba(239,159,39,.18);--lc-bad:#F09595;--lc-badbg:rgba(226,75,74,.16);--lc-req:#85B7EB;--lc-reqbg:rgba(56,138,221,.2)}
.lc-head{display:flex;gap:18px;align-items:center;flex-wrap:wrap;padding:2px 0 18px;border-bottom:.5px solid var(--ia-border);margin-bottom:20px}
.lc-prog{flex:1 1 260px;min-width:220px}
.lc-prog .n{font-size:20px;font-weight:500;letter-spacing:-.01em}
.lc-prog .n small{font-size:12.5px;font-weight:400;color:var(--ia-text-muted);margin-left:6px}
.lc-bar{height:5px;border-radius:99px;background:var(--ia-surface-2);margin-top:8px;overflow:hidden}
.lc-bar i{display:block;height:100%;background:var(--lc-okdot);border-radius:99px}
.lc-state{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.lc-pill{font-size:11.5px;font-weight:600;padding:3px 10px;border-radius:99px}
.lc-pill.prev{background:var(--lc-warnbg);color:var(--lc-warn)}.lc-pill.live{background:var(--lc-okbg);color:var(--lc-ok)}.lc-pill.bad{background:var(--lc-badbg);color:var(--lc-bad)}
.lc-alert{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:18px;padding:11px 14px;border-radius:var(--ia-r-md);background:var(--lc-badbg);color:var(--lc-bad);font-size:13px;line-height:1.5}
.lc-prev{margin-bottom:18px;padding:10px 14px;border-radius:var(--ia-r-md);background:var(--lc-warnbg);color:var(--lc-warn);font-size:12.5px;line-height:1.5}
.lc-grp h3{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--ia-text-muted);font-weight:600;margin:20px 0 4px;display:flex;gap:8px;align-items:baseline}
.lc-grp h3 span{letter-spacing:0;text-transform:none;font-weight:400;font-size:11.5px}
.lc-row{border-bottom:.5px solid var(--ia-border)}
.lc-line{display:flex;gap:12px;align-items:flex-start}
.lc-d{flex:1;min-width:0}
.lc-d summary{display:flex;gap:12px;align-items:flex-start;padding:12px 0;cursor:pointer;list-style:none}
.lc-d summary::-webkit-details-marker{display:none}
.lc-act{padding-top:10px}
.lc-ico{width:20px;height:20px;flex:none;margin-top:1px;display:grid;place-items:center;border-radius:50%;font-size:11px;font-weight:700}
.lc-ico.ok{background:var(--lc-okdot);color:#fff}.lc-ico.warn{background:var(--lc-warndot);color:#fff}.lc-ico.todo{border:1.5px solid var(--ia-border-strong)}
.lc-ico.man{border:1.5px dashed var(--ia-border-strong)}.lc-ico.skip{background:var(--ia-surface-2);color:var(--ia-text-muted)}.lc-ico.bad{background:#E24B4A;color:#fff}
.lc-tx{flex:1;min-width:0}
.lc-tx .t{font-size:13.5px;font-weight:500;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.lc-tx .r{font-size:12px;color:var(--ia-text-muted);margin-top:2px;line-height:1.5}
.lc-tx .r.warn{color:var(--lc-warn)}.lc-tx .r.bad{color:var(--lc-bad)}.lc-tx .r.ok{color:var(--lc-ok)}
.lc-tag{font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;padding:1px 6px;border-radius:99px;background:var(--ia-surface-2);color:var(--ia-text-muted)}
.lc-tag.req{background:var(--lc-reqbg);color:var(--lc-req)}
.lc-by{font-size:11.5px;color:var(--ia-text-muted)}
.lc-act{flex:none;display:flex;gap:8px;align-items:center}
.lc-act form{margin:0}
.lc-more{padding:0 0 14px 32px;font-size:12.5px;line-height:1.6;color:var(--ia-text-muted);max-width:74ch}
.lc-more b{color:var(--ia-text);font-weight:500}
.lc-more .meta{margin-top:8px;font-size:11.5px}
.lc-more a{text-decoration:underline}
.lc-legend{font-size:12px;color:var(--ia-text-muted);line-height:1.6;margin:22px 0 0;padding:9px 0;border-top:.5px solid var(--ia-border);border-bottom:.5px solid var(--ia-border)}
.lc-legend summary{cursor:pointer;font-size:12px;color:var(--ia-text);font-weight:500;list-style:none}
.lc-legend summary::-webkit-details-marker{display:none}
.lc-legend summary:after{content:" ▾";color:var(--ia-text-muted)}.lc-legend[open] summary:after{content:" ▴"}
.lc-legend .k{margin-top:7px}.lc-legend b{color:var(--ia-text);font-weight:500}
.lc-foot{display:flex;gap:10px;align-items:center;margin-top:14px;font-size:12px;color:var(--ia-text-muted)}
.lc-foot form{margin:0}
@media (max-width:640px){.lc-line{flex-wrap:wrap}.lc-act{width:100%;padding:0 0 12px 32px}}
</style>

<div class="lc">
  @if($lcBroke)
    @php $lcB = $lcBroke[0]; @endphp
    <div class="lc-alert">
      <span><b>{{ $lcB['step']->title }}</b> stopped being true. {{ $lcB['line'] }}
        @if($lcSt->state === 'paused')
          {{ $lcMod === 'rent' ? 'Online booking' : 'Checkout' }} is paused until it passes again, then reopens by itself.
        @endif
      </span>
      @if($lcB['fix'])<a class="ia-btn ia-btn--sm" href="{{ $lcB['fix'] }}">Fix it</a>@endif
    </div>
  @elseif($lcSt->state === 'paused')
    <div class="lc-alert"><span><b>{{ $lcMod === 'rent' ? 'Online booking' : 'Checkout' }} is paused by Intake.</b> {{ $lcSt->pause_reason }} Visitors can still look. Reply to Intake's email or contact support to lift it.</span></div>
  @endif

  @if($ev['public'] === 'off')
    <div class="lc-prev"><b>Switched off.</b> {{ $lcMod === 'rent' ? '"Show rentals in my menu" is off in Rentals settings' : '"Store is open" is off under General' }}, so nobody outside your team can reach it. The checklist still works.</div>
  @elseif($ev['public'] === 'preview')
    <div class="lc-prev"><b>Preview.</b> Only your signed-in team can see your {{ $lcThing }}. Visitors see a "coming soon" page, and nothing is listed on Google.</div>
  @endif

  <div class="lc-head">
    <div class="lc-prog">
      <div class="n">{{ $ev['done'] }} of {{ $ev['total'] }} done<small>{{ $lcLeft ? $lcLeft . ' required ' . ($lcLeft === 1 ? 'step' : 'steps') . ' left' : 'all required steps done' }}</small></div>
      <div class="lc-bar"><i style="width:{{ $ev['total'] ? round($ev['done'] / $ev['total'] * 100) : 0 }}%"></i></div>
    </div>
    <div class="lc-state">
      @if(! $lcLive && $ev['public'] === 'live')
        {{-- Intake has made checklists advice-only: the store is public without going live --}}
        <span class="lc-pill live">Live</span>
      @elseif($lcLive)
        <span class="lc-pill {{ ($lcBroke || $lcSt->state === 'paused') ? 'bad' : 'live' }}">
          {{ $lcSt->state === 'paused' ? 'Live · ' . ($lcMod === 'rent' ? 'booking' : 'checkout') . ' paused' : ($lcBroke ? 'Live · needs attention' : 'Live since ' . $lcWhen($lcSt->live_since)) }}
        </span>
        <form method="POST" action="{{ $lcRoute('offline') }}" id="lcOffline">@csrf
          <button type="button" class="ia-btn" data-lc-confirm="lcOffline"
                  data-title="Take your {{ $lcThing }} offline?"
                  data-msg="Visitors will see a &quot;coming soon&quot; page and nothing is listed on Google. Orders and bookings already made are not touched, and your team can still see everything."
                  data-ok="Take offline" data-cancel="Keep it live">Take offline</button>
        </form>
      @else
        <span class="lc-pill prev">Preview · only your team can see it</span>
        <form method="POST" action="{{ $lcRoute('golive') }}" id="lcGo">@csrf
          <button type="button" class="ia-btn ia-btn--primary" data-lc-confirm="lcGo" @disabled(! $ev['can_go_live'])
                  title="{{ $ev['can_go_live'] ? '' : $lcLeft . ' required ' . ($lcLeft === 1 ? 'step' : 'steps') . ' left' }}"
                  data-title="Go live?"
                  data-msg="Anyone can then see and use your {{ $lcThing }}. {{ $lcMod === 'ecom' ? 'Your items can appear on Google if the feed is on.' : 'Renters can book online.' }} You can take it offline again at any time."
                  data-ok="Go live" data-cancel="Not yet">Go live</button>
        </form>
      @endif
    </div>
  </div>

  @foreach($lcGroups as $gName => $gRows)
    <div class="lc-grp">
      <h3>{{ $gName }}<span>{{ count(array_filter($gRows, $lcDone)) }} of {{ count($gRows) }}</span></h3>
      @foreach($gRows as $r)
        @php
          $s = $r['status'];
          $isBadLive = $s === 'bad' && $lcLive && $r['level'] === 'req';
          $ico = match ($s) {
            'ok', 'na', 'confirmed', 'waived' => ['ok', '✓'],
            'warn' => ['warn', '!'],
            'bad'  => $isBadLive ? ['bad', '!'] : ['todo', ''],
            'todo' => ['man', ''],
            'skipped' => ['skip', '–'],
            default => ['todo', ''],
          };
          $line = match ($s) {
            'confirmed' => 'Confirmed by ' . ($r['mark']->by_name ?: 'your team') . ' · ' . $lcWhen($r['mark']->updated_at),
            'skipped'   => 'Skipped by ' . ($r['mark']->by_name ?: 'your team') . ' · ' . $lcWhen($r['mark']->updated_at) . ($r['mark']->reason ? ' · "' . $r['mark']->reason . '"' : ''),
            'waived'    => 'Waived by Intake' . ($r['mark']->reason ? ' · ' . $r['mark']->reason : ''),
            default     => $r['line'],
          };
          $lineCls = match ($s) { 'ok' => 'ok', 'warn' => 'warn', 'bad' => $isBadLive ? 'bad' : '', default => '' };
        @endphp
        <div class="lc-row">
         <div class="lc-line">
          <details class="lc-d">
          <summary>
            <span class="lc-ico {{ $ico[0] }}">{{ $ico[1] }}</span>
            <div class="lc-tx">
              <div class="t">{{ $r['step']->title }}
                <span class="lc-tag {{ $r['level'] === 'req' ? 'req' : '' }}">{{ \App\Models\Launch\LaunchStep::LEVELS[$r['level']] }}</span>
                <span class="lc-by">{{ $r['step']->isConfirm() ? 'You confirm' : 'Checked for you' }}</span>
              </div>
              <div class="r {{ $lineCls }}">{{ $line }}</div>
            </div>
          </summary>
          <div class="lc-more">
            <div>{{ $r['step']->explain }}</div>
            <div class="meta"><b>How it's checked.</b> {{ $r['step']->how }}
              @if($r['late'] && $r['step']->level === 'req') <br><b>New step.</b> Added after you went live, so it never holds or pauses your {{ $lcThing }}. @endif
              @if($r['help']) <a href="{{ $r['help'] }}">How to do this</a>@endif
              @if($r['fix'] && $s === 'ok') · <a href="{{ $r['fix'] }}">Open the setting</a>@endif
            </div>
          </div>
          </details>
            <div class="lc-act">
              @if($r['level'] === 'opt' && in_array($s, ['bad', 'warn', 'todo', 'ok'], true))
                <form method="POST" action="{{ $lcRoute('mark') }}" class="lc-skip">@csrf
                  <input type="hidden" name="step" value="{{ $r['key'] }}"><input type="hidden" name="kind" value="skipped"><input type="hidden" name="reason" value="">
                  <button type="button" class="ia-btn ia-btn--sm" data-lc-skip data-title="Skip: {{ $r['step']->title }}">Skip</button>
                </form>
              @endif
              @if($s === 'todo')
                <form method="POST" action="{{ $lcRoute('mark') }}" id="lcC{{ $r['key'] }}">@csrf
                  <input type="hidden" name="step" value="{{ $r['key'] }}"><input type="hidden" name="kind" value="confirmed">
                  <button type="button" class="ia-btn ia-btn--sm" data-lc-confirm="lcC{{ $r['key'] }}"
                          data-title="{{ $r['step']->title }}"
                          data-msg="{{ $r['step']->explain }} Ticking this records that you did it, with your name and the time. You can undo it later."
                          data-ok="I've done this" data-cancel="Not yet">I've done this</button>
                </form>
              @elseif(in_array($s, ['confirmed', 'skipped'], true))
                <form method="POST" action="{{ $lcRoute('mark') }}">@csrf
                  <input type="hidden" name="step" value="{{ $r['key'] }}"><input type="hidden" name="kind" value="clear">
                  <button type="submit" class="ia-btn ia-btn--sm">{{ $s === 'skipped' ? 'Turn on' : 'Undo' }}</button>
                </form>
              @elseif(in_array($s, ['bad', 'warn'], true) && $r['fix'])
                <a class="ia-btn ia-btn--sm {{ $s === 'bad' ? 'ia-btn--primary' : '' }}" href="{{ $r['fix'] }}">{{ $s === 'warn' ? 'Review' : $r['fix_label'] }}</a>
              @endif
            </div>
         </div>
        </div>
      @endforeach
    </div>
  @endforeach

  <div class="lc-foot">
    <span>Checked {{ $lcSt->checked_at ? $lcSt->checked_at->diffForHumans() : 'just now' }}.</span>
    <form method="POST" action="{{ $lcRoute('recheck') }}">@csrf<button type="submit" class="ia-btn ia-btn--sm">Check again</button></form>
  </div>

  <details class="lc-legend" open>
    <summary>What this checklist does</summary>
    <div class="k"><b>It only looks.</b> Steps marked <i>Checked for you</i> re-check each time you open this page, and every 15 minutes once you are live. Nothing here changes your settings.</div>
    <div class="k"><b>Required steps hold the Go live button.</b> Until you go live, only your signed-in team can see the {{ $lcThing }}; visitors see "coming soon". Recommended and optional steps never hold it.</div>
    <div class="k"><b>Steps you confirm</b> are your word: we record who ticked it and when. <b>Skipping</b> is allowed for optional steps and keeps the reason.</div>
    <div class="k"><b>After you're live,</b> a required step that stops being true shows here and at the top of every page, and the owner is emailed. For the most serious ones (payments disconnected) {{ $lcMod === 'rent' ? 'online booking' : 'checkout' }} is paused until it passes again, then reopens by itself. Orders and bookings already made are never touched.</div>
    <div class="k"><b>Intake sets the list.</b> Intake support can waive a step for your shop; waived steps say so.</div>
  </details>
</div>

<script>
(function () {
  if (window.__lcBound) return; window.__lcBound = true;
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-lc-confirm]');
    if (b) {
      e.preventDefault();
      if (b.disabled) return;
      IntakeConfirm.show({ title: b.dataset.title, message: b.dataset.msg, confirmText: b.dataset.ok, cancelText: b.dataset.cancel })
        .then(function (ok) { if (ok) document.getElementById(b.dataset.lcConfirm).submit(); });
      return;
    }
    var k = e.target.closest('[data-lc-skip]');
    if (k) {
      e.preventDefault();
      var f = k.closest('form');
      IntakeConfirm.prompt({ title: k.dataset.title, message: 'Optional steps can be skipped. Say why, so you and Intake remember (optional).', placeholder: 'Reason', confirmText: 'Skip this step' })
        .then(function (v) { if (v === null || v === undefined) return; f.querySelector('input[name=reason]').value = v; f.submit(); });
    }
  });
})();
</script>
