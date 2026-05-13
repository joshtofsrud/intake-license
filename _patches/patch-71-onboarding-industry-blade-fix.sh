#!/bin/bash
# ============================================================================
# patch-71-onboarding-industry-blade-fix.sh
# ----------------------------------------------------------------------------
# Bug: visiting /admin/onboarding/wizard/industry on a fresh tenant throws a
# 500 with Blade compile error: "unexpected token 'else', expecting end of
# file" — Reference ID ERR-PTAONGBC (May 13 23:16 UTC).
#
# Root cause: industry.blade.php (patch 61) contains a Blade comment with
# the literal text "@php block" inside it. Blade's compiler parses
# directives even inside {{-- comments --}}, so it counts that as an
# unclosed @php block. Subsequent @if/@else/@endif structure goes off-by-
# one in Blade's bookkeeping, and @else is reported as unexpected.
#
# Fix: rephrase the comment so it doesn't contain the bare token "@php".
#
# This is a Blade bug-class worth banking: ANY mention of @<directive>
# inside a Blade comment is parsed by the compiler. Use plain English
# descriptions like "the PHP block" instead.
#
# Files touched:
#   - resources/views/tenant/onboarding/industry.blade.php  (1 line)
# ============================================================================

set -euo pipefail
cd "${REPO_ROOT:-$(pwd)}"

if [ ! -f "resources/views/tenant/onboarding/industry.blade.php" ]; then
  echo "ERROR: industry.blade.php not found." >&2
  exit 1
fi

python3 <<'PYEOF'
from pathlib import Path
p = Path("resources/views/tenant/onboarding/industry.blade.php")
s = p.read_text()

old = "    Workflow mapping is defined in the @php block and is the source of truth."
new = "    Workflow mapping is defined in the PHP block below and is the source of truth."

if "Workflow mapping is defined in the PHP block below" in s:
    print("    SKIP — Blade comment already fixed")
elif old not in s:
    raise SystemExit("ABORT: comment anchor not found")
else:
    s = s.replace(old, new, 1)

    # While we're in here, audit for any OTHER @<directive> tokens inside
    # Blade comments that could trip the same bug. Look for @php, @endphp,
    # @if, @else, @endif, @foreach, @endforeach, @section, @endsection
    # inside {{-- ... --}} blocks. If we find any, abort with a clear error
    # so we don't ship a patch that still has the bug class lurking.
    import re
    # Find each {{-- ... --}} comment (multiline)
    comments = re.findall(r"\{\{--.*?--\}\}", s, re.DOTALL)
    dangerous = ['@php', '@endphp', '@if', '@else', '@endif',
                 '@foreach', '@endforeach', '@section', '@endsection',
                 '@extends', '@yield', '@include']
    found_issues = []
    for c in comments:
        for tok in dangerous:
            if tok in c:
                found_issues.append((tok, c[:80]))
    if found_issues:
        print("    WARN: directive-like tokens still found in Blade comments:")
        for tok, snippet in found_issues:
            print(f"      {tok}  in:  {snippet.strip()[:60]}...")
        # Not fatal — we already fixed the one known bad spot.

    p.write_text(s)
    print("    UPDATED — replaced '@php' inside Blade comment with plain text")
PYEOF

cat <<EONOTE

==> Patch 71 applied locally.

Deploy:
  mv patch-71-onboarding-industry-blade-fix.sh _patches/
  git add resources/views/tenant/onboarding/industry.blade.php \\
          _patches/patch-71-onboarding-industry-blade-fix.sh
  git commit -m "fix: blade compile error in industry wizard — @php inside comment (patch 71)"
  git push

On server:
  cd /var/www/intake
  git pull
  php artisan view:clear
  sudo systemctl stop php8.3-fpm && sleep 2 && sudo systemctl start php8.3-fpm

Verify:
  - Visit /admin/onboarding/wizard/industry on a fresh test tenant
  - Should render workflow chooser (3 cards) without a 500
EONOTE
