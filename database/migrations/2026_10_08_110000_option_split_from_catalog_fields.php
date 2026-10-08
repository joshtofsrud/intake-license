<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-OPTION-FIELDS: option splitting reads each distributor's own fields
 * (BTI "Casing", HLC "Tire Compound", QBP "Tire Bead"…) instead of parsing
 * titles, maps their spellings to one name, and stores the clean values on
 * each catalog row (spec_attrs). Additive only.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('variant_split_rules', function (Blueprint $t) {
            if (! Schema::hasColumn('variant_split_rules', 'fields')) { $t->json('fields')->nullable()->after('applies_to'); }
            if (! Schema::hasColumn('variant_split_rules', 'mixed_fields')) { $t->json('mixed_fields')->nullable()->after('fields'); }
            if (! Schema::hasColumn('variant_split_rules', 'aliases')) { $t->json('aliases')->nullable()->after('words'); }
        });
        Schema::table('platform_distributor_catalogs', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_distributor_catalogs', 'spec_attrs')) { $t->json('spec_attrs')->nullable(); }
            if (! Schema::hasColumn('platform_distributor_catalogs', 'spec_attrs_at')) { $t->timestamp('spec_attrs_at')->nullable(); }
        });

        $now = now();
        $set = function (string $attr, array $vals) use ($now) {
            $vals = array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $vals) + ['updated_at' => $now];
            $n = DB::table('variant_split_rules')->where('attribute', $attr)->where('applies_to', 'Tire')->update($vals);
            if ($n === 0) {
                DB::table('variant_split_rules')->insert($vals + ['attribute' => $attr, 'applies_to' => 'Tire', 'is_active' => true,
                    'sort' => (int) DB::table('variant_split_rules')->max('sort') + 1, 'created_at' => $now,
                    'words' => $vals['words'] ?? json_encode([])]);
            }
        };
        $set('Casing', [
            'fields'       => ['Casing'],
            'mixed_fields' => ['Tire Technology'],
            'words'        => ['EXO+', 'EXO', 'DoubleDown', 'DH', 'SilkShield', 'Super Gravity', 'Super Downhill', 'Super Trail',
                               'Super Ground', 'Super Race', 'SnakeSkin', 'GRID Gravity', 'GRID Trail', 'GRID',
                               'Downhill Casing', 'Enduro Casing', 'Trail Casing'],
            'aliases'      => ['DD' => 'DoubleDown', 'Double Down' => 'DoubleDown', 'EXO Protection' => 'EXO', 'EXO Plus' => 'EXO+'],
        ]);
        $set('Compound', [
            'fields'       => ['Compound', 'Tire Compound', 'Rubber Compound'],
            'mixed_fields' => [],
            'words'        => ['MaxxTerra', 'MaxxGrip', 'MaxxSpeed', 'Dual', 'Single', 'Addix Ultra Soft', 'Addix SpeedGrip',
                               'Addix Soft', 'Addix Speed', 'Addix', 'T9', 'T7', 'Gripton', 'BlackChili', 'SuperSoft', 'High Grip', 'Fast Rolling'],
            'aliases'      => ['3C MaxxTerra' => 'MaxxTerra', '3C MaxxGrip' => 'MaxxGrip', '3C MaxxSpeed' => 'MaxxSpeed',
                               '3CT' => 'MaxxTerra', '3CG' => 'MaxxGrip', 'DC' => 'Dual', 'Dual Compound' => 'Dual', 'Single Compound' => 'Single'],
        ]);
        $set('Bead', [
            'fields'       => ['Bead', 'Tire Bead'],
            'mixed_fields' => [],
            'words'        => ['Folding', 'Wire'],
            'aliases'      => ['Foldable' => 'Folding', 'Kevlar' => 'Folding', 'Aramid' => 'Folding', 'Steel' => 'Wire'],
        ]);
        $set('TPI', [
            'fields'       => ['TPI'],
            'mixed_fields' => [],
            'words'        => [],
            'aliases'      => [],
        ]);
    }

    public function down(): void
    {
        // additive: columns stay (rollback does not reverse migrations).
    }
};
