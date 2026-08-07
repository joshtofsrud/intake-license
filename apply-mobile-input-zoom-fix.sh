#!/bin/bash
# apply-mobile-input-zoom-fix.sh
#
# iOS Safari auto-zooms the page when a focused input's font-size is under
# 16px. Every Intake form field is 14px, so every tap on a text box zooms.
# Fix: pin input/select/textarea to 16px on phones only (<=767px), via one
# shared partial included in every layout/standalone head that serves a
# browser page. Desktop and iPad are untouched; pinch-zoom stays enabled.
#
# Idempotent: files already carrying MARKER-MOBILE-INPUT-ZOOM are skipped.
# Run from the repo root on the Mac:  bash apply-mobile-input-zoom-fix.sh

set -e

if [ ! -d resources/views ]; then
  echo "ERROR: run this from the intake-license repo root (resources/views not found)." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# 1. The shared partial (pure CSS — no Blade vars, quoted heredoc)
# ---------------------------------------------------------------------------
mkdir -p resources/views/partials

if grep -q "MARKER-MOBILE-INPUT-ZOOM" resources/views/partials/mobile-input-zoom.blade.php 2>/dev/null; then
  echo "OK   partial already exists — skipping create"
else
cat > resources/views/partials/mobile-input-zoom.blade.php <<'EOF'
{{-- MARKER-MOBILE-INPUT-ZOOM
     iOS Safari auto-zooms any focused input whose font-size is under 16px.
     Pin form fields to 16px on phones so the zoom never triggers.
     Scoped to <=767px: desktop and iPad layouts are unchanged. --}}
<style>
@media (max-width: 767px) {
  input[type="text"], input[type="email"], input[type="password"],
  input[type="number"], input[type="search"], input[type="tel"],
  input[type="url"], input[type="date"], input[type="time"],
  input[type="datetime-local"], input[type="month"], input[type="week"],
  select, textarea {
    font-size: 16px !important;
  }
}
</style>
EOF
  echo "OK   created resources/views/partials/mobile-input-zoom.blade.php"
fi

# ---------------------------------------------------------------------------
# 2. Insert @include after the viewport meta in each browser-facing head.
#    Explicit file list (excluded on purpose: emails/*, tenant/print/*,
#    register/receipt, appointments/tag, deliveries/slips, register/display,
#    offline-fallback, errors/_shell, public/coming-soon — no keyboards).
# ---------------------------------------------------------------------------
python3 - <<'PYEOF'
import re, sys

TARGETS = [
    "resources/views/layouts/admin/page-editor.blade.php",
    "resources/views/layouts/tenant/app.blade.php",
    "resources/views/marketing/layout.blade.php",
    "resources/views/marketing/page.blade.php",
    "resources/views/platform/login.blade.php",
    "resources/views/platform/signup-payment.blade.php",
    "resources/views/platform/signup.blade.php",
    "resources/views/public/_booking-shell.blade.php",
    "resources/views/public/account/_shell.blade.php",
    "resources/views/public/confirm.blade.php",
    "resources/views/public/delivery-confirm.blade.php",
    "resources/views/public/layout.blade.php",
    "resources/views/public/rental-confirmed.blade.php",
    "resources/views/public/rental-reserve.blade.php",
    "resources/views/public/rentals.blade.php",
    "resources/views/public/waitlist/_shell.blade.php",
    "resources/views/rep/setup.blade.php",
    "resources/views/tenant/auth/forgot.blade.php",
    "resources/views/tenant/auth/login.blade.php",
    "resources/views/tenant/auth/reset.blade.php",
    "resources/views/tenant/auth/select-location.blade.php",
    "resources/views/tenant/auth/setup.blade.php",
    "resources/views/tenant/auth/switch.blade.php",
    "resources/views/tenant/onboarding/_layout.blade.php",
    "resources/views/tenant/register/checkout-cancel.blade.php",
    "resources/views/tenant/register/checkout-success.blade.php",
]

MARKER  = "MARKER-MOBILE-INPUT-ZOOM"
INCLUDE = "@include('partials.mobile-input-zoom') {{-- MARKER-MOBILE-INPUT-ZOOM --}}"

ok = skipped = 0
failures = []

for path in TARGETS:
    try:
        src = open(path).read()
    except FileNotFoundError:
        failures.append(f"{path}: FILE NOT FOUND")
        continue

    if MARKER in src:
        print(f"SKIP {path} (already patched)")
        skipped += 1
        continue

    lines = src.split("\n")
    hits = [i for i, ln in enumerate(lines) if 'name="viewport"' in ln or "name='viewport'" in ln]
    if len(hits) != 1:
        failures.append(f"{path}: expected exactly 1 viewport meta, found {len(hits)}")
        continue

    i = hits[0]
    indent = re.match(r"[ \t]*", lines[i]).group(0)
    lines.insert(i + 1, indent + INCLUDE)
    open(path, "w").write("\n".join(lines))
    print(f"OK   {path}")
    ok += 1

print(f"\n{ok} patched, {skipped} skipped, {len(failures)} failed")
if failures:
    print("\nFAILED — fix these before committing:")
    for f in failures:
        print("  " + f)
    sys.exit(1)
PYEOF

# ---------------------------------------------------------------------------
# 3. Glue check — no directive may sit against a word character (Blade rule)
# ---------------------------------------------------------------------------
if grep -rE '\w@include\(.partials\.mobile-input-zoom' resources/views --include="*.blade.php" | grep -q .; then
  echo "ERROR: glued @include detected — do not commit:" >&2
  grep -rE '\w@include\(.partials\.mobile-input-zoom' resources/views --include="*.blade.php" >&2
  exit 1
fi

echo ""
echo "SUCCESS: mobile input zoom fix applied. Review with: git diff --stat"
