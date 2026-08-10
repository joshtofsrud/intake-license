#!/usr/bin/env bash
set -euo pipefail
# apply-customizer-js-placement.sh — MARKER-CZJS
# ROOT CAUSE of "nothing in the customizer works".
#
# apply-template-customizer appended the panel's <script> to the END of
# tenant/templates/index.blade.php — which is AFTER its @endsection. The view
# does @extends('layouts.tenant.app'), and Blade discards anything outside a
# section in an extending view, so that script has NEVER been rendered.
#
# Every reported symptom is that one omission:
#   - live preview never repaints
#   - the hidden inputs never receive edits, so Save posts the values the page
#     was seeded with — which is why design_tokens came back holding only
#     _prev, with not a single override stored
#   - Reset and Reset all do nothing (same block)
#   - heading weight, colours, radius: all "not taking"
#
# This moves the block inside the section. No behaviour changes.
#
# NOTE the separate, still-real heading-weight issue documented after this:
# section partials style headings through CLASSES (.p-hero-headline etc), which
# outrank the global h1-h4 rule, so heading weight will still not visibly move
# once JS runs. That is a second patch, not this one.

INDEX=resources/views/tenant/templates/index.blade.php
[ -f "$INDEX" ] || { echo "MISSING $INDEX — run from the repo root"; exit 1; }

grep -q "MARKER-CUSTOMIZER" "$INDEX" \
  || { echo "PRECONDITION FAILED: deploy apply-template-customizer.sh first"; exit 1; }

if grep -q "MARKER-CZJS" "$INDEX"; then
  echo "Already applied (MARKER-CZJS present) — no-op."
  exit 0
fi

python3 - "$INDEX" <<'PY'
import sys
path = sys.argv[1]
src = open(path).read()

end = src.rindex('@endsection')

# Everything after @endsection is the stranded block.
tail = src[end + len('@endsection'):]

if 'MARKER-CUSTOMIZER' not in tail or '<script>' not in tail:
    print("FAIL: no stranded customizer script after @endsection — nothing to move")
    sys.exit(1)

head = src[:end]

moved = ("\n{{-- MARKER-CZJS — this block used to sit past the end of the content\n"
         "     section, where Blade discards it in an extending view, so the\n"
         "     customizer had no JS at all. --}}\n"
         + tail.strip() + "\n\n@endsection\n")

src = head.rstrip() + "\n" + moved
print("ok   customizer JS moved inside @section('content')")

# Guard: exactly one @endsection, and the script now precedes it.
if src.count('@endsection') != 1:
    print(f"FAIL: expected 1 @endsection, found {src.count('@endsection')}"); sys.exit(1)
if src.index('MARKER-CUSTOMIZER --}}\n<script>') > src.index('@endsection'):
    pass  # the panel comment appears earlier too; positional check below is the real one
if src.rindex('</script>') > src.rindex('@endsection'):
    print("FAIL: script still sits after @endsection"); sys.exit(1)
print("ok   script now precedes @endsection")

open(path, 'w').write(src)
PY

echo ""
echo "SUCCESS — apply-customizer-js-placement applied."
echo "Deploy's optimize covers the view cache."
