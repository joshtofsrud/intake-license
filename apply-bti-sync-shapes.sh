#!/bin/bash
# bti-sync-shapes — BTI cost and availability actually reach the pivot.
#
#   Even with valid credentials, BTI's numbers would parse to nothing.
#
#   TenantDistributorSyncService is HLC-shaped, like the catalog sync was.
#   normalizeInventory() looks for 'VariantNo' / 'Sku' / 'SKU' and quantity
#   under 'TotalQtyAvailable' / 'Available' / 'Quantity'; fetchCosts() reads
#   'VariantNo' and a 'Prices' array. BtiClient returned lowercase 'sku',
#   'available' and 'cost_cents' — and PHP array keys are case-sensitive, so
#   every row was skipped silently. No error, just no cost and no quantity.
#
#   Fixed in the adapter, not the service, for the same reason as before: the
#   service runs HLC's nightly cost and availability sync for a live shop,
#   and BTI is not worth putting that at risk. The adapter is the layer whose
#   job is to speak the platform's shape.
#
#   inventory() now returns VariantNo + TotalQtyAvailable, with the per-
#   warehouse split kept under 'Warehouses' in the shape the fallback branch
#   already understands (QtyAvailable per row), so Santa Fe and Reno still
#   sum correctly if the top-level total is ever absent.
#
#   prices() returns VariantNo + a Prices[] array. HLC sends tiers there and
#   the service picks a cost from them, so BTI's single dealer price is
#   emitted as one tier rather than invented as a different field.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-BTI-SYNC-SHAPES" app/Services/Distributors/BtiClient.php; then
  echo "bti-sync-shapes already applied — aborting."; exit 1
fi

python3 - <<'BSS_0_EOF'
import io
p = 'app/Services/Distributors/BtiClient.php'
s = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------- inventory
old = """            $out[] = [
                'sku'        => $id,
                'available'  => (int) ($r['available'] ?? 0),
                'warehouses' => [
                    ['code' => 'santa_fe', 'available' => (int) ($r['available_santa_fe'] ?? 0)],
                    ['code' => 'reno',     'available' => (int) ($r['available_reno'] ?? 0)],
                ],
            ];"""
assert s.count(old) == 1, ('inventory', s.count(old))
new = """            // MARKER-BTI-SYNC-SHAPES — the platform's key names, not BTI's.
            // TenantDistributorSyncService::normalizeInventory looks for
            // VariantNo and TotalQtyAvailable; lowercase 'sku'/'available'
            // matched nothing and every row was skipped without an error.
            $out[] = [
                'VariantNo'          => $id,
                'TotalQtyAvailable'  => (int) ($r['available'] ?? 0),
                // Kept in the shape the service's fallback branch reads, so
                // the two warehouses still sum if the total is ever missing.
                'Warehouses' => [
                    ['Code' => 'santa_fe', 'QtyAvailable' => (int) ($r['available_santa_fe'] ?? 0)],
                    ['Code' => 'reno',     'QtyAvailable' => (int) ($r['available_reno'] ?? 0)],
                ],
            ];"""
s = s.replace(old, new)

# ---------------------------------------------------------------- prices
old = """            $map = (float) ($r['map'] ?? 0);
            $out[] = [
                'sku'         => $id,
                'cost_cents'  => (int) round(((float) ($r['your_price'] ?? 0)) * 100),
                'msrp_cents'  => (int) round(((float) ($r['msrp'] ?? 0)) * 100),
                // 0.0 means NO MAP, not a zero-dollar floor.
                'map_cents'   => $map == 0.0 ? null : (int) round($map * 100),
                'on_sale'     => (bool) ((int) ($r['is_on_sale'] ?? 0)),
                'on_closeout' => (bool) ((int) ($r['is_on_closeout'] ?? 0)),
            ];"""
assert s.count(old) == 1, ('prices', s.count(old))
new = """            $map = (float) ($r['map'] ?? 0);

            // MARKER-BTI-SYNC-SHAPES — VariantNo plus a Prices[] array, which
            // is what fetchCosts() reads. HLC sends tiers there, so BTI's
            // single dealer price is emitted as one tier rather than as a
            // differently-named field the service would ignore.
            $out[] = [
                'VariantNo' => $id,
                'Prices'    => [[
                    'PriceTypeId' => 1,
                    'PriceType'   => 'Base',
                    'Price'       => (float) ($r['your_price'] ?? 0),
                ]],
                'MSRP' => (float) ($r['msrp'] ?? 0),
                // 0.0 means NO MAP, not a zero-dollar floor.
                'MAP'  => $map == 0.0 ? null : $map,
                'OnSale'     => (bool) ((int) ($r['is_on_sale'] ?? 0)),
                'OnCloseout' => (bool) ((int) ($r['is_on_closeout'] ?? 0)),
            ];"""
s = s.replace(old, new)

# ---------------------------------------------------------------- images
old = """            $out[] = [
                'sku'    => $id,
                'images' => array_values(array_map("""
assert s.count(old) == 1, ('images', s.count(old))
new = """            $out[] = [
                // MARKER-BTI-SYNC-SHAPES — consistent with the others.
                'VariantNo' => $id,
                'sku'       => $id,
                'images' => array_values(array_map("""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('bti shapes ok')
BSS_0_EOF

echo
echo "bti-sync-shapes applied."
