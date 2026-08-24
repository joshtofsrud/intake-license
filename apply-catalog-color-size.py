#!/usr/bin/env python3
"""Colour and size: stop discarding what's already been computed.

tenant_inventory_items.color and .size were added in May and have never
been populated for an imported item, so both columns render "—" on every
row. The cause is NOT missing data and NOT a missing field map:

  * All three distributors ship colour and size inside their `attributes`
    bag — BTI zips two pipe strings (its seeder note literally reads
    "Model|Color|Size + Snapback Hat|Gray|One Size"), HLC and QBP pass
    JSON through.
  * CatalogTitleComposer ALREADY extracts both, via a priority list that
    is configurable per distributor and category in master admin, with
    ['Color','Primary Color'] as the fallback.
  * compose() returns four values: title, subtitle, size, color. The sync
    service keeps three. $composed['size'] and $composed['color'] are
    computed on every row and dropped one line short of the finish.
  * platform_distributor_catalogs has size_id/color_id — HLC's opaque
    CODES, used as title tokens — but no columns for the NAMES.

So: add the two columns, keep what the composer already returns, and
carry them across on import.

HLC's size_id/color_id are deliberately left alone. They're a different
thing (codes, not names) and the title templates reference them.

Existing catalog rows stay empty until their next sync, which is fine —
the nightly run fills them without a 24k-row refetch.
Run from repo root: python3 apply-catalog-color-size.py
"""
import os, sys

ROOT = os.getcwd()
def read(p):
    with open(os.path.join(ROOT, p)) as f: return f.read()
def write(p, s):
    with open(os.path.join(ROOT, p), 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

# ============================================================
# 1) Columns on the shared catalog
# ============================================================
mig = 'database/migrations/2026_08_24_090000_add_color_size_to_distributor_catalogs.php'
if os.path.exists(os.path.join(ROOT, mig)):
    print("SKIP (exists): migration")
else:
    write(mig, """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

/**
 * MARKER-CATALOG-COLORSIZE — the NAMES, not the codes.
 *
 * size_id / color_id already exist and hold HLC's opaque codes, which the
 * title templates use as tokens. These two hold the human-readable values
 * CatalogTitleComposer resolves out of the attributes bag, which had
 * nowhere to be stored and were therefore thrown away every sync.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->string('color', 60)->nullable()->after('color_id');
            $table->string('size', 60)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('platform_distributor_catalogs', function (Blueprint $table) {
            $table->dropColumn(['color', 'size']);
        });
    }
};
""")
    print("OK: migration")

sub('app/Models/PlatformDistributorCatalog.php',
    """        'size_id',
        'color_id',""",
    """        'size_id',
        'color_id',
        'color', // MARKER-CATALOG-COLORSIZE — resolved name, not HLC's code
        'size',""",
    "model: fillable")

# ============================================================
# 2) Keep what the composer already returns
# ============================================================
sub('app/Services/Distributors/DistributorCatalogSyncService.php',
    """        $canonical['display_name']     = $composed['title'] !== '' ? $composed['title'] : ($canonical['name'] ?? null);
        $canonical['display_subtitle'] = $composed['subtitle'] !== '' ? $composed['subtitle'] : null;
        $canonical['search_text']      = ($composed['search'] ?? '') !== '' ? $composed['search'] : null;""",
    """        $canonical['display_name']     = $composed['title'] !== '' ? $composed['title'] : ($canonical['name'] ?? null);
        $canonical['display_subtitle'] = $composed['subtitle'] !== '' ? $composed['subtitle'] : null;
        $canonical['search_text']      = ($composed['search'] ?? '') !== '' ? $composed['search'] : null;

        // MARKER-CATALOG-COLORSIZE — compose() has always returned these two
        // alongside the title; nothing kept them, so every row resolved a
        // colour and a size and then threw both away. A map row can still
        // override them by resolving canonical 'color'/'size' directly.
        $canonical['color'] = ($canonical['color'] ?? null) ?: (($composed['color'] ?? '') !== '' ? $composed['color'] : null);
        $canonical['size']  = ($canonical['size']  ?? null) ?: (($composed['size']  ?? '') !== '' ? $composed['size']  : null);""",
    "sync: persist colour and size")

# ============================================================
# 3) Carry them to the tenant item on import
# ============================================================
sub('app/Services/Distributors/DistributorCatalogImportService.php',
    """            'catalog_upc'            => $cat->upc,""",
    """            'catalog_upc'            => $cat->upc,
            // MARKER-CATALOG-COLORSIZE — the columns added in May finally get
            // a value. On CREATE only: a shop's own edit outranks the feed,
            // same rule the description above follows.
            'color'                  => $cat->color ?: null,
            'size'                   => $cat->size ?: null,""",
    "import: carry to the item")

print("\\nDone. Post-deploy: php artisan migrate --force")
print("Existing rows fill on their next distributor sync.")
