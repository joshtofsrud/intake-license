#!/bin/bash
# catalog-identifiers — the index cross-distributor matching runs on.
#
#   Why this instead of the existing `product_key` coalesce:
#   product_key is one string per row, built UPC -> EAN -> brand+MPN. Two rows
#   only match if BOTH picked the SAME rung. Measured on real data: 4,207 HLC
#   rows have an EAN and no UPC, so they key off EAN — while BTI ships a UPC
#   and a null EAN, so it keys off UPC. Different strings, never equal.
#   And the EANs are not zero-padded UPCs (HLC row: UPC 086699113269,
#   EAN 3528701198316 — a US company prefix against a French one), so no
#   normalisation collapses them either.
#
#   So identifiers get their own rows, one per (catalog row, type, value).
#   Two catalog rows match when ANY identifier pair agrees, which is what
#   "UPC first, MPN as confirmation or fallback" actually requires.
#
#   This patch builds ONLY the index and its backfill. It creates no links and
#   changes no behaviour — nothing reads the table yet. Matching is next, and
#   wants to be judged against real numbers from this rather than my guesses.
#
#   Normalisation, per type:
#     upc/ean  digits only; a 13-digit value starting 0 is stored as its
#              12-digit UPC-A too, so a genuine zero-padded pair still meets.
#              Check digits are left alone — feeds carry them consistently.
#     mpn      upper-cased, non-alphanumerics stripped, trimmed. BTI ships
#              " SOX-6M" with a leading space, and SOX-6M vs sox6m must agree.
#              Stored as brand|mpn because an MPN alone is not unique across
#              manufacturers — "100" is somebody's part number.
#
#   Deliberately NOT stored: anything shorter than 4 characters after
#   normalising, and the known junk values ("N/A", "NONE", "0"). A shared
#   junk MPN would merge unrelated products, which is the one failure mode
#   that is worse than no match at all.
# MIGRATION REQUIRED. After deploy: php artisan catalog:index-identifiers
set -e
if [ -f app/Models/CatalogIdentifier.php ]; then
  echo "catalog-identifiers already applied — aborting."; exit 1
fi

# ------------------------------------------------------------------ migration
cat > 'database/migrations/2026_07_31_000001_create_catalog_identifiers.php' <<'CID_0_EOF'
<?php

// MARKER-CATALOG-IDENTIFIERS — one row per (catalog row, identifier type,
// normalised value). Cross-distributor matching joins this table to itself.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_identifiers', function (Blueprint $t) {
            $t->id();

            $t->uuid('distributor_catalog_id');
            $t->string('distributor_code', 32);

            // upc | ean | mpn
            $t->string('identifier_type', 8);

            // Normalised — never the raw feed value. See CatalogIdentifierService.
            $t->string('value_norm', 96);

            $t->timestamps();

            // The matching join: find every row sharing this type+value.
            $t->index(['identifier_type', 'value_norm'], 'ci_type_value_idx');

            // Excludes a row's own distributor when looking for counterparts.
            $t->index(['identifier_type', 'value_norm', 'distributor_code'], 'ci_type_value_dist_idx');

            $t->index('distributor_catalog_id', 'ci_catalog_idx');

            // A row can hold two values of one type (a 13-digit EAN also
            // stored as its 12-digit UPC-A form), so the value is in the key.
            $t->unique(
                ['distributor_catalog_id', 'identifier_type', 'value_norm'],
                'ci_row_type_value_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_identifiers');
    }
};
CID_0_EOF

# ------------------------------------------------------------------ model
cat > 'app/Models/CatalogIdentifier.php' <<'CID_1_EOF'
<?php

// MARKER-CATALOG-IDENTIFIERS

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogIdentifier extends Model
{
    protected $fillable = [
        'distributor_catalog_id', 'distributor_code',
        'identifier_type', 'value_norm',
    ];

    public const TYPE_UPC = 'upc';
    public const TYPE_EAN = 'ean';
    public const TYPE_MPN = 'mpn';

    public function catalogRow()
    {
        return $this->belongsTo(PlatformDistributorCatalog::class, 'distributor_catalog_id');
    }
}
CID_1_EOF

