#!/bin/bash
# apply-flash-modal-validation-errors.sh
#
# PATCH-445 removed all per-page success/error banners from the tenant admin
# in favor of one global modal (layouts/tenant/_flash-modal.blade.php), but
# that modal only reads session('success') and session('error'). It never
# reads $errors — Laravel's validation bag. So EVERY validation failure in
# the tenant admin is currently silent: a controller that returns
# back()->withErrors([...]) (or a failed FormRequest) bounces back with the
# fields cleared and no message at all.
#
# Symptom that surfaced this: the account password change "not saving" — a
# wrong current password (or the 10-char / confirmation rules) returned via
# withErrors(), which the modal swallowed. The save itself was fine.
#
# Fix: teach the modal to fall back to the first validation error when there
# is no session('error'). One line. Restores failure visibility across the
# whole tenant admin, not just the password form.
#
# Idempotent: re-running is a no-op once the $errors fallback is present.
# Run from the repo root on the Mac:  bash apply-flash-modal-validation-errors.sh

set -e

FILE="resources/views/layouts/tenant/_flash-modal.blade.php"

if [ ! -f "$FILE" ]; then
  echo "ERROR: $FILE not found — run this from the intake-license repo root." >&2
  exit 1
fi

if grep -q '\$errors->first()' "$FILE"; then
  echo "OK   already patched — \$errors fallback present. No change."
  exit 0
fi

python3 - "$FILE" <<'PYEOF'
import sys

path = sys.argv[1]
src = open(path).read()

# The exact line the modal uses today for its error source.
old = "  $flashErr = session('error');"
new = "  $flashErr = session('error') ?: ((isset($errors) && $errors->any()) ? $errors->first() : null);"

count = src.count(old)
if count != 1:
    print(f"ERROR: expected exactly 1 occurrence of the flashErr line, found {count}.", file=sys.stderr)
    print("The modal source differs from what this patch expects — inspect it before editing.", file=sys.stderr)
    sys.exit(1)

src = src.replace(old, new)
open(path, "w").write(src)
print("OK   patched " + path)
PYEOF

# ---- verify the result compiles as sane PHP and the fallback is in place ----
if ! grep -q '\$errors->first()' "$FILE"; then
  echo "ERROR: post-edit check failed — fallback not found." >&2
  exit 1
fi

php -l "$FILE" >/dev/null 2>&1 && echo "OK   php -l: no syntax errors" || {
  # php -l can't fully parse Blade, so a non-zero here is informational only.
  echo "NOTE php -l couldn't fully lint Blade (expected) — visual check below:"
}

echo ""
echo "Resulting flashErr line:"
grep -n 'flashErr = ' "$FILE"
echo ""
echo "SUCCESS: flash modal now surfaces validation errors. Review with: git diff $FILE"
