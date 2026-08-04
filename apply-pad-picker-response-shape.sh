#!/usr/bin/env bash
# apply-pad-picker-response-shape.sh
# MARKER-PAD-PICKER-SHAPE — "No match" on a shop full of customers.
#
# CustomerController::search() returns:
#
#     response()->json( [ 'customers' => $rows ] )
#
# The picker looked for a bare array, then for `data`, and fell through to an
# empty list — so every search reported no match no matter what was typed.
# I read the map that builds $rows and never read the line that returns it.
#
# Rather than swapping one hardcoded key for another, it now takes the first
# array it finds in the response. A wrapper key is an implementation detail of
# that endpoint, and this picker should not break again if it changes.
set -e

python3 <<'PY'
import io

p = 'resources/views/layouts/tenant/_notes-pad.blade.php'
s = io.open(p, encoding='utf-8').read()

assert 'MARKER-PAD-PICKER-SHAPE' not in s, 'already applied'

old = """          .then( function ( r ) { return r.ok ? r.json() : []; } )
          .then( function ( d ) { render( Array.isArray( d ) ? d : ( d.data || [] ) ); } )"""
assert s.count(old) == 1, 'F1 fetch handler anchor'
s = s.replace(old, """          .then( function ( r ) { return r.ok ? r.json() : []; } )
          .then( function ( d ) { render( rowsOf( d ) ); } )""")

old = """    function render( rows ) {"""
assert s.count(old) == 1, 'F2 render anchor'
s = s.replace(old, """    /* MARKER-PAD-PICKER-SHAPE — the endpoint wraps its rows in a key
       ("customers" today). Hunting for the first array rather than naming
       the key means a rename upstream cannot silently turn every search into
       "No match", which is exactly what happened. */
    function rowsOf( d ) {
      if ( Array.isArray( d ) ) { return d; }
      if ( !d || typeof d !== 'object' ) { return []; }
      for ( var k in d ) {
        if ( Object.prototype.hasOwnProperty.call( d, k ) && Array.isArray( d[ k ] ) ) {
          return d[ k ];
        }
      }
      return [];
    }

    function render( rows ) {""")

io.open(p, 'w', encoding='utf-8').write(s)
print('patched', p)
PY

echo
echo "--- shape handling ---"
grep -n "MARKER-PAD-PICKER-SHAPE\|rowsOf" resources/views/layouts/tenant/_notes-pad.blade.php | head

echo
echo "--- rowsOf handles every shape the endpoint could return ---"
python3 - <<'PY'
import io, re, subprocess, os
raw = io.open('resources/views/layouts/tenant/_notes-pad.blade.php', encoding='utf-8').read()
js = re.findall(r'<script[^>]*>(.*?)</script>', raw, flags=re.S)[0]

# stub @json(...) honouring nested parens
out, i = [], 0
while i < len(js):
    if js.startswith('@json(', i):
        d = 0; j = i + 5
        while j < len(js):
            if js[j] == '(': d += 1
            elif js[j] == ')':
                d -= 1
                if d == 0: break
            j += 1
        out.append('"/x"'); i = j + 1
    else:
        out.append(js[i]); i += 1
js = ''.join(out)

os.makedirs('/tmp/pad5', exist_ok=True)
io.open('/tmp/pad5/p.js','w',encoding='utf-8').write(js)
r = subprocess.run(['node','--check','/tmp/pad5/p.js'], capture_output=True, text=True)
print('  pad JS:', 'OK' if r.returncode==0 else 'FAIL\n'+r.stderr[:300])

# exercise rowsOf directly against the real shape
fn = re.search(r'function rowsOf\( d \) \{.*?\n    \}', js, re.S).group(0)
test = fn.replace('function rowsOf', 'function rowsOf') + """
var cases = [
  [ { customers: [ { id: 1 } ] }, 1 ],
  [ [ { id: 1 }, { id: 2 } ],     2 ],
  [ { data: [ { id: 1 } ] },      1 ],
  [ { customers: [] },            0 ],
  [ null,                         0 ],
  [ { error: 'nope' },            0 ]
];
var bad = 0;
cases.forEach( function ( c, i ) {
  var got = rowsOf( c[0] ).length;
  if ( got !== c[1] ) { console.log( '  case ' + i + ' expected ' + c[1] + ' got ' + got ); bad++; }
} );
console.log( bad === 0 ? '  all shapes OK' : '  ' + bad + ' FAILED' );
"""
io.open('/tmp/pad5/t.js','w',encoding='utf-8').write(test)
r = subprocess.run(['node','/tmp/pad5/t.js'], capture_output=True, text=True)
print(r.stdout.rstrip() or r.stderr[:300])
PY

echo
echo "apply-pad-picker-response-shape: OK"
