#!/bin/bash
# apply-qbp-scalar-coercion.sh
#
# Fixes the "Array to string conversion" errors on the QBP distributor page
# that have been gutting the QBP catalog sync.
#
# Cause: QBP responses are XML-derived, so any element that carries an XML
# attribute (or repeats) arrives as an ARRAY, not a string. The parser already
# handles this for collections (images, bullets, barcodes, features via
# asList + is_array guards), but a few SCALAR leaves still get a bare
# (string) cast: sku, modelCode, brand.description/id, and category name/id.
# When QBP sends one of those as an array, (string) throws — and because that
# throw escapes products() OUTSIDE the per-brand fetch try, syncIdentityByBrand
# catches it at the 10-brand CHUNK level, so one bad row makes all ten write
# nothing. That's why nearly every chunk errored and the catalog stopped
# updating while still paying the full fetch cost.
#
# Fix (two parts):
#   1. scalar() helper — safely stringify a leaf (attribute-wrapped -> its
#      #text/value node, else first scalar, else ''). Applied to the exposed
#      casts so those rows PARSE instead of throwing (no data lost).
#   2. per-row try/catch in products() — belt-and-suspenders so any remaining
#      rare array leaf skips that one row instead of killing the whole chunk.
#
# Same products(['brands'=>...]) path the new per-brand button calls, so this
# unblocks that too.
#
# Idempotent (gated by MARKER-QBP-SCALAR). One file.
# Run from the repo root on the Mac:  bash apply-qbp-scalar-coercion.sh

set -e

FILE="app/Services/Distributors/QbpClient.php"
[ -f "$FILE" ] || { echo "ERROR: $FILE not found — run from the intake-license repo root." >&2; exit 1; }

if grep -q "MARKER-QBP-SCALAR" "$FILE"; then
  echo "OK   already patched — no change."
  exit 0
fi

python3 - "$FILE" <<'PYEOF'
import sys
path = sys.argv[1]
s = open(path).read()

def repl(old, new, label):
    global s
    if s.count(old) != 1:
        print(f"ERROR [{label}]: expected exactly 1 match, found {s.count(old)}.", file=sys.stderr); sys.exit(1)
    s = s.replace(old, new)

# 1. scalar() helper, inserted before asList()
asList_anchor = "    private function asList(mixed $value): array"
helper = (
    "    // MARKER-QBP-SCALAR — QBP is XML-derived, so a leaf that carries an XML\n"
    "    // attribute (or repeats) arrives as an array, not a string; casting it\n"
    "    // with (string) throws \"Array to string conversion\", and because that\n"
    "    // happens outside the per-brand fetch try it kills a whole 10-brand\n"
    "    // chunk. Coerce safely: an attribute-wrapped leaf keeps its text node,\n"
    "    // else the first scalar; a non-scalar collapses to ''.\n"
    "    private function scalar(mixed $v): string\n"
    "    {\n"
    "        if (is_array($v)) {\n"
    "            if (isset($v['#text']) && is_scalar($v['#text'])) {\n"
    "                return trim((string) $v['#text']);\n"
    "            }\n"
    "            if (isset($v['value']) && is_scalar($v['value'])) {\n"
    "                return trim((string) $v['value']);\n"
    "            }\n"
    "            foreach ($v as $inner) {\n"
    "                if (is_scalar($inner)) {\n"
    "                    return trim((string) $inner);\n"
    "                }\n"
    "            }\n"
    "            return '';\n"
    "        }\n"
    "\n"
    "        return $v === null ? '' : trim((string) $v);\n"
    "    }\n"
    "\n"
    + asList_anchor
)
repl(asList_anchor, helper, "helper")

# 2. products() row loop: try/catch + scalar the four leaves
old_loop = (
    "            foreach ($this->asList($doc['products']['product'] ?? null) as $row) {\n"
    "                $sku = trim((string) ($row['sku'] ?? ''));\n"
    "                if ($sku === '') {\n"
    "                    continue;\n"
    "                }\n"
    "\n"
    "                // modelCode groups variants of one product. Missing means the\n"
    "                // SKU stands alone, so it becomes its own group.\n"
    "                $model = trim((string) ($row['modelCode'] ?? '')) ?: $sku;\n"
    "\n"
    "                $byModel[$model] ??= [\n"
    "                    'ModelCode' => $model,\n"
    "                    'Brand'     => trim((string) ($row['brand']['description'] ?? '')) ?: $brandId,\n"
    "                    'BrandId'   => trim((string) ($row['brand']['id'] ?? $brandId)),\n"
    "                    'Variants'  => [],\n"
    "                ];\n"
    "\n"
    "                $byModel[$model]['Variants'][] = $this->variant($row);\n"
    "            }"
)
new_loop = (
    "            foreach ($this->asList($doc['products']['product'] ?? null) as $row) {\n"
    "                try {\n"
    "                    $sku = $this->scalar($row['sku'] ?? '');\n"
    "                    if ($sku === '') {\n"
    "                        continue;\n"
    "                    }\n"
    "\n"
    "                    // modelCode groups variants of one product. Missing means\n"
    "                    // the SKU stands alone, so it becomes its own group.\n"
    "                    $model = $this->scalar($row['modelCode'] ?? '') ?: $sku;\n"
    "\n"
    "                    $byModel[$model] ??= [\n"
    "                        'ModelCode' => $model,\n"
    "                        'Brand'     => $this->scalar($row['brand']['description'] ?? '') ?: $brandId,\n"
    "                        'BrandId'   => $this->scalar($row['brand']['id'] ?? $brandId),\n"
    "                        'Variants'  => [],\n"
    "                    ];\n"
    "\n"
    "                    $byModel[$model]['Variants'][] = $this->variant($row);\n"
    "                } catch (\\Throwable $e) {\n"
    "                    // MARKER-QBP-SCALAR — a single malformed row must not lose\n"
    "                    // the rest of the brand's products.\n"
    "                    continue;\n"
    "                }\n"
    "            }"
)
repl(old_loop, new_loop, "products.loop")

# 3. variant() category leaves
old_cat = (
    "        $row['CategoryName'] = trim((string) ($row['productCategories']['productCategory']['name'] ?? ''));\n"
    "        $row['CategoryId']   = trim((string) ($row['productCategories']['productCategory']['id'] ?? ''));"
)
new_cat = (
    "        $row['CategoryName'] = $this->scalar($row['productCategories']['productCategory']['name'] ?? ''); // MARKER-QBP-SCALAR\n"
    "        $row['CategoryId']   = $this->scalar($row['productCategories']['productCategory']['id'] ?? '');"
)
repl(old_cat, new_cat, "variant.category")

open(path, "w").write(s)
print("OK   patched " + path)
PYEOF

echo ""
if php -l "$FILE" >/dev/null 2>&1; then echo "OK   php -l clean"; else echo "ERROR php -l"; php -l "$FILE"; exit 1; fi

echo ""
echo "SUCCESS. Deploy, then RESTART THE QUEUE WORKER so it picks up the new code:"
echo "    php artisan queue:restart"
echo "(a running worker holds the old QbpClient in memory — this bit the BTI brand-list fix.)"
echo "Then re-run a QBP sync; the per-brand errors should be gone and rows should write."