# ------------------------------------------------------------------ service
cat > 'app/Services/Distributors/CatalogIdentifierService.php' <<'CID_2_EOF'
<?php

// MARKER-CATALOG-IDENTIFIERS

namespace App\Services\Distributors;

use App\Models\PlatformDistributorCatalog;

/**
 * Turns a catalog row into the set of identifiers it can be matched on.
 *
 * The point of the set: a single coalesced key only matches when both sides
 * pick the same rung, and they often don't. HLC frequently has an EAN and no
 * UPC; BTI has a UPC and no EAN; both have brand+MPN. Emitting every
 * identifier a row carries lets any one of them do the matching.
 */
class CatalogIdentifierService
{
    /** Shorter than this after normalising and it isn't an identifier. */
    private const MIN_LENGTH = 4;

    /**
     * Values that appear across unrelated products. Matching on one of these
     * would merge things that have nothing to do with each other, which is
     * worse than not matching at all.
     */
    private const JUNK = [
        'NA', 'N/A', 'NONE', 'NULL', 'NOMPN', 'UNKNOWN', 'TBD',
        '0', '00', '000', '0000', '00000', '000000',
        '9999', '99999', '999999', 'XXXX', 'XXXXX',
    ];

    /**
     * @return array<int,array{type:string,value:string}>
     */
    public function forRow(PlatformDistributorCatalog $row): array
    {
        $out = [];

        foreach ($this->barcodes((string) $row->upc) as $v) {
            $out[] = ['type' => 'upc', 'value' => $v];
        }

        foreach ($this->barcodes((string) $row->ean) as $v) {
            $out[] = ['type' => 'ean', 'value' => $v];
        }

        $mpn = $this->mpn((string) $row->manufacturer, (string) $row->manufacturer_sku);
        if ($mpn !== null) {
            $out[] = ['type' => 'mpn', 'value' => $mpn];
        }

        // Same type+value twice on one row would violate the unique key.
        $seen = [];
        return array_values(array_filter($out, function ($i) use (&$seen) {
            $k = $i['type'] . '|' . $i['value'];
            if (isset($seen[$k])) { return false; }
            $seen[$k] = true;
            return true;
        }));
    }

    /**
     * A barcode yields one value, or two when it is a 13-digit number with a
     * leading zero — that form IS a zero-padded UPC-A, so both are stored and
     * a genuinely padded pair still meets. It does NOT make unrelated UPC and
     * EAN numbers equal; nothing can, and nothing here pretends otherwise.
     *
     * @return array<int,string>
     */
    private function barcodes(string $raw): array
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '' || strlen($digits) < self::MIN_LENGTH) {
            return [];
        }
        if ($this->isJunk($digits)) {
            return [];
        }

        $out = [$digits];

        if (strlen($digits) === 13 && str_starts_with($digits, '0')) {
            $out[] = substr($digits, 1);
        } elseif (strlen($digits) === 12) {
            $out[] = '0' . $digits;   // the same product's EAN-13 form
        }

        return array_values(array_unique($out));
    }

    /**
     * Brand-qualified part number. An MPN alone is not unique across
     * manufacturers — plenty of brands ship a part called "100" — so the
     * brand travels with it and a bare MPN is never emitted.
     */
    private function mpn(string $brand, string $sku): ?string
    {
        $b = $this->squash($brand);
        $s = $this->squash($sku);

        if ($b === '' || $s === '') {
            return null;
        }
        if (strlen($s) < self::MIN_LENGTH || $this->isJunk($s)) {
            return null;
        }

        return $b . '|' . $s;
    }

    /** Upper-case, strip everything that isn't alphanumeric. */
    private function squash(string $v): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', trim($v)) ?? '');
    }

    private function isJunk(string $v): bool
    {
        return in_array(strtoupper($v), self::JUNK, true);
    }
}
CID_2_EOF

