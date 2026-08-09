#!/usr/bin/env bash
# apply-qbp-attribute-array-values.sh
#
# Fixes "Array to string conversion" in QbpClient::attributes().
#
# QBP returns an OBJECT when a field has one value and a LIST when it has
# several — the same shape the MARKER-QBP-FIXES comment already documents for
# images and bulletPoints. attributes() guards $fv itself but then casts
# $fv['value'] straight to string, so any feature carrying multiple values
# throws and the whole row is discarded.
#
# The class already has scalar(), which digs a string out of #text / value /
# the first scalar member. This routes all four casts through it, and iterates
# a multi-value list instead of flattening it to one.
#
# Guarded by MARKER-QBP-ATTRVAL. Idempotent.
set -euo pipefail
cd "$(dirname "$0")"

python3 - <<'PYEOF'
import sys
P = "app/Services/Distributors/QbpClient.php"
src = open(P, encoding="utf-8").read()
if "MARKER-QBP-ATTRVAL" in src:
    print("  skip (already patched)"); raise SystemExit(0)

edits = [
(
"""                $values = [];
                foreach ($this->asList($feature['featureValues']['featureValue'] ?? null) as $fv) {
                    $v = is_array($fv) ? ($fv['value'] ?? '') : $fv;
                    $v = trim((string) $v);
                    if ($v !== '') {
                        $values[] = $v;
                    }
                }""",
"""                $values = [];
                foreach ($this->asList($feature['featureValues']['featureValue'] ?? null) as $fv) {
                    // MARKER-QBP-ATTRVAL — ['value'] is itself an OBJECT for one
                    // value and a LIST for several. Casting it straight to string
                    // threw "Array to string conversion" and lost the whole row.
                    $raw = is_array($fv) ? ($fv['value'] ?? $fv) : $fv;
                    foreach ($this->asList($raw) as $one) {
                        $one = $this->scalar($one);
                        if ($one !== '') {
                            $values[] = $one;
                        }
                    }
                }"""
),
(
"""                $name = trim((string) ($feature['name'] ?? $cls['name'] ?? ''));""",
"""                // MARKER-QBP-ATTRVAL — same nesting risk on every field here.
                $name = $this->scalar($feature['name'] ?? $cls['name'] ?? '');"""
),
(
"""                    'Code'  => trim((string) ($feature['code'] ?? $cls['code'] ?? '')),
                    'Unit'  => trim((string) ($feature['featureUnit'] ?? '')),""",
"""                    'Code'  => $this->scalar($feature['code'] ?? $cls['code'] ?? ''),
                    'Unit'  => $this->scalar($feature['featureUnit'] ?? ''),"""
),
]

for old, new in edits:
    c = src.count(old)
    if c != 1:
        print(f"  !! anchor matched {c} times (expected 1): {old.strip().splitlines()[0][:60]}")
        sys.exit(1)
    src = src.replace(old, new)

open(P, "w", encoding="utf-8").write(src)
print(f"  patched {len(edits)} sites  {P}")
PYEOF

echo
echo "  Then: php artisan optimize && php artisan queue:restart"
