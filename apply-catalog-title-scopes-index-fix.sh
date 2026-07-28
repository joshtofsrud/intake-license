#!/bin/bash
# catalog-title-scopes-index-fix — repairs the failed scopes migration.
#   Two problems, one deploy:
#   1. platform_distributor_catalogs.category_path is TEXT in the live schema,
#      and MySQL cannot index a TEXT column without a key length. Laravel's
#      Blueprint::index() has no way to express a prefix, so the add is now
#      raw SQL with category_path(191) — long enough for a full path, short
#      enough to stay inside the index byte limit alongside distributor_code.
#   2. MySQL DDL is not transactional, so catalog_title_scopes was created
#      before the index statement failed, and the migration never recorded.
#      A straight re-run would then die on "table already exists". Both steps
#      are now guarded, so the migration is safe to run again as-is.
#   Rewrites the migration file in place. It has not successfully run
#   anywhere yet, so there is no applied history to preserve.
# MIGRATION REQUIRED (re-run). After deploy: php artisan catalog:scan-titles HLC
set -e
if grep -q "MARKER-SCOPES-INDEX-FIX" database/migrations/2026_07_28_000001_create_catalog_title_scopes.php; then
  echo "catalog-title-scopes-index-fix already applied — aborting."; exit 1
fi

cat > 'database/migrations/2026_07_28_000001_create_catalog_title_scopes.php' <<'CTSF_0_EOF'
<?php

// MARKER-TITLE-SCOPES / MARKER-SCOPES-INDEX-FIX
//
// Materialized coverage + health index for the Catalog Titles page. One row
// per (distributor, distributor category path).
//
// Both steps are guarded because the first version of this migration failed
// partway: MySQL created the table, then rejected the index, and the
// migration row was never written. Guarding makes a re-run a no-op for
// whatever already landed.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_title_scopes')) {
            Schema::create('catalog_title_scopes', function (Blueprint $t) {
                $t->id();

                $t->string('distributor_code', 32);

                // The DISTRIBUTOR's category path from the feed — not a tenant
                // category. '' is possible for feed rows with no path at all.
                $t->string('category_key', 191)->default('');

                $t->unsignedInteger('item_count')->default(0);

                // Which rule actually applies, after prefix resolution. Null
                // means nothing more specific than the global default matched.
                $t->string('resolved_rule_scope', 191)->nullable();
                $t->boolean('has_own_rule')->default(false);

                // [{code,label,severity,detail}, ...] — empty array = healthy.
                $t->json('flags')->nullable();

                // A few catalog row ids to render previews from, so the editor
                // never has to go hunting for representative items.
                $t->json('sample_ids')->nullable();

                $t->string('sample_title', 255)->nullable();

                $t->boolean('reviewed')->default(false);
                $t->timestamp('reviewed_at')->nullable();

                $t->timestamp('scanned_at')->nullable();
                $t->timestamps();

                $t->unique(['distributor_code', 'category_key'], 'cts_scope_unique');
                $t->index(['distributor_code', 'reviewed'], 'cts_dist_reviewed_idx');
            });
        }

        // Per-scope sampling walks this. Without it every scope is a scan.
        //
        // Raw SQL on purpose: category_path is TEXT, which MySQL will not
        // index without a key length, and Blueprint::index() cannot emit one.
        if (! $this->indexExists('platform_distributor_catalogs', 'pdc_dist_catpath_idx')) {
            DB::statement(
                'ALTER TABLE `platform_distributor_catalogs`
                 ADD INDEX `pdc_dist_catpath_idx` (`distributor_code`, `category_path`(191))'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('platform_distributor_catalogs', 'pdc_dist_catpath_idx')) {
            DB::statement('ALTER TABLE `platform_distributor_catalogs` DROP INDEX `pdc_dist_catpath_idx`');
        }
        Schema::dropIfExists('catalog_title_scopes');
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
CTSF_0_EOF

php -l database/migrations/2026_07_28_000001_create_catalog_title_scopes.php

echo
echo "catalog-title-scopes-index-fix applied."
