#!/usr/bin/env bash
# fix-sales-widget-registration.sh — HOTFIX for 500 on /admin/sales/prospects
# (page loads, then dies on the Livewire lazy-load of the funnel widget:
#  "Unable to find component: [...sales-funnel-widget]").
#
# Root cause: the widget was returned from ListSalesProspects::getHeaderWidgets()
# but never declared in SalesProspectResource::getWidgets() — which is what
# registers it as a Livewire component. The lazy /livewire/update request then
# has no component to resolve.
#
# Run from the repo root:  bash fix-sales-widget-registration.sh
# Idempotent: guarded on MARKER-SALES-WIDGETREG.
set -euo pipefail

[ -f artisan ] || { echo "ERROR: run from the Laravel repo root."; exit 1; }
if grep -q MARKER-SALES-WIDGETREG app/Filament/Resources/SalesProspectResource.php; then
  echo "fix-sales-widget-registration.sh: already applied — skipping."; exit 0
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

edit("app/Filament/Resources/SalesProspectResource.php",
"    public static function getPages(): array",
"""    // MARKER-SALES-WIDGETREG — registers the funnel widget as a Livewire component
    public static function getWidgets(): array
    {
        return [
            \\App\\Filament\\Resources\\SalesProspectResource\\Widgets\\SalesFunnelWidget::class,
        ];
    }

    public static function getPages(): array""")

print("Edit applied.")
PYEOF

echo ""
echo "Done. PHP change — re-cache Filament components + full clear + fpm bounce."
