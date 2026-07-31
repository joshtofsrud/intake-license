#!/bin/bash
# bti-products-shape — BTI rows grouped into the shape the sync consumes.
#
#   First BTI sync reported pages 1, seen 0, written 0 — while also warning
#   the page came back full. So rows arrived and none were counted.
#
#   Cause: DistributorCatalogSyncService is HLC-shaped, not generic. It reads
#   $product['Brand'] to group, then iterates $product['Variants']. BTI's feed
#   is flat item rows with neither key, so the inner loop never ran.
#
#   Fixed in the adapter, not the sync. Touching the sync would put HLC's
#   working nightly import at risk to onboard a second distributor, and BTI
#   already has the grouping the shape wants: group_id is the product, id is
#   the variant.
#
#   Pagination stays row-based on purpose. A group split across a page
#   boundary yields two product entries sharing a group_id, which is harmless
#   — upsertVariant keys on distributor_variant_no, so each row lands once
#   either way. Paging by group would mean buffering an unknown number of
#   rows to find a boundary, for no gain.
#
#   Field maps need no change: resolve() merges variant and product into one
#   context, and every BTI row carries its group_* columns already, so the
#   flat paths in BtiFieldMapSeeder resolve from the variant side.
# NO MIGRATION. Server: optimize:clear.
set -e
if grep -q "MARKER-BTI-PRODUCT-SHAPE" app/Services/Distributors/BtiClient.php; then
  echo "bti-products-shape already applied — aborting."; exit 1
fi

python3 - <<'BPS_0_EOF'
import io
p = 'app/Services/Distributors/BtiClient.php'
s = io.open(p, encoding='utf-8').read()

old = """            if ($i++ < $start) {
                continue;
            }
            $out[] = $r;
            if (count($out) >= $size) {
                break;
            }
        }

        return $out;
    }"""
assert s.count(old) == 1, s.count(old)

new = """            if ($i++ < $start) {
                continue;
            }
            $out[] = $r;
            if (count($out) >= $size) {
                break;
            }
        }

        return $this->groupIntoProducts($out);
    }

    /**
     * MARKER-BTI-PRODUCT-SHAPE
     *
     * The sync layer is written around HLC's nested shape: it groups on
     * $product['Brand'] and iterates $product['Variants']. BTI ships flat
     * item rows, so without this the sync sees rows and counts none of them.
     *
     * BTI already has the grouping: group_id is the product, id is the
     * variant. Rows keep every column, so the field map's flat paths still
     * resolve — resolve() merges variant and product into one context.
     *
     * A group straddling a page boundary produces two entries with the same
     * group_id. That is fine: upsertVariant keys on distributor_variant_no,
     * so each row is written exactly once regardless.
     *
     * @param  array<int,array<string,string>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function groupIntoProducts(array $rows): array
    {
        $byGroup = [];

        foreach ($rows as $r) {
            // Fall back to the item id so a row with no group still syncs
            // as a product of one, rather than being silently dropped.
            $gid = ($r['group_id'] ?? '') !== '' ? $r['group_id'] : ($r['id'] ?? '');
            if ($gid === '') {
                continue;
            }

            if (! isset($byGroup[$gid])) {
                $byGroup[$gid] = [
                    // What the sync groups on. Unknown keeps a nameless row
                    // out of a bucket it doesn't belong in.
                    'Brand'             => ($r['manufacturer_name'] ?? '') !== ''
                        ? $r['manufacturer_name']
                        : 'Unknown',
                    'group_id'          => $gid,
                    'group_description' => $r['group_description'] ?? '',
                    'group_text'        => $r['group_text'] ?? '',
                    'manufacturer_id'   => $r['manufacturer_id'] ?? '',
                    'manufacturer_name' => $r['manufacturer_name'] ?? '',
                    'category_id'       => $r['category_id'] ?? '',
                    'category_name'     => $r['category_name'] ?? '',
                    'sub_category_id'   => $r['sub_category_id'] ?? '',
                    'sub_category_name' => $r['sub_category_name'] ?? '',
                    'Variants'          => [],
                ];
            }

            $byGroup[$gid]['Variants'][] = $r;
        }

        return array_values($byGroup);
    }"""

io.open(p, 'w', encoding='utf-8').write(s.replace(old, new))
print('products shape ok')
BPS_0_EOF

echo
echo "bti-products-shape applied."
