#!/usr/bin/env bash
# apply-asset-noun-hardcoded-labels.sh
#
# Replaces hardcoded "bike" / "bikes" in tenant-facing UI labels with the
# tenant's configured asset noun (Tenant::$asset_label_singular / _plural).
#
# Every edit is guarded by MARKER-ASSET-NOUN so the script is idempotent —
# re-running it is a no-op. Uses exact string replacement, not regex.
#
# Scope: UI LABELS only. Seeded/sample content (page-builder starter copy,
# the email token preview) is deliberately left alone — see the report.
set -euo pipefail
cd "$(dirname "$0")"

python3 - <<'PYEOF'
import sys

S = "tenant()->asset_label_singular ?: 'item'"
P = "tenant()->asset_label_plural ?: 'items'"

EDITS = {
"resources/views/tenant/appointments/show-multi-asset.blade.php": [
  ("Attach a bike, vehicle, or other item to this appointment.",
   "Attach a {{ " + S + " }} to this appointment."),
  ("+ Add service or add-on to this bike",
   "+ Add service or add-on to this {{ " + S + " }}"),
  ('placeholder="+ Add product or custom item to this bike…"',
   'placeholder="+ Add product or custom item to this {{ ' + S + ' }}…"'),
  ("actionLabel: 'Add to this bike',",
   "actionLabel: 'Add to this ' + (window.maLooseAssetSingular || 'item'),"),
],
"resources/views/tenant/deliveries/index.blade.php": [
  ('<div class="sub">Bike to shop</div>',
   '<div class="sub">{{ ucfirst(' + S + ') }} to shop</div>'),
  ('<label class="del-label">Bikes on this run</label>',
   '<label class="del-label">{{ ucfirst(' + P + ') }} on this run</label>'),
  ("No saved bikes for this customer.",
   "No saved {{ " + P + " }} for this customer."),
  ("placeholder=\"Gate code, dog warning, where to leave the bike…\"",
   "placeholder=\"Gate code, dog warning, where to leave the {{ " + S + " }}…\""),
],
"resources/views/tenant/customers/show.blade.php": [
  ("Bikes, vehicles, or other items that belong to this customer.",
   "{{ ucfirst(" + P + ") }} that belong to this customer."),
  ("to add the customer's first bike, vehicle, or pet.",
   "to add the customer's first {{ " + S + " }}."),
],
"resources/views/tenant/_appt-drawer.blade.php": [
  ("sec('Bikes / assets', ah)",
   "sec(@json(ucfirst(" + P + ")) + ' / assets', ah)"),
],
"resources/views/tenant/work-order-fields/index.blade.php": [
  ("Fields your team fills in when receiving a bike.",
   "Fields your team fills in when receiving a {{ " + S + " }}."),
  ("Usually the bike's serial number.",
   "Usually the {{ " + S + " }}'s serial number."),
],
"resources/views/tenant/appointments/tag.blade.php": [
  ("<tr><td>BIKE</td>",
   "<tr><td>{{ strtoupper(" + S + ") }}</td>"),
],
}

MARKER = "MARKER-ASSET-NOUN"
total = 0

for path, pairs in EDITS.items():
    src = open(path, encoding="utf-8").read()
    if MARKER in src:
        print(f"  skip (already patched)  {path}")
        continue
    n = 0
    for old, new in pairs:
        c = src.count(old)
        if c == 0:
            print(f"  !! NOT FOUND in {path}: {old[:56]}")
            sys.exit(1)
        src = src.replace(old, new)
        n += c
    # stamp the marker as a Blade comment on the first line
    src = "{{-- " + MARKER + " — asset labels read tenant()->asset_label_* --}}\n" + src
    open(path, "w", encoding="utf-8").write(src)
    print(f"  patched {n:2d} label(s)  {path}")
    total += n

print(f"\n  {total} hardcoded asset labels replaced.")
PYEOF

echo
echo "  Next: php artisan optimize:clear  (view cache)"
