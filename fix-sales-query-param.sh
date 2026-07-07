#!/usr/bin/env bash
# fix-sales-query-param.sh — HOTFIX for 500 on /admin/sales/campaigns.
#
# Root cause: Filament's evaluate() injects closure args by PARAMETER NAME
# ($query). Three closures named the param $q, so name-matching failed and
# Filament container-resolved the Builder type instead — a fresh builder with
# no model — crashing withCount('prospects') ("prospects() on null") and
# silently breaking the two toggle filters on Prospects.
#
# Run from the repo root:  bash fix-sales-query-param.sh
# Idempotent: guarded on MARKER-SALES-QUERYPARAM in SalesChannelResource.php.
set -euo pipefail

[ -f artisan ] || { echo "ERROR: run from the Laravel repo root."; exit 1; }
if grep -q MARKER-SALES-QUERYPARAM app/Filament/Resources/SalesChannelResource.php; then
  echo "fix-sales-query-param.sh: already applied — skipping."; exit 0
fi

python3 - <<'PYEOF'
def rd(p):
    with open(p, encoding="utf-8") as f: return f.read()
def wr(p, s):
    with open(p, "w", encoding="utf-8") as f: f.write(s)
def edit(p, old, new):
    s = rd(p); n = s.count(old)
    assert n == 1, f"ANCHOR count={n} in {p} (expected 1) for: {old[:70]!r}"
    wr(p, s.replace(old, new, 1)); print(f"  edited {p}")

edit("app/Filament/Resources/SalesChannelResource.php",
"            ->modifyQueryUsing(fn (Builder $q) => $q->withCount('prospects'))",
"            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('prospects')) // MARKER-SALES-QUERYPARAM")

edit("app/Filament/Resources/SalesProspectResource.php",
"                    ->query(fn (Builder $q) => $q->operational())",
"                    ->query(fn (Builder $query) => $query->operational()) // MARKER-SALES-QUERYPARAM")

edit("app/Filament/Resources/SalesProspectResource.php",
"                    ->query(fn (Builder $q) => $q->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', now()))",
"                    ->query(fn (Builder $query) => $query->whereNotNull('next_action_on')->whereDate('next_action_on', '<=', now())) // MARKER-SALES-QUERYPARAM")

print("All edits applied.")
PYEOF

echo ""
echo "Done. PHP change — full clear + fpm bounce required."
