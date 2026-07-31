#!/bin/bash
# import-reads-matches — the 4,440 links finally reach a tenant.
#
#   DistributorCatalogImportService decides "do I already carry this product"
#   from product_key, then UPC. Both are broken for cross-distributor work:
#   product_key is null on all but 71 of 14,469 HLC rows, and UPC can't see
#   the 4,207 HLC rows that carry only an EAN. So a shop subscribed to HLC
#   and BTI gets two items for the same product, which is exactly what the
#   matcher was built to prevent — and until now it prevented nothing,
#   because nothing read catalog_matches.
#
#   Now the lookup order is:
#     1. this exact catalog row is already sourced      -> skip
#     2. a row LINKED to it by catalog_matches is carried -> merge a source
#     3. product_key / UPC                              -> merge a source
#     4. otherwise                                      -> new item
#
#   catalog_matches goes FIRST because it is the only one of the three built
#   from evidence rather than a single field, and the only one that survives
#   a distributor having no UPC.
#
#   Only auto and confirmed links count. A held pair is a question nobody has
#   answered yet, and a rejected pair is a person saying no — merging on
#   either would be the system overriding a human, which is the failure mode
#   worth being strict about.
#
#   Links are read in both directions: the pair table stores each pair once,
#   ordered by id, so the row being imported can sit on either side.
# NO MIGRATION. Server: optimize:clear
set -e
if grep -q "MARKER-IMPORT-MATCHES" app/Services/Distributors/DistributorCatalogImportService.php; then
  echo "import-reads-matches already applied — aborting."; exit 1
fi
if [ ! -f app/Models/CatalogMatch.php ]; then
  echo "catalog-matches must be applied first — aborting."; exit 1
fi

python3 - <<'IRM_0_EOF'
import io
p = 'app/Services/Distributors/DistributorCatalogImportService.php'
s = io.open(p, encoding='utf-8').read()

# ---------------------------------------------------------------- lookup
old = """            // 2) same physical product already carried -> merge a source
            $key = $cat->product_key;
            $matchId = ($key && isset($byKey[$key]))
                ? $byKey[$key]
                : (($cat->upc && isset($byUpc[$cat->upc])) ? $byUpc[$cat->upc] : null);"""
assert s.count(old) == 1, s.count(old)

new = """            // MARKER-IMPORT-MATCHES
            // 2) a catalog row LINKED to this one is already carried.
            //
            // Checked before product_key and UPC because it is the only test
            // built from evidence rather than one field, and the only one
            // that works when a distributor ships no UPC — 4,207 HLC rows
            // carry an EAN and nothing else, and UPC matching is blind to
            // every one of them.
            $matchId = null;
            foreach (($matchedRows[$cat->id] ?? []) as $otherId) {
                if (isset($linkedCatalog[$otherId])) {
                    $matchId = $linkedCatalog[$otherId];
                    break;
                }
            }

            // 3) same physical product by key or barcode
            $key = $cat->product_key;
            if ($matchId === null) {
                $matchId = ($key && isset($byKey[$key]))
                    ? $byKey[$key]
                    : (($cat->upc && isset($byUpc[$cat->upc])) ? $byUpc[$cat->upc] : null);
            }"""
s = s.replace(old, new)

# ---------------------------------------------------------------- build it
old = """        $vendor = $dryRun ? null : $this->vendorFor($tenantId, $code);
        [$byKey, $byUpc, $linkedCatalog] = $this->existingIndexes($tenantId);"""
assert s.count(old) == 1, s.count(old)
new = """        $vendor = $dryRun ? null : $this->vendorFor($tenantId, $code);
        [$byKey, $byUpc, $linkedCatalog] = $this->existingIndexes($tenantId);

        // MARKER-IMPORT-MATCHES
        $matchedRows = $this->matchedRows($candidates->pluck('id')->all());"""
s = s.replace(old, new)

# ---------------------------------------------------------------- helper
old = """    private function vendorFor(string $tenantId, string $code): TenantVendor"""
assert s.count(old) == 1, s.count(old)
new = """    /**
     * MARKER-IMPORT-MATCHES \u2014 catalog rows linked to each of these rows.
     *
     * Only `auto` and `confirmed` links are honoured. A `held` pair is a
     * question nobody has answered and a `rejected` pair is someone having
     * said no \u2014 merging on either would let the importer overrule a person.
     *
     * The pair table stores each pair once with the lower id first, so both
     * directions are read and folded into one map.
     *
     * @param  array<int,string> $catalogIds
     * @return array<string,array<int,string>> catalog id => linked catalog ids
     */
    private function matchedRows(array $catalogIds): array
    {
        if (! $catalogIds) {
            return [];
        }

        $out = [];

        \\App\\Models\\CatalogMatch::query()
            ->whereIn('status', ['auto', 'confirmed'])
            ->where(function ($q) use ($catalogIds) {
                $q->whereIn('row_a_id', $catalogIds)
                  ->orWhereIn('row_b_id', $catalogIds);
            })
            ->select(['row_a_id', 'row_b_id'])
            ->chunk(2000, function ($rows) use (&$out) {
                foreach ($rows as $m) {
                    $out[$m->row_a_id][] = $m->row_b_id;
                    $out[$m->row_b_id][] = $m->row_a_id;
                }
            });

        return $out;
    }

    private function vendorFor(string $tenantId, string $code): TenantVendor"""
s = s.replace(old, new)

io.open(p, 'w', encoding='utf-8').write(s)
print('import matches ok')
IRM_0_EOF

php -l app/Services/Distributors/DistributorCatalogImportService.php

echo
echo "import-reads-matches applied."
