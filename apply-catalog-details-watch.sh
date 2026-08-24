#!/usr/bin/env bash
# MARKER-DETAILS-WATCH — catalog color/size/description flow to tenant items:
#   1. migration + model: catalog_details_seen baseline (json) on items
#   2. tenant sync: details_changed attention flag (mirrors title watch)
#   3. controller + attention view: adopt/keep actions, badge, filter, rows
#      (also dedupes the pre-existing doubled vanished-filter options)
#   4. edit page: color/size/description shown in the Catalog data panel
#   5. artisan catalog:backfill-item-details — one-time blank-fill + baseline seed
set -e

if grep -q "MARKER-DETAILS-WATCH" app/Services/Distributors/TenantDistributorSyncService.php; then
  echo "ok: already applied"
  exit 0
fi

# ---------- 1. migration ----------
cat > database/migrations/2026_08_24_100000_add_catalog_details_seen.php <<'EOF'
<?php
// MARKER-DETAILS-WATCH — baseline of the catalog's color/size/description as
// last seen by this item, so the details watch flags changes, not backlog.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->json('catalog_details_seen')->nullable()->after('catalog_title_seen');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_items', function (Blueprint $table) {
            $table->dropColumn('catalog_details_seen');
        });
    }
};
EOF
echo "ok: migration written"

# ---------- 2. model fillable + cast ----------
python3 - <<'EOF'
import io
p = "app/Models/Tenant/TenantInventoryItem.php"
s = io.open(p, encoding="utf-8").read()
old = "        'catalog_title_seen',\n"
new = "        'catalog_title_seen',\n        'catalog_details_seen', // MARKER-DETAILS-WATCH\n"
assert s.count(old) == 1, "fillable anchor"
s = s.replace(old, new)
old = "        'catalog_synced_at' => 'datetime',\n"
new = "        'catalog_synced_at' => 'datetime',\n        'catalog_details_seen' => 'array', // MARKER-DETAILS-WATCH\n"
assert s.count(old) == 1, "casts anchor"
s = s.replace(old, new)
io.open(p, "w", encoding="utf-8").write(s)
print("ok: item model")
EOF

# ---------- 3. flag model reason ----------
python3 - <<'EOF'
import io
p = "app/Models/Tenant/TenantPricingAttentionFlag.php"
s = io.open(p, encoding="utf-8").read()
old = "    public const REASON_TITLE_CHANGED = 'title_changed';\n"
new = "    public const REASON_TITLE_CHANGED = 'title_changed';\n    public const REASON_DETAILS_CHANGED = 'details_changed'; // MARKER-DETAILS-WATCH\n"
assert s.count(old) == 1, "reason anchor"
io.open(p, "w", encoding="utf-8").write(s.replace(old, new))
print("ok: flag model")
EOF

# ---------- 4. tenant sync details watch ----------
python3 - <<'EOF'
import io
p = "app/Services/Distributors/TenantDistributorSyncService.php"
s = io.open(p, encoding="utf-8").read()
old = """        } else {
            $this->resolveFlag($tenantId, $item, TenantPricingAttentionFlag::REASON_TITLE_CHANGED, $dryRun, $res);
        }
    }
"""
new = """        } else {
            $this->resolveFlag($tenantId, $item, TenantPricingAttentionFlag::REASON_TITLE_CHANGED, $dryRun, $res);
        }

        // MARKER-DETAILS-WATCH — descriptive drift (color / size / description).
        // Same contract as the title watch: never auto-applied; the tenant
        // adopts or keeps from the attention surface. A null baseline is
        // seeded silently so an existing library never floods attention.
        $detailsNow = [
            'color'       => $cat->color,
            'size'        => $cat->size,
            'description' => $cat->description,
        ];
        $seenDetails = $item->catalog_details_seen;
        if ($seenDetails === null) {
            if (! $dryRun) {
                $item->catalog_details_seen = $detailsNow;
                $item->save();
            }
        } else {
            $changed = [];
            foreach ($detailsNow as $k => $v) {
                if (($v ?? '') !== ($seenDetails[$k] ?? '')) {
                    $changed[$k] = ['old' => $seenDetails[$k] ?? null, 'new' => $v];
                }
            }
            if ($changed !== []) {
                $this->openFlag($tenantId, $item, $cat,
                    TenantPricingAttentionFlag::REASON_DETAILS_CHANGED, null, $dryRun, $res, [
                        'changed' => $changed,
                        'at'      => now()->toIso8601String(),
                    ]);
            } else {
                $this->resolveFlag($tenantId, $item, TenantPricingAttentionFlag::REASON_DETAILS_CHANGED, $dryRun, $res);
            }
        }
    }
"""
assert s.count(old) == 1, "sync anchor"
io.open(p, "w", encoding="utf-8").write(s.replace(old, new))
print("ok: tenant sync")
EOF

# ---------- 5. controller actions ----------
python3 - <<'EOF'
import io
p = "app/Http/Controllers/Tenant/DistributorController.php"
s = io.open(p, encoding="utf-8").read()

