#!/usr/bin/env bash
# apply-old-school-banner-appointment.sh
# MARKER-OLD-SCHOOL-BANNER — stage two: the pad stops hiding, and notes reach
# the appointment they were written about.
#
# THE STACKING FIX. The panel is position:fixed at z-index 9000 and still
# painted behind cards that declare no z-index at all — which cannot happen
# on value alone. That is the signature of an ancestor stacking context: a
# parent with position + z-index (the sticky bars in the tenant layout do
# this) traps a fixed child, and the child's 9000 is then compared only
# against its siblings inside that context, not against the page.
#
# Raising the number would appear to work in some layouts and fail in others.
# The panel is instead REPARENTED TO <body> the first time it opens, which
# leaves every ancestor context behind. It is also lifted to 9990 — above
# page furniture, deliberately below the 9999 used by real modal dialogs, so
# a dialog still covers it rather than the pad floating over a confirmation.
#
# THE BANNER. An open note about this customer appears on their appointment,
# with the text in it rather than a count — "Mike has 2 open notes" makes you
# go looking at the busiest moment, which is the opposite of the point. Each
# line can be crossed off from here, because a note should be closed where it
# is read.
#
# The page also sets $noteCustomer, so pressing the pad button while looking
# at this appointment pre-attaches the customer. That is the whole friction
# saving: the note already knows who it is about.
set -e

python3 <<'PY'
import io

# ---------------------------------------------------------------- stacking
p = 'resources/views/layouts/tenant/_notes-pad.blade.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-OLD-SCHOOL-BANNER' not in s, 'already applied'

old = """  .pad-panel { position:fixed; width:320px; z-index:9000; border-radius:12px; overflow:hidden;"""
assert s.count(old) == 1, 'S1 panel css anchor'
s = s.replace(old, """  /* MARKER-OLD-SCHOOL-BANNER — 9990: above page furniture, below the 9999
     that real modals use, so a dialog still covers the pad. The number alone
     is not enough; see the reparent in the script below. */
  .pad-panel { position:fixed; width:320px; z-index:9990; border-radius:12px; overflow:hidden;""")

old = """  btn.addEventListener( 'click', function ( e ) {
    e.stopPropagation();
    var showing = !panel.hasAttribute( 'hidden' );
    if ( showing ) { panel.setAttribute( 'hidden', '' ); return; }
    place();
    panel.removeAttribute( 'hidden' );
    var ta = panel.querySelector( 'textarea' );
    if ( ta ) { ta.focus(); }
  } );"""
assert s.count(old) == 1, 'S2 toggle anchor'
s = s.replace(old, """  // MARKER-OLD-SCHOOL-BANNER — move the panel to <body> before it is ever
  // shown. A fixed element inside an ancestor that has position + z-index is
  // trapped in that ancestor's stacking context, so it can be painted behind
  // page content that declares no z-index at all. Reparenting escapes every
  // such context; raising the number would only appear to fix it.
  var moved = false;
  function liftOut() {
    if ( moved ) { return; }
    document.body.appendChild( panel );
    moved = true;
  }

  btn.addEventListener( 'click', function ( e ) {
    e.stopPropagation();
    var showing = !panel.hasAttribute( 'hidden' );
    if ( showing ) { panel.setAttribute( 'hidden', '' ); return; }
    liftOut();
    place();
    panel.removeAttribute( 'hidden' );
    var ta = panel.querySelector( 'textarea' );
    if ( ta ) { ta.focus(); }
  } );""")

# Once the panel lives on <body> it is no longer inside .pad, so the
# click-outside test has to allow it explicitly or the first click inside
# the panel closes it.
old = """  document.addEventListener( 'click', function ( e ) {
    if ( !panel.hasAttribute( 'hidden' ) && !wrap.contains( e.target ) ) {
      panel.setAttribute( 'hidden', '' );
    }
  } );"""
