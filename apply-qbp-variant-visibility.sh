#!/usr/bin/env bash
# apply-qbp-variant-visibility.sh
#
# The last silent catch. In QbpClient the model entry is created BEFORE the
# variant is built:
#
#     $byModel[$model] ??= [ ... 'Variants' => [] ];
#     $byModel[$model]['Variants'][] = $this->variant($row);
#
# so if variant() throws, the MARKER-QBP-SCALAR catch swallows it and the
# product survives with an EMPTY Variants array. The sync then sees products,
# marks the brand 'done', and writes nothing — which is exactly the observed
# state: 415 brands done, every one written = 0.
#
# Two changes:
#   1. log the swallowed row exception (class, message, file:line, sku)
#   2. build the variant FIRST, and only create the model entry once it
#      succeeds — so a brand whose rows all fail reports 'empty' instead of
#      'done' with nothing behind it
#
# Guarded by MARKER-QBP-ROWFAIL. Idempotent.
set -euo pipefail
cd "$(dirname "$0")"

python3 - <<'PYEOF'
import sys
P = "app/Services/Distributors/QbpClient.php"
src = open(P, encoding="utf-8").read()
if "MARKER-QBP-ROWFAIL" in src:
    print("  skip (already patched)"); raise SystemExit(0)

old = """                    $byModel[$model] ??= [
                        'ModelCode' => $model,
                        'Brand'     => $this->scalar($row['brand']['description'] ?? '') ?: $brandId,
                        'BrandId'   => $this->scalar($row['brand']['id'] ?? $brandId),
                        'Variants'  => [],
                    ];

                    $byModel[$model]['Variants'][] = $this->variant($row);
                } catch (\\Throwable $e) {
                    // MARKER-QBP-SCALAR — a single malformed row must not lose
                    // the rest of the brand's products.
                    continue;
                }"""

new = """                    // MARKER-QBP-ROWFAIL — build the variant BEFORE creating
                    // the model entry. Creating it first meant a throwing
                    // variant() left a product with an empty Variants array,
                    // so the brand looked complete and wrote nothing.
                    $builtVariant = $this->variant($row);

                    $byModel[$model] ??= [
                        'ModelCode' => $model,
                        'Brand'     => $this->scalar($row['brand']['description'] ?? '') ?: $brandId,
                        'BrandId'   => $this->scalar($row['brand']['id'] ?? $brandId),
                        'Variants'  => [],
                    ];

                    $byModel[$model]['Variants'][] = $builtVariant;
                } catch (\\Throwable $e) {
                    // MARKER-QBP-ROWFAIL — a single malformed row still must
                    // not lose the rest of the brand, but it no longer fails
                    // silently. This catch hid the whole QBP write failure.
                    $failures[] = $brandId . ' row ' . ($sku ?: '?') . ': ' . $e->getMessage();
                    Log::warning('QBP row build failed', [
                        'brand_id'  => $brandId,
                        'sku'       => $sku ?: null,
                        'exception' => get_class($e),
                        'error'     => $e->getMessage(),
                        'at'        => basename($e->getFile()) . ':' . $e->getLine(),
                    ]);
                    continue;
                }"""

if src.count(old) != 1:
    print(f"  !! anchor matched {src.count(old)} times (expected 1)"); sys.exit(1)

open(P, "w", encoding="utf-8").write(src.replace(old, new))
print("  patched 1 site  " + P)
PYEOF

echo
echo "  Then:  php artisan optimize"
echo "         php artisan distributors:sync-catalog QBP"
echo "         grep 'QBP row build failed' storage/logs/laravel-\$(date +%F).log | head -5"