old = "'in:raise_map,match_msrp,acknowledge,adopt_title,keep_title'"
new = "'in:raise_map,match_msrp,acknowledge,adopt_title,keep_title,adopt_details,keep_details'"
assert s.count(old) == 1, "validation anchor"
s = s.replace(old, new)

old = "        $titleReason = \\App\\Models\\Tenant\\TenantPricingAttentionFlag::REASON_TITLE_CHANGED;\n"
new = old + "        $detailsReason = \\App\\Models\\Tenant\\TenantPricingAttentionFlag::REASON_DETAILS_CHANGED; // MARKER-DETAILS-WATCH\n"
assert s.count(old) == 1, "reason var anchor"
s = s.replace(old, new)

old = "            $isTitle = $flag->reason === $titleReason;\n"
new = old + "            $isDetails = $flag->reason === $detailsReason; // MARKER-DETAILS-WATCH\n"
assert s.count(old) == 1, "isTitle anchor"
s = s.replace(old, new)

old = """            if (in_array($action, ['adopt_title', 'keep_title'], true) && ! $isTitle) {
                $skipped++;
                continue;
            }
"""
new = old + """            if (in_array($action, ['adopt_details', 'keep_details'], true) && ! $isDetails) {
                $skipped++;
                continue;
            }
"""
assert s.count(old) == 1, "title guard anchor"
s = s.replace(old, new)

old = "if (in_array($action, ['raise_map', 'match_msrp'], true) && $isTitle) {"
new = "if (in_array($action, ['raise_map', 'match_msrp'], true) && ($isTitle || $isDetails)) {"
assert s.count(old) == 1, "price guard anchor"
s = s.replace(old, new)

old = """                $cat = $item?->distributorCatalog;
                if ($item && $cat) {
                    $item->catalog_title_seen = $cat->display_name;
                    $item->save();
                }
            }
"""
new = """                $cat = $item?->distributorCatalog;
                if ($item && $cat) {
                    $item->catalog_title_seen = $cat->display_name;
                    $item->save();
                }
            } elseif ($action === 'adopt_details') {
                // MARKER-DETAILS-WATCH — copy only non-blank catalog values;
                // the feed dropping a field never blanks the shop's own.
                $cat = $item?->distributorCatalog;
                if (! $item || ! $cat) {
                    $skipped++;
                    continue;
                }
                foreach (['color', 'size', 'description'] as $fld) {
                    if (filled($cat->{$fld})) {
                        $item->{$fld} = $cat->{$fld};
                    }
                }
                $item->catalog_details_seen = [
                    'color'       => $cat->color,
                    'size'        => $cat->size,
                    'description' => $cat->description,
                ];
                $item->save();
            } elseif ($action === 'keep_details') {
                // Keep the tenant's values; snapshot the catalog's so the
                // watch stops flagging this change.
                $cat = $item?->distributorCatalog;
                if ($item && $cat) {
                    $item->catalog_details_seen = [
                        'color'       => $cat->color,
                        'size'        => $cat->size,
                        'description' => $cat->description,
                    ];
                    $item->save();
                }
            }
"""
assert s.count(old) == 1, "keep_title branch anchor"
s = s.replace(old, new)

old = "            'keep_title'  => 'Kept your title',\n"
new = old + "            'adopt_details' => 'Adopted new details',\n            'keep_details'  => 'Kept your details',\n"
assert s.count(old) == 1, "verb map anchor"
s = s.replace(old, new)

io.open(p, "w", encoding="utf-8").write(s)
print("ok: controller")
EOF

# ---------- 6. attention view ----------
python3 - <<'EOF'
import io
p = "resources/views/tenant/distributors/attention.blade.php"
s = io.open(p, encoding="utf-8").read()

old = "      'title_changed' => ['at-b-title','Renamed by distributor'],\n"
new = old + "      'details_changed' => ['at-b-title','Details updated'], // MARKER-DETAILS-WATCH\n"
assert s.count(old) == 1, "badge anchor"
s = s.replace(old, new)

# dedupe the doubled vanished options and add the details filter
trio = """      <option value="cost_vanished" @selected(($filters['reason'] ?? null)==='cost_vanished')>Cost removed</option>
      <option value="map_vanished" @selected(($filters['reason'] ?? null)==='map_vanished')>MAP removed</option>
      <option value="msrp_vanished" @selected(($filters['reason'] ?? null)==='msrp_vanished')>MSRP removed</option>
"""
doubled = trio + trio
assert s.count(doubled) == 1, "doubled filter anchor"
s = s.replace(doubled, trio)

old = """      <option value="title_changed" @selected(($filters['reason'] ?? null)==='title_changed')>Title changed</option>
"""
new = old + """      <option value="details_changed" @selected(($filters['reason'] ?? null)==='details_changed')>Details updated</option>
"""
assert s.count(old) == 1, "filter anchor"
s = s.replace(old, new)