assert s.count(old) == 1, 'S3 outside-click anchor'
s = s.replace(old, """  document.addEventListener( 'click', function ( e ) {
    // panel.contains is required as well as wrap.contains: after liftOut()
    // the panel is a child of <body>, so a click inside it is no longer
    // inside .pad and would otherwise close the thing being typed into.
    if ( !panel.hasAttribute( 'hidden' )
         && !wrap.contains( e.target )
         && !panel.contains( e.target ) ) {
      panel.setAttribute( 'hidden', '' );
    }
  } );""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

cat <<'EOF' > resources/views/tenant/_notes-banner.blade.php
{{-- MARKER-OLD-SCHOOL-BANNER — open notes about one customer.

     Expects $bannerCustomer. Renders nothing when there is nothing open, so
     it can be included unconditionally.

     The note TEXT is here, not a count. A count sends someone looking at the
     moment they are least able to, which is the opposite of what the pad is
     for. --}}
@php
  $bnNotes = $bannerCustomer
      ? \App\Models\Tenant\TenantNote::where('tenant_id', tenant()->id)
          ->where('customer_id', $bannerCustomer->id)
          ->whereNull('completed_at')
          ->with('author')
          ->orderBy('created_at')
          ->get()
      : collect();
@endphp

@if($bnNotes->count())
  <div class="nb">
    <div class="nb-head">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 4h11l5 5v11H4z"/><path d="M8 10h8M8 14h5"/>
      </svg>
      Open notes on {{ $bannerCustomer->first_name }} {{ $bannerCustomer->last_name }}
      <span class="nb-n">{{ $bnNotes->count() }}</span>
    </div>

    @foreach($bnNotes as $bn)
      <div class="nb-line">
        <form method="POST" action="{{ route('tenant.notes.toggle', $bn->id) }}">
          @csrf
          <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
          <button type="submit" class="nb-box" aria-label="Cross off"></button>
        </form>
        <div class="nb-body">
          <div class="nb-text">{{ $bn->body }}</div>
          <div class="nb-meta">
            {{ $bn->author?->name ?? 'someone' }} · {{ $bn->created_at?->diffForHumans() }}
            @if($bn->ageInDays() >= 14)
              <span class="nb-age">still open after {{ $bn->ageInDays() }} days</span>
            @endif
          </div>
        </div>
      </div>
    @endforeach

    <div class="nb-foot">Tick one to cross it off — it disappears from here straight away.</div>
  </div>

  <style>
    .nb { background:#F4ECD8; color:#2A2419; border-radius:10px; padding:11px 13px; margin-bottom:16px;
          box-shadow:0 2px 0 rgba(0,0,0,.20); }
    .nb-head { display:flex; align-items:center; gap:8px; font-size:10.5px; font-weight:700;
               letter-spacing:.06em; text-transform:uppercase; color:#7A7159; margin-bottom:8px; }
    .nb-n { margin-left:auto; font-size:11.5px; font-weight:600; letter-spacing:0; text-transform:none; }
    .nb-line { display:flex; gap:11px; align-items:flex-start; padding:8px 0; border-top:1px solid #D9CDB0; }
    .nb-box { width:19px; height:19px; border:1.6px solid #8D8267; border-radius:4px; background:#FBF7EC;
              cursor:pointer; flex:none; margin-top:1px; padding:0; }
    .nb-box:hover { background:#8D8267; }
    .nb-body { flex:1; min-width:0; }
    .nb-text { font-size:13.5px; line-height:1.5; word-break:break-word; }
    .nb-meta { font-size:10.5px; color:#7A7159; margin-top:4px; }
    .nb-age { color:#A8622A; font-weight:600; margin-left:6px; }
    .nb-foot { border-top:1px solid #D9CDB0; margin-top:6px; padding-top:8px; font-size:11px; color:#7A7159; }
  </style>
@endif
EOF
echo "created resources/views/tenant/_notes-banner.blade.php"

python3 <<'PY'
import io

p = 'resources/views/tenant/appointments/show.blade.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-OLD-SCHOOL-BANNER' not in s, 'already applied'

old = """@if($bannerPendingLink)"""
assert s.count(old) == 1, 'A1 payment banner anchor'
s = s.replace(old, """{{-- MARKER-OLD-SCHOOL-BANNER — above the payment banners: a note written
     about this person is context for everything below it, including whether
     to take the money yet. Also sets $noteCustomer so the pad button
     pre-attaches them. --}}
@php $noteCustomer = $appointment->customer ?? null; @endphp
@include('tenant._notes-banner', ['bannerCustomer' => $noteCustomer])

@if($bannerPendingLink)""", 1)

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- wiring ---"
grep -n "MARKER-OLD-SCHOOL-BANNER" resources/views/layouts/tenant/_notes-pad.blade.php resources/views/tenant/appointments/show.blade.php | head

echo
echo "--- panel is reparented AND click-outside allows it ---"
python3 - <<'PY'
import io
s = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
print('  liftOut defined      :', 'function liftOut' in s)
print('  liftOut called first :', s.index('liftOut();') < s.index('place();', s.index('liftOut();')-200))
print('  panel.contains guard :', 'panel.contains( e.target )' in s)
print('  z-index 9990         :', 'z-index:9990' in s)
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re, subprocess, os
pat = re.compile(r'\B@(@?\w+(?:::\w+)?)([ \t]*)(\()?', re.X)
OPEN  = {'if','unless','isset','auth','guest','forelse','foreach','for','while','php','section','error'}
CLOSE = {'endif','endunless','endisset','endempty','endauth','endguest','endforelse','endforeach','endfor','endwhile','endphp','endsection','enderror'}
for f in ['resources/views/tenant/_notes-banner.blade.php',
          'resources/views/tenant/appointments/show.blade.php',
          'resources/views/layouts/tenant/_notes-pad.blade.php']:
    raw = io.open(f, encoding='utf-8').read()
    s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' '*len(m.group(0)), raw, flags=re.S)
    glued = len(re.findall(r'\w@(?:if|endif|foreach|endforeach|forelse|endforelse|else|elseif|php|endphp|csrf|include)\b', s))
    d = 0
    for m in re.finditer(r'@(\w+)', s):
        if not pat.match(s, m.start()): continue
        if m.group(1) in OPEN: d += 1
        elif m.group(1) in CLOSE: d -= 1
    print('  %-46s glued=%d depth=%d %s' % (f.split('/')[-1], glued, d, 'OK' if (glued == 0 and d == 0) else '*** CHECK ***'))
    for m in re.finditer(r'@php(.*?)@endphp', raw, re.S):
        if '{{--' in m.group(1):
            print('     *** blade comment inside @php ***')

os.makedirs('/tmp/pad2', exist_ok=True)
js = re.findall(r'<script[^>]*>(.*?)</script>',
                io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read(), flags=re.S)[0]
io.open('/tmp/pad2/p.js','w',encoding='utf-8').write(js)
r = subprocess.run(['node','--check','/tmp/pad2/p.js'], capture_output=True, text=True)
print('  pad JS:', 'OK' if r.returncode==0 else 'FAIL\n'+r.stderr[:300])
PY

echo
echo "--- banner forms are not nested in the appointment page ---"
python3 - <<'PY'
import io, re
s = io.open('resources/views/tenant/appointments/show.blade.php', encoding='utf-8').read()
d = 0; nested = False
for m in re.finditer(r'<form\b|</form>', s):
    d += -1 if m.group(0) == '</form>' else 1
    if d > 1: nested = True
print('  appointments/show forms balanced=%s nested=%s' % (d == 0, nested))
PY

echo
echo "apply-old-school-banner-appointment: OK"
