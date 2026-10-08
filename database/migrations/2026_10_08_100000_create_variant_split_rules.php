<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-OPTION-SPLIT: rules that split a run-together "Version" into its
 * own option dropdowns in the register picker (Casing, Compound, Bead…).
 * Platform-wide; edited in master admin › Option splitting.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('variant_split_rules')) {
            Schema::create('variant_split_rules', function (Blueprint $t) {
                $t->id();
                $t->string('attribute', 40);
                $t->string('applies_to', 120)->nullable();
                $t->json('words');
                $t->unsignedSmallInteger('sort')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        if (DB::table('variant_split_rules')->count() === 0) {
            $now = now();
            $rows = [
                ['Casing', 'Tire', ['EXO+', 'EXO', 'DoubleDown', 'DH', 'SilkShield', 'Super Gravity', 'Super Downhill', 'Super Trail',
                                    'Super Ground', 'Super Race', 'SnakeSkin', 'GRID Gravity', 'GRID Trail', 'GRID',
                                    'Downhill Casing', 'Enduro Casing', 'Trail Casing']],
                ['Compound', 'Tire', ['3C MaxxTerra', '3C MaxxGrip', '3C MaxxSpeed', 'MaxxTerra', 'MaxxGrip', 'MaxxSpeed', 'Dual', 'Single',
                                      'Addix Ultra Soft', 'Addix SpeedGrip', 'Addix Soft', 'Addix Speed', 'Addix',
                                      'T9', 'T7', 'Gripton', 'BlackChili', 'SuperSoft', 'High Grip', 'Fast Rolling']],
                ['Bead', 'Tire', ['Folding', 'Foldable', 'Wire']],
            ];
            foreach ($rows as $i => [$attr, $applies, $words]) {
                DB::table('variant_split_rules')->insert([
                    'attribute' => $attr, 'applies_to' => $applies, 'words' => json_encode($words),
                    'sort' => $i, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_split_rules');
    }
};