# ------------------------------------------------------------------ command
cat > 'app/Console/Commands/IndexCatalogIdentifiers.php' <<'CID_3_EOF'
<?php

// MARKER-CATALOG-IDENTIFIERS

namespace App\Console\Commands;

use App\Models\PlatformDistributorCatalog;
use App\Services\Distributors\CatalogIdentifierService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * (Re)builds catalog_identifiers. Safe to re-run — a distributor's rows are
 * cleared and rebuilt inside a transaction, so a half-finished run can't
 * leave the index partially populated for that distributor.
 */
class IndexCatalogIdentifiers extends Command
{
    protected $signature = 'catalog:index-identifiers
        {code? : distributor code, omit for all}
        {--chunk=500}';

    protected $description = 'Rebuild the cross-distributor identifier index';

    public function handle(CatalogIdentifierService $svc): int
    {
        $code = $this->argument('code');

        $codes = $code
            ? [$code]
            : PlatformDistributorCatalog::query()
                ->select('distributor_code')->distinct()
                ->pluck('distributor_code')->all();

        foreach ($codes as $dist) {
            $total = PlatformDistributorCatalog::where('distributor_code', $dist)
                ->where('is_active', true)->count();

            $this->info("{$dist}: {$total} active rows");
            $bar = $this->output->createProgressBar($total);

            DB::table('catalog_identifiers')->where('distributor_code', $dist)->delete();

            $written = 0;
            $noneAtAll = 0;
            $byType = ['upc' => 0, 'ean' => 0, 'mpn' => 0];

            PlatformDistributorCatalog::where('distributor_code', $dist)
                ->where('is_active', true)
                ->chunkById((int) $this->option('chunk'), function ($rows) use (
                    $svc, $dist, &$written, &$noneAtAll, &$byType, $bar
                ) {
                    $batch = [];
                    $now = now();

                    foreach ($rows as $row) {
                        $ids = $svc->forRow($row);
                        if (! $ids) {
                            $noneAtAll++;          // unmatchable by any key
                        }
                        foreach ($ids as $i) {
                            $byType[$i['type']] = ($byType[$i['type']] ?? 0) + 1;
                            $batch[] = [
                                'distributor_catalog_id' => $row->id,
                                'distributor_code'       => $dist,
                                'identifier_type'        => $i['type'],
                                'value_norm'             => $i['value'],
                                'created_at'             => $now,
                                'updated_at'             => $now,
                            ];
                        }
                        $bar->advance();
                    }

                    if ($batch) {
                        // insertOrIgnore: the unique key is the guard, and a
                        // duplicate inside one feed shouldn't abort the run.
                        DB::table('catalog_identifiers')->insertOrIgnore($batch);
                        $written += count($batch);
                    }
                });

            $bar->finish();
            $this->newLine();
            $this->line("  identifiers written: {$written}"
                . "  (upc {$byType['upc']} · ean {$byType['ean']} · mpn {$byType['mpn']})");

            if ($noneAtAll > 0) {
                $this->warn("  {$noneAtAll} rows produced NO identifier — unmatchable by any key");
            }
            $this->newLine();
        }

        // What the matching pass will actually have to work with.
        $shared = DB::table('catalog_identifiers as a')
            ->join('catalog_identifiers as b', function ($j) {
                $j->on('a.identifier_type', '=', 'b.identifier_type')
                  ->on('a.value_norm', '=', 'b.value_norm')
                  ->on('a.distributor_code', '<', 'b.distributor_code');
            })
            ->distinct()
            ->count(DB::raw('CONCAT(a.identifier_type, a.value_norm)'));

        $this->info("Identifier values shared across distributors: {$shared}");
        return self::SUCCESS;
    }
}
CID_3_EOF

php -l app/Models/CatalogIdentifier.php
php -l app/Services/Distributors/CatalogIdentifierService.php
php -l app/Console/Commands/IndexCatalogIdentifiers.php

echo
echo "catalog-identifiers applied."
