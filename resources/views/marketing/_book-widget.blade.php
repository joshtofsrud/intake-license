{{-- MARKER-SCHED-PUBLIC — the calendar + form. Self-contained on purpose so
     the page-builder section and the invest page can @include it.
     $type      PlatformBookingType (required)
     $booking   PlatformBooking — present = reschedule mode (time only)
     $showHost  bool, default true — the "who you're meeting" column
     $heading / $intro  optional copy overrides --}}
@php
    $booking  = $booking  ?? null;
    $showHost = $showHost ?? true;
    $hostName = \App\Models\PlatformBookingSetting::get('host_name', '') ?: (\App\Models\PlatformSettings::fromName() ?: 'Intake');
    $hostTitle = \App\Models\PlatformBookingSetting::get('host_title', '') ?: 'Intake';
    $isMove   = $booking !== null;
    $action   = $isMove ? route('book.reschedule', $booking->token) : route('book.store', $type->slug);
    $slotsUrl = route('book.slots', $type->slug);
    $whereLabel = match ($type->location_mode) {
        'phone'     => "Phone — we'll call the number you give",
        'choice'    => 'Google Meet or a phone call — your pick',
        'in_person' => 'In person',
        default     => 'Google Meet — link in your confirmation',
    };
    $oldStart = old('start') ?: (string) request()->query('start', ''); // MARKER-SCHED-SECTION — ?start= from the next-slots pills
    $widgetId = 'bw' . substr(md5($action), 0, 6);
@endphp

