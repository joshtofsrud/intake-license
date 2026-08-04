#!/usr/bin/env bash
# apply-old-school-pad-customer.sh
# MARKER-PAD-CUSTOMER — attach a customer from the pad.
#
# The pad only ever showed "no customer", with no way to set one: a customer
# arrived solely from a page that pre-filled $noteCustomer, which so far is
# only the appointment page. Everywhere else the note had no way to say who
# it was about.
#
# NOT the shared <x-tenant.customer-search> component, deliberately. That
# component ships its styles with @once @push('styles'), and the pad is
# included FROM THE LAYOUT — after @stack('styles') has already rendered in
# <head>. Pushes made after a stack renders are dropped silently, so the
# picker would be styled on pages that happen to use the component elsewhere
# and unstyled everywhere else. A self-contained picker has no such ordering
# dependency.
#
# It calls the same endpoint the component does, so results and permissions
# stay identical.
#
# Pre-filled beats picked: where a page sets $noteCustomer the chip is shown
# already attached and the search stays out of the way behind "change".
set -e

python3 <<'PY'
import io

p = 'resources/views/layouts/tenant/_notes-pad.blade.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-PAD-CUSTOMER' not in s, 'already applied'

# Stage one put a conditional hidden customer_id near the top of the form.
# The picker below is now the single source of truth, and two fields of the
# same name would resolve by document order rather than by intent.
old = """      @if($padCustomer)
        <input type="hidden" name="customer_id" value="{{ $padCustomer->id }}">
      @endif
"""
assert s.count(old) == 1, 'P0 stage-one hidden field anchor'
s = s.replace(old, '')

old = """      <div class="pad-new-foot">
        @if($padCustomer)
          <span class="pad-chip">{{ $padCustomer->first_name }} {{ $padCustomer->last_name }}</span>
        @else
          <span class="pad-hint">no customer</span>
        @endif
        <button type="submit" class="pad-add">Add note</button>
      </div>"""
assert s.count(old) == 1, 'P1 pad foot anchor'
s = s.replace(old, """      {{-- MARKER-PAD-CUSTOMER — the hidden field is the single source of
           truth for who the note is about. The chip and the search only ever
           write to it. --}}
      <input type="hidden" name="customer_id" data-pad-cid value="{{ $padCustomer->id ?? '' }}">

      <div class="pad-pick" data-pad-pick @if($padCustomer) hidden @endif>
        <input type="text" class="pad-search" data-pad-search autocomplete="off"
               placeholder="Attach a customer (optional)…">
        <div class="pad-results" data-pad-results hidden></div>
      </div>

      <div class="pad-new-foot">
        <span class="pad-chip" data-pad-chip @unless($padCustomer) hidden @endunless>
          <span data-pad-chip-name>{{ $padCustomer ? $padCustomer->first_name . ' ' . $padCustomer->last_name : '' }}</span>
          <button type="button" data-pad-chip-clear aria-label="Detach">×</button>
        </span>
        <span class="pad-hint" data-pad-hint @if($padCustomer) hidden @endif>no customer</span>
        <button type="submit" class="pad-add">Add note</button>
      </div>""")

# styles
old = """  .pad-hint { font-size:11px; color:#7A7159; }"""
assert s.count(old) == 1, 'P2 style anchor'
s = s.replace(old, """  .pad-hint { font-size:11px; color:#7A7159; }
  /* MARKER-PAD-CUSTOMER */
  .pad-pick { position:relative; margin-top:8px; }
  .pad-search { width:100%; border:1px solid #D9CDB0; border-radius:8px; padding:7px 10px;
                font-family:inherit; font-size:12.5px; background:#FBF7EC; color:#2A2419; }
  .pad-search:focus { outline:none; border-color:#B8860B; }
  .pad-results { position:absolute; left:0; right:0; top:100%; margin-top:4px; z-index:5;
                 background:#FBF7EC; border:1px solid #D9CDB0; border-radius:8px; overflow:hidden;
                 max-height:190px; overflow-y:auto; box-shadow:0 6px 18px rgba(0,0,0,.22); }
  .pad-res { display:block; width:100%; text-align:left; background:none; border:0; cursor:pointer;
             padding:7px 10px; font-family:inherit; font-size:12.5px; color:#2A2419;
             border-bottom:1px solid #EDE3CC; }
  .pad-res:last-child { border-bottom:0; }
  .pad-res:hover, .pad-res.on { background:#EDE3CC; }
  .pad-res small { display:block; color:#7A7159; font-size:10.5px; margin-top:1px; }
  .pad-none { padding:9px 10px; font-size:12px; color:#7A7159; }
  .pad-chip button { background:none; border:0; color:#33452C; cursor:pointer; font-size:13px;
                     line-height:1; padding:0 0 0 5px; opacity:.6; }
  .pad-chip button:hover { opacity:1; }""")

