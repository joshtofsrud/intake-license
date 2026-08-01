#!/bin/bash
# attention-blade-fix — @if glued to a word never compiled.
#
#   The attention page 500s with "syntax error, unexpected token endif".
#
#   Blade's statement regex begins with \B@ — a directive immediately
#   preceded by a WORD character is not treated as a directive. My line was
#
#       ... in stock@if($flagDist) · <span>{{ $flagDist }}</span>@endif</div>
#
#   so `stock@if(...)` stayed literal text while the `@endif` — preceded by
#   `>`, which satisfies \B — compiled normally. The result is an `endif;`
#   with no matching `if(`, and the view fails to compile at all.
#
#   Nothing else in that patch was wrong; the diff is otherwise correct. It
#   is one missing space, and it takes the whole page down rather than
#   degrading, because a Blade compile error is fatal for the file.
#
#   The directive now starts on its own line, which cannot butt against
#   preceding text no matter how the surrounding markup is edited later.
# NO MIGRATION. Server: view:clear
set -e
if grep -q "MARKER-BLADE-WORD-BOUNDARY" resources/views/tenant/distributors/attention.blade.php; then
  echo "attention-blade-fix already applied — aborting."; exit 1
fi

python3 - <<'ABF_0_EOF'
import io
p = 'resources/views/tenant/distributors/attention.blade.php'
s = io.open(p, encoding='utf-8').read()

old = """                <div class="at-dim at-mono" style="font-size:11px">{{ $item->sku ?? '' }} \u00b7 {{ $item->computed_stock_count ?? 0 }} in stock@if($flagDist) \u00b7 <span style="font-weight:700">{{ $flagDist }}</span>@endif</div>"""
assert s.count(old) == 1, ('badge line', s.count(old))

new = """                {{-- MARKER-BLADE-WORD-BOUNDARY — the directive must not touch the
                     word before it. Blade's statement regex starts with \\B@, so
                     a directive glued to the preceding word is left as literal
                     text while the matching
                     @endif compiled, giving an endif with no if and a fatal
                     compile error for the whole view. --}}
                <div class="at-dim at-mono" style="font-size:11px">
                  {{ $item->sku ?? '' }} \u00b7 {{ $item->computed_stock_count ?? 0 }} in stock
                  @if($flagDist)
                    \u00b7 <span style="font-weight:700">{{ $flagDist }}</span>
                  @endif
                </div>"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('blade fix ok')
ABF_0_EOF

# guard against the same mistake anywhere in this file
python3 - <<'ABF_1_EOF'
import io, re
p = 'resources/views/tenant/distributors/attention.blade.php'
s = io.open(p, encoding='utf-8').read()

live = re.sub(r'\{\{--.*?--\}\}', '', s, flags=re.S)   # ignore blade comments
bad = [m.group(0) for m in re.finditer(r'\w@(if|endif|foreach|endforeach|else|elseif|unless|php|endphp)\b', live)]
if bad:
    print('STILL GLUED TO A WORD:', bad)
    raise SystemExit(1)
print('no directives glued to a word character')
ABF_1_EOF

echo
echo "attention-blade-fix applied."