old = """                  <div class="when">your item still uses the name on the left</div>
                @elseif($f->reason === 'below_map')
"""
new = """                  <div class="when">your item still uses the name on the left</div>
                @elseif($f->reason === 'details_changed')
                  @foreach(($d['changed'] ?? []) as $fld => $chg)
                    <div><b style="text-transform:capitalize">{{ $fld }}</b>:
                      <span class="old">{{ blank($chg['old'] ?? null) ? '—' : \\Illuminate\\Support\\Str::limit($chg['old'], 60) }}</span> →
                      {{ blank($chg['new'] ?? null) ? '—' : \\Illuminate\\Support\\Str::limit($chg['new'], 60) }}</div>
                  @endforeach
                  <div class="when">your item still has the values on the left</div>
                @elseif($f->reason === 'below_map')
"""
assert s.count(old) == 1, "row detail anchor"
s = s.replace(old, new)

old = """                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('keep_title')">Keep mine</button>
                  @elseif($f->reason === 'below_map')
"""
new = """                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('keep_title')">Keep mine</button>
                  @elseif($f->reason === 'details_changed')
                    <button class="at-btn primary" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('adopt_details')">Use new details</button>
                    <button class="at-btn" type="submit" name="row_flag" value="{{ $f->id }}" onclick="setAct('keep_details')">Keep mine</button>
                  @elseif($f->reason === 'below_map')
"""
assert s.count(old) == 1, "row actions anchor"
s = s.replace(old, new)

old = """        <button class="at-btn" type="submit" onclick="setAct('keep_title')">Keep mine</button>
"""
new = """        <button class="at-btn" type="submit" onclick="setAct('keep_title')">Keep mine</button>
        <button class="at-btn primary" type="submit" onclick="setAct('adopt_details')">Adopt new details</button>
        <button class="at-btn" type="submit" onclick="setAct('keep_details')">Keep my details</button>
"""
assert s.count(old) == 1, "bulk bar anchor"
s = s.replace(old, new)

io.open(p, "w", encoding="utf-8").write(s)
print("ok: attention view")
EOF

# ---------- 7. edit page catalog panel ----------
python3 - <<'EOF'
import io
p = "resources/views/tenant/inventory/edit.blade.php"
s = io.open(p, encoding="utf-8").read()
old = """          <tr><td>UPC</td><td><code>{{ $item->catalog_upc ?? '—' }}</code></td></tr>
"""
new = """          <tr><td>UPC</td><td><code>{{ $item->catalog_upc ?? '—' }}</code></td></tr>
          {{-- MARKER-DETAILS-WATCH --}}
          <tr><td>Color</td><td>{{ $item->distributorCatalog?->color ?? '—' }}</td></tr>
          <tr><td>Size</td><td>{{ $item->distributorCatalog?->size ?? '—' }}</td></tr>
          <tr><td>Description</td><td>{{ blank($item->distributorCatalog?->description) ? '—' : \\Illuminate\\Support\\Str::limit($item->distributorCatalog->description, 160) }}</td></tr>
"""
assert s.count(old) == 1, "edit panel anchor"
io.open(p, "w", encoding="utf-8").write(s.replace(old, new))
print("ok: edit view")
EOF

# ---------- 8. backfill command ----------
cat > app/Console/Commands/BackfillItemCatalogDetails.php <<'EOF'
<?php
// MARKER-DETAILS-WATCH — one-time backfill: copy catalog color/size/description
// into linked items where the item's field is blank, and seed the
// catalog_details_seen baseline so the details watch flags changes, not backlog.

namespace App\Console\Commands;

use App\Models\Tenant\TenantInventoryItem;
use Illuminate\Console\Command;

class BackfillItemCatalogDetails extends Command
{
    protected $signature = 'catalog:backfill-item-details
        {--tenant= : Limit to one tenant id}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Fill blank item color/size/description from the linked catalog row and seed the details baseline';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $q = TenantInventoryItem::query()
            ->whereNotNull('distributor_catalog_id')
            ->with('distributorCatalog');
        if ($this->option('tenant')) {
            $q->where('tenant_id', $this->option('tenant'));
        }

        $scanned = 0;
        $filled = 0;
        $seeded = 0;

        $q->chunkById(500, function ($items) use (&$scanned, &$filled, &$seeded, $dry) {
            foreach ($items as $item) {
                $scanned++;
                $cat = $item->distributorCatalog;
                if (! $cat) {
                    continue;
                }
                $dirty = false;
                foreach (['color', 'size', 'description'] as $fld) {
                    if (blank($item->{$fld}) && filled($cat->{$fld})) {
                        $item->{$fld} = $cat->{$fld};
                        $dirty = true;
                        $filled++;
                    }
                }
                if ($item->catalog_details_seen === null) {
                    $item->catalog_details_seen = [
                        'color'       => $cat->color,
                        'size'        => $cat->size,
                        'description' => $cat->description,
                    ];
                    $dirty = true;
                    $seeded++;
                }
                if ($dirty && ! $dry) {
                    $item->save();
                }
            }
        });

        $this->info(($dry ? '[dry-run] ' : '') . "{$scanned} linked items scanned, {$filled} blank fields filled, {$seeded} baselines seeded.");

        return self::SUCCESS;
    }
}
EOF
echo "ok: backfill command written"

echo "ok: done — 8 steps"
