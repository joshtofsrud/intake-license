#!/usr/bin/env bash
# MARKER-LOOKUP-COLORSIZE — surface resolved color/size on the catalog item lookup.
set -e

F="app/Filament/Pages/CatalogItemLookup.php"

if grep -q "MARKER-LOOKUP-COLORSIZE" "$F"; then
  echo "ok: already applied"
  exit 0
fi

python3 - <<'EOF'
import io
p = "app/Filament/Pages/CatalogItemLookup.php"
s = io.open(p, encoding="utf-8").read()
old = """                'item_group'    => $r->item_group,
                'size_id'       => $r->size_id,"""
new = """                'item_group'    => $r->item_group,
                // MARKER-LOOKUP-COLORSIZE — resolved names, not distributor codes
                'color'         => $r->color,
                'size'          => $r->size,
                'size_id'       => $r->size_id,"""
assert s.count(old) == 1, "anchor not found or not unique"
io.open(p, "w", encoding="utf-8").write(s.replace(old, new))
print("ok: color/size added to Classification payload")
EOF

echo "ok: done"