# script — appended inside the existing IIFE, before its close
old = """  window.addEventListener( 'resize', function () {
    if ( !panel.hasAttribute( 'hidden' ) ) { place(); }
  } );
}() );"""
assert s.count(old) == 1, 'P3 script tail anchor'
s = s.replace(old, """  window.addEventListener( 'resize', function () {
    if ( !panel.hasAttribute( 'hidden' ) ) { place(); }
  } );

  /* MARKER-PAD-CUSTOMER — a small picker against the same endpoint the
     shared component uses. Kept inside this IIFE so nothing it declares can
     collide with another script on the page. */
  var cid     = panel.querySelector( '[data-pad-cid]' );
  var pick    = panel.querySelector( '[data-pad-pick]' );
  var search  = panel.querySelector( '[data-pad-search]' );
  var results = panel.querySelector( '[data-pad-results]' );
  var chip    = panel.querySelector( '[data-pad-chip]' );
  var chipNm  = panel.querySelector( '[data-pad-chip-name]' );
  var chipX   = panel.querySelector( '[data-pad-chip-clear]' );
  var hint    = panel.querySelector( '[data-pad-hint]' );

  if ( cid && pick && search && results && chip && chipNm && chipX && hint ) {
    var searchUrl = @json(route('tenant.customers.search', []));
    var timer = null;

    function attach( id, name ) {
      cid.value = id;
      chipNm.textContent = name;
      chip.removeAttribute( 'hidden' );
      hint.setAttribute( 'hidden', '' );
      pick.setAttribute( 'hidden', '' );
      results.setAttribute( 'hidden', '' );
      search.value = '';
    }

    function detach() {
      cid.value = '';
      chip.setAttribute( 'hidden', '' );
      hint.removeAttribute( 'hidden' );
      pick.removeAttribute( 'hidden' );
      search.focus();
    }

    chipX.addEventListener( 'click', detach );

    function render( rows ) {
      results.innerHTML = '';
      if ( !rows.length ) {
        var none = document.createElement( 'div' );
        none.className = 'pad-none';
        none.textContent = 'No match.';
        results.appendChild( none );
        results.removeAttribute( 'hidden' );
        return;
      }
      rows.slice( 0, 8 ).forEach( function ( c ) {
        var b = document.createElement( 'button' );
        b.type = 'button';
        b.className = 'pad-res';
        // textContent throughout — a customer name is user data and must
        // never be written as markup.
        b.textContent = c.name || ( ( c.first_name || '' ) + ' ' + ( c.last_name || '' ) ).trim();
        var sub = c.email || c.phone || '';
        if ( sub ) {
          var s2 = document.createElement( 'small' );
          s2.textContent = sub;
          b.appendChild( s2 );
        }
        b.addEventListener( 'click', function () {
          attach( c.id, b.firstChild ? b.firstChild.textContent : 'customer' );
        } );
        results.appendChild( b );
      } );
      results.removeAttribute( 'hidden' );
    }

    search.addEventListener( 'input', function () {
      var q = search.value.trim();
      if ( timer ) { clearTimeout( timer ); }
      if ( q.length < 2 ) { results.setAttribute( 'hidden', '' ); return; }
      timer = setTimeout( function () {
        fetch( searchUrl + '?q=' + encodeURIComponent( q ), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
          } )
          .then( function ( r ) { return r.ok ? r.json() : []; } )
          .then( function ( d ) { render( Array.isArray( d ) ? d : ( d.data || [] ) ); } )
          .catch( function () { results.setAttribute( 'hidden', '' ); } );
      }, 250 );
    } );

    search.addEventListener( 'keydown', function ( e ) {
      if ( e.key === 'Escape' ) { results.setAttribute( 'hidden', '' ); e.stopPropagation(); }
    } );
  }
}() );""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- picker wired ---"
grep -n "MARKER-PAD-CUSTOMER\|data-pad-cid\|data-pad-search\|data-pad-chip" resources/views/layouts/tenant/_notes-pad.blade.php | head

echo
echo "--- exactly one customer_id field in the form ---"
python3 - <<'PY'
import io, re
s = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
n = len(re.findall(r'name="customer_id"', s))
print('  customer_id inputs:', n, 'OK' if n == 1 else '*** two would fight, last wins ***')
PY

echo
echo "--- blade sweep ---"
python3 - <<'PY'
import io, re, subprocess, os
pat = re.compile(r'\B@(@?\w+(?:::\w+)?)([ \t]*)(\()?', re.X)
OPEN  = {'if','unless','isset','auth','guest','forelse','foreach','for','while','php','section','error','once'}
CLOSE = {'endif','endunless','endisset','endempty','endauth','endguest','endforelse','endforeach','endfor','endwhile','endphp','endsection','enderror','endonce'}
f = 'resources/views/layouts/tenant/_notes-pad.blade.php'
raw = io.open(f, encoding='utf-8').read()
s = re.sub(r'\{\{--.*?--\}\}', lambda m: ' '*len(m.group(0)), raw, flags=re.S)
print('  glued:', len(re.findall(r'\w@(?:if|endif|unless|endunless|foreach|endforeach|forelse|endforelse|else|elseif|php|endphp|csrf|json)\b', s)))
d = 0
for m in re.finditer(r'@(\w+)', s):
    if not pat.match(s, m.start()): continue
    if m.group(1) in OPEN: d += 1
    elif m.group(1) in CLOSE: d -= 1
print('  directive depth:', d, 'OK' if d == 0 else '*** CHECK ***')

os.makedirs('/tmp/pad3', exist_ok=True)
js = re.findall(r'<script[^>]*>(.*?)</script>', raw, flags=re.S)[0]
# @json(...) is Blade; stub it so node can parse the rest.
js = re.sub(r'@json\([^)]*\)', '"/x"', js)
io.open('/tmp/pad3/p.js','w',encoding='utf-8').write(js)
r = subprocess.run(['node','--check','/tmp/pad3/p.js'], capture_output=True, text=True)
print('  pad JS:', 'OK' if r.returncode==0 else 'FAIL\n'+r.stderr[:400])
PY

echo
echo "--- customer names are never written as markup ---"
python3 - <<'PY'
import io, re
s = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
js = re.findall(r'<script[^>]*>(.*?)</script>', s, flags=re.S)[0]
bad = re.findall(r'innerHTML\s*=\s*(?!\'\')(?!"")', js)
print('  innerHTML assignments:', len(bad), '(only the clear-to-empty is allowed)')
print('  uses textContent     :', js.count('textContent'))
PY

echo
echo "apply-old-school-pad-customer: OK"
