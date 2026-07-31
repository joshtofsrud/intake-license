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