@once
<style>
.mk-bc{display:grid;grid-template-columns:280px 1fr;background:var(--mk-bg2,#141414);border:.5px solid var(--mk-border,rgba(255,255,255,.08));border-radius:var(--mk-r-lg,12px);overflow:hidden}
.mk-bc.no-host{grid-template-columns:1fr}
.mk-bc-about{padding:26px;border-right:.5px solid var(--mk-border,rgba(255,255,255,.08))}
.mk-bc-who{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.mk-bc-who .av{width:38px;height:38px;border-radius:50%;background:var(--mk-bg3,#1a1a1a);display:grid;place-items:center;font-weight:600;color:var(--mk-muted,rgba(255,255,255,.45))}
.mk-bc-who b{display:block;font-size:14px}.mk-bc-who span{font-size:12.5px;color:var(--mk-muted,rgba(255,255,255,.45))}
.mk-bc-about h3{font-size:20px;margin:0 0 6px;letter-spacing:-.02em;line-height:1.2}
.mk-bc-about p{color:var(--mk-muted,rgba(255,255,255,.45));margin:0 0 14px;font-size:14.5px;line-height:1.55}
.mk-bc-facts{display:grid;gap:8px;font-size:13.5px;color:var(--mk-muted,rgba(255,255,255,.45))}
.mk-bc-facts b{color:var(--mk-text,#f0f0f0);font-weight:500}
.mk-bc-pane{padding:26px;min-height:420px}
.mk-bc-pick{display:grid;grid-template-columns:1fr 180px;gap:22px}
.mk-bc-mh{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;font-size:14px;font-weight:600}
.mk-bc-mh button{width:28px;height:28px;border-radius:6px;border:.5px solid var(--mk-border2,rgba(255,255,255,.14));background:none;color:var(--mk-muted,rgba(255,255,255,.45));cursor:pointer;font:inherit}
.mk-bc-days{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;text-align:center}
.mk-bc-days .dow{font-size:11px;color:var(--mk-dim,rgba(255,255,255,.2));padding:4px 0}
.mk-bc-days .d{height:36px;border-radius:6px;display:grid;place-items:center;color:var(--mk-dim,rgba(255,255,255,.2));font-size:13.5px;border:0;background:none;font:inherit}
.mk-bc-days .d.ok{color:var(--mk-text,#f0f0f0);background:var(--mk-bg3,#1a1a1a);cursor:pointer;font-weight:500}
.mk-bc-days .d.ok:hover{filter:brightness(1.25)}
.mk-bc-days .d.sel{background:var(--mk-accent,#BEF264);color:var(--mk-accent-text,#0a0a0a);font-weight:600}
.mk-bc-slots h4{font-size:13px;margin:0 0 10px;color:var(--mk-muted,rgba(255,255,255,.45));font-weight:500}
.mk-bc-slot{display:block;width:100%;padding:9px;border:.5px solid var(--mk-border2,rgba(255,255,255,.14));background:none;color:var(--mk-text,#f0f0f0);border-radius:var(--mk-r,8px);margin-bottom:8px;text-align:center;font-weight:500;font:inherit;font-size:14px;cursor:pointer}
.mk-bc-slot:hover{border-color:var(--mk-accent,#BEF264)}
.mk-bc-slots [data-slots]{max-height:372px;overflow-y:auto;padding-right:2px} /* MARKER-SCHED-INVEST */
.mk-bc-empty{font-size:13.5px;color:var(--mk-muted,rgba(255,255,255,.45))}
.mk-bc-tz{margin-top:12px;font-size:12.5px;color:var(--mk-muted,rgba(255,255,255,.45))}
.mk-bc-tz select{background:var(--mk-bg3,#1a1a1a);color:var(--mk-text,#f0f0f0);border:.5px solid var(--mk-border2,rgba(255,255,255,.14));border-radius:6px;padding:4px 8px;font:inherit;font-size:12.5px;margin-left:6px;max-width:100%}
.mk-bc-chosen{background:var(--mk-bg3,#1a1a1a);border:.5px solid var(--mk-border,rgba(255,255,255,.08));border-radius:var(--mk-r,8px);padding:12px 14px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:14px}
.mk-bc-chosen b{display:block}.mk-bc-chosen small{color:var(--mk-muted,rgba(255,255,255,.45));font-size:13px}
.mk-bc-chosen button{background:none;border:0;color:var(--mk-muted,rgba(255,255,255,.45));text-decoration:underline;cursor:pointer;font:inherit;font-size:13px}
.mk-bc-f{margin-bottom:14px}.mk-bc-two{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.mk-bc-label{display:block;font-size:12px;font-weight:500;margin-bottom:6px;color:var(--mk-text,#f0f0f0);letter-spacing:.02em}
.mk-bc-label small{color:var(--mk-dim,rgba(255,255,255,.3));font-weight:400}
.mk-bc-input{width:100%;padding:12px 14px;background:rgba(255,255,255,.03);border:.5px solid var(--mk-border,rgba(255,255,255,.08));border-radius:8px;font-size:15px;font-family:inherit;color:var(--mk-text,#f0f0f0);box-sizing:border-box}
.mk-bc-input:focus{outline:none;border-color:rgba(190,242,100,.5);background:rgba(255,255,255,.05)}
.mk-bc-radios{display:flex;gap:8px}
.mk-bc-radios label{flex:1;border:.5px solid var(--mk-border2,rgba(255,255,255,.14));border-radius:8px;padding:10px;display:flex;gap:8px;align-items:center;cursor:pointer;font-size:14px}
.mk-bc-err{color:#f87171;font-size:13px;margin:-6px 0 12px}
.mk-bc-fine{font-size:12.5px;color:var(--mk-dim,rgba(255,255,255,.3));margin-top:10px;line-height:1.5}
.mk-bc-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:var(--mk-r,8px);font-size:14px;font-weight:600;border:none;cursor:pointer;font-family:inherit;background:var(--mk-accent,#BEF264);color:var(--mk-accent-text,#0a0a0a)}
.mk-bc-hp{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden}
.mk-bc-step{display:none}.mk-bc-step.on{display:block}
.mk-bc-loading{opacity:.5;pointer-events:none}
@media (max-width:740px){.mk-bc,.mk-bc-pick,.mk-bc-two{grid-template-columns:1fr}.mk-bc-about{border-right:0;border-bottom:.5px solid var(--mk-border,rgba(255,255,255,.08))}}
</style>
@endonce

<div class="mk-bc {{ $showHost ? '' : 'no-host' }}" id="{{ $widgetId }}" data-book-widget
     data-slots-url="{{ $slotsUrl }}" data-length="{{ $type->length_min }}"
     data-preselect="{{ $oldStart }}" data-move="{{ $isMove ? '1' : '0' }}">
    @if($showHost)
    <aside class="mk-bc-about">
        <div class="mk-bc-who"><div class="av">{{ strtoupper(substr($hostName, 0, 1)) }}</div><div><b>{{ $hostName }}</b><span>{{ $hostTitle }}</span></div></div>
        <h3>{{ $heading ?? ($isMove ? 'Pick a new time' : $type->name) }}</h3>
        @if(!empty($intro ?? $type->description))<p>{{ $intro ?? $type->description }}</p>@endif
        <div class="mk-bc-facts">
            <div>Length <b>{{ $type->length_min }} minutes</b></div>
            <div>Where <b>{{ $whereLabel }}</b></div>
            @if($isMove)
                <div>Currently <b>{{ $booking->startsForBooker()->format('D, M j · g:i a') }}</b></div>
            @else
                <div>After <b>You can reschedule or cancel from the confirmation email.</b></div>
            @endif
        </div>
    </aside>
    @endif

    <div class="mk-bc-pane">
        <form method="POST" action="{{ $action }}" data-book-form>
            @csrf
            <input type="hidden" name="start" value="{{ $oldStart }}" data-start>
            <input type="hidden" name="timezone" value="{{ old('timezone') }}" data-tz-field>
            @unless($isMove)<input type="hidden" name="source_url" value="{{ old('source_url', url()->current()) }}" data-source-url>@endunless
            <div class="mk-bc-hp" aria-hidden="true"><label>Company website <input type="text" name="company_website" tabindex="-1" autocomplete="off"></label></div>

            {{-- step 1: pick a time --}}
            <div class="mk-bc-step on" data-step="1">
                @error('start')<div class="mk-bc-err">{{ $message }}</div>@enderror
                <div class="mk-bc-pick">
                    <div>
                        <div class="mk-bc-mh"><button type="button" data-prev aria-label="Previous month">‹</button><span data-month-label></span><button type="button" data-next aria-label="Next month">›</button></div>
                        <div class="mk-bc-days" data-days></div>
                        <div class="mk-bc-tz">Times shown in <select data-tz></select></div>
                    </div>
                    <div class="mk-bc-slots">
                        <h4 data-day-label>Pick a day</h4>
                        <div data-slots><div class="mk-bc-empty">Loading times…</div></div>
                    </div>
                </div>
            </div>

            {{-- step 2: details (or, when moving, just confirm) --}}
            <div class="mk-bc-step" data-step="2">
                <div class="mk-bc-chosen"><div><b data-chosen-label></b><small>{{ $type->length_min }} minutes</small></div><button type="button" data-change>Change</button></div>
                @if($isMove)
                    <button type="submit" class="mk-bc-btn">Move my call</button>
                    <div class="mk-bc-fine">You'll get a fresh confirmation and calendar file.</div>
                @else
                    @if($errors->any() && !$errors->has('start'))<div class="mk-bc-err">{{ $errors->first() }}</div>@endif
                    <div class="mk-bc-two">
                        <div class="mk-bc-f"><label class="mk-bc-label">Your name</label><input class="mk-bc-input" name="name" value="{{ old('name') }}" required></div>
                        <div class="mk-bc-f"><label class="mk-bc-label">Email</label><input class="mk-bc-input" type="email" name="email" value="{{ old('email') }}" required></div>
                    </div>
                    @if($type->location_mode === 'choice')
                        <div class="mk-bc-f"><label class="mk-bc-label">How would you like to talk?</label>
                            <div class="mk-bc-radios">
                                <label><input type="radio" name="location" value="meet" {{ old('location', 'meet') === 'meet' ? 'checked' : '' }}> Google Meet</label>
                                <label><input type="radio" name="location" value="phone" {{ old('location') === 'phone' ? 'checked' : '' }}> Phone — call me</label>
                            </div></div>
                    @endif
                    <div class="mk-bc-f"><label class="mk-bc-label">Phone @if($type->location_mode !== 'phone')<small>{{ $type->location_mode === 'choice' ? "if we're calling you" : 'optional' }}</small>@endif</label><input class="mk-bc-input" name="phone" value="{{ old('phone') }}" {{ $type->location_mode === 'phone' ? 'required' : '' }}></div>
                    @foreach($type->questionList() as $q)
                        <div class="mk-bc-f">
                            <label class="mk-bc-label">{{ $q['label'] }} @unless($q['required'])<small>optional</small>@endunless</label>
                            @if($q['type'] === 'textarea')
                                <textarea class="mk-bc-input" name="answers[{{ $q['key'] }}]" rows="3" {{ $q['required'] ? 'required' : '' }}>{{ old('answers.' . $q['key']) }}</textarea>
                            @elseif($q['type'] === 'select')
                                <select class="mk-bc-input" name="answers[{{ $q['key'] }}]" {{ $q['required'] ? 'required' : '' }}>
                                    <option value="">Choose…</option>
                                    @foreach($q['options'] as $opt)<option value="{{ $opt }}" {{ old('answers.' . $q['key']) === $opt ? 'selected' : '' }}>{{ $opt }}</option>@endforeach
                                </select>
                            @else
                                <input class="mk-bc-input" name="answers[{{ $q['key'] }}]" value="{{ old('answers.' . $q['key']) }}" {{ $q['required'] ? 'required' : '' }}>
                            @endif
                        </div>
                    @endforeach
                    <button type="submit" class="mk-bc-btn">Book the call</button>
                    <div class="mk-bc-fine">You'll get a confirmation{{ $type->reminder_minutes ? ' and a reminder before the call' : '' }}. No account needed.</div>
                @endif
            </div>
        </form>
    </div>
</div>

@once
<script>
(function () {
  var ZONES = [
    ['America/Los_Angeles','Pacific Time'],['America/Denver','Mountain Time'],['America/Phoenix','Arizona'],
    ['America/Chicago','Central Time'],['America/New_York','Eastern Time'],['America/Anchorage','Alaska'],['Pacific/Honolulu','Hawaii'],
    ['America/Toronto','Toronto'],['America/Vancouver','Vancouver'],['Europe/London','London'],['Europe/Berlin','Central Europe'],['Australia/Sydney','Sydney']
  ];
  var DOW = ['Su','Mo','Tu','We','Th','Fr','Sa'];
  var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  function ymdIn(date, tz) {
    return new Intl.DateTimeFormat('en-CA', {timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit'}).format(date);
  }
  function timeIn(date, tz) {
    return new Intl.DateTimeFormat('en-US', {timeZone: tz, hour: 'numeric', minute: '2-digit'}).format(date).toLowerCase();
  }
  function longIn(date, tz) {
    return new Intl.DateTimeFormat('en-US', {timeZone: tz, weekday: 'long', month: 'long', day: 'numeric'}).format(date);
  }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function init(root) {
    var slotsUrl = root.dataset.slotsUrl;
    var form = root.querySelector('[data-book-form]');
    var daysEl = root.querySelector('[data-days]'), slotsEl = root.querySelector('[data-slots]');
    var monthLabel = root.querySelector('[data-month-label]'), dayLabel = root.querySelector('[data-day-label]');
    var tzSel = root.querySelector('[data-tz]'), tzField = root.querySelector('[data-tz-field]');
    var startField = root.querySelector('[data-start]'), chosenLabel = root.querySelector('[data-chosen-label]');
    var steps = root.querySelectorAll('[data-step]');
    var srcField = root.querySelector('[data-source-url]');
    if (srcField && !srcField.value) srcField.value = location.href;

    var viewerTz = tzField.value || (Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/Los_Angeles');
    var now = new Date();
    var ym = { y: +ymdIn(now, viewerTz).slice(0,4), m: +ymdIn(now, viewerTz).slice(5,7) };
    var minYm = { y: ym.y, m: ym.m };
    var slots = []; var byDay = {}; var selectedDay = null;

    // timezone picker: known zones + the visitor's own if it isn't listed
    var zones = ZONES.slice();
    if (!zones.some(function (z) { return z[0] === viewerTz; })) zones.unshift([viewerTz, viewerTz.replace(/_/g, ' ')]);
    zones.forEach(function (z) { var o = document.createElement('option'); o.value = z[0]; o.textContent = z[1]; if (z[0] === viewerTz) o.selected = true; tzSel.appendChild(o); });
    tzField.value = viewerTz;
    tzSel.addEventListener('change', function () { viewerTz = tzSel.value; tzField.value = viewerTz; bucket(); renderMonth(); renderSlots(); });

    function go(n) { steps.forEach(function (s) { s.classList.toggle('on', s.dataset.step === String(n)); }); }

    function fetchMonth() {
      // host-zone dates a day wider than the visitor's month, so offsets can't drop edge slots
      var first = new Date(Date.UTC(ym.y, ym.m - 1, 1)); first.setUTCDate(0);
      var last = new Date(Date.UTC(ym.y, ym.m, 1)); last.setUTCDate(2);
      var from = first.toISOString().slice(0,10), to = last.toISOString().slice(0,10);
      root.classList.add('mk-bc-loading');
      return fetch(slotsUrl + '?from=' + from + '&to=' + to, {headers: {'Accept': 'application/json'}})
        .then(function (r) { return r.json(); })
        .then(function (j) { slots = (j.slots || []).map(function (s) { return new Date(s); }); bucket(); })
        .catch(function () { slots = []; bucket(); })
        .then(function () { root.classList.remove('mk-bc-loading'); });
    }

    function bucket() {
      byDay = {};
      slots.forEach(function (d) { var k = ymdIn(d, viewerTz); (byDay[k] = byDay[k] || []).push(d); });
      if (selectedDay && !byDay[selectedDay]) selectedDay = null;
    }

    function renderMonth() {
      monthLabel.textContent = MONTHS[ym.m - 1] + ' ' + ym.y;
      daysEl.innerHTML = '';
      DOW.forEach(function (d) { var e = document.createElement('div'); e.className = 'dow'; e.textContent = d; daysEl.appendChild(e); });
      var firstDow = new Date(Date.UTC(ym.y, ym.m - 1, 1)).getUTCDay();
      var dim = new Date(Date.UTC(ym.y, ym.m, 0)).getUTCDate();
      for (var i = 0; i < firstDow; i++) { var b = document.createElement('div'); b.className = 'd'; daysEl.appendChild(b); }
      var firstOk = null;
      for (var day = 1; day <= dim; day++) {
        var key = ym.y + '-' + pad(ym.m) + '-' + pad(day);
        var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'd'; btn.textContent = day;
        if (byDay[key]) {
          btn.classList.add('ok'); if (!firstOk) firstOk = key;
          btn.addEventListener('click', (function (k) { return function () { selectedDay = k; renderMonth(); renderSlots(); }; })(key));
        }
        if (key === selectedDay) btn.classList.add('sel');
        daysEl.appendChild(btn);
      }
      if (!selectedDay && firstOk) { selectedDay = firstOk; renderMonth(); renderSlots(); }
      root.querySelector('[data-prev]').disabled = (ym.y === minYm.y && ym.m === minYm.m);
    }

    function renderSlots() {
      slotsEl.innerHTML = '';
      if (!selectedDay) {
        dayLabel.textContent = 'Pick a day';
        var e = document.createElement('div'); e.className = 'mk-bc-empty';
        e.textContent = Object.keys(byDay).length ? 'Choose a highlighted day.' : 'No open times this month — try the next one.';
        slotsEl.appendChild(e); return;
      }
      var list = byDay[selectedDay] || [];
      dayLabel.textContent = longIn(list[0], viewerTz);
      list.forEach(function (d) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'mk-bc-slot'; b.textContent = timeIn(d, viewerTz);
        b.addEventListener('click', function () { choose(d); });
        slotsEl.appendChild(b);
      });
    }

    function choose(d) {
      startField.value = d.toISOString();
      chosenLabel.textContent = longIn(d, viewerTz) + ' · ' + timeIn(d, viewerTz);
      go(2);
      var first = root.querySelector('[data-step="2"] input:not([type=hidden]):not([type=radio])'); if (first) first.focus();
    }

    root.querySelector('[data-change]').addEventListener('click', function () { startField.value = ''; go(1); });
    root.querySelector('[data-prev]').addEventListener('click', function () { if (ym.m === 1) { ym.y--; ym.m = 12; } else ym.m--; selectedDay = null; fetchMonth().then(function () { renderMonth(); renderSlots(); }); });
    root.querySelector('[data-next]').addEventListener('click', function () { if (ym.m === 12) { ym.y++; ym.m = 1; } else ym.m++; selectedDay = null; fetchMonth().then(function () { renderMonth(); renderSlots(); }); });

    // returning from a failed submit: reopen the details step with the same slot
    var pre = root.dataset.preselect;
    fetchMonth().then(function () {
      renderMonth(); renderSlots();
      if (pre) { var d = new Date(pre); if (!isNaN(d)) { selectedDay = ymdIn(d, viewerTz); choose(d); } }
    });
  }

  function boot() { document.querySelectorAll('[data-book-widget]').forEach(function (r) { if (!r._booted) { r._booted = true; init(r); } }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
</script>
@endonce
