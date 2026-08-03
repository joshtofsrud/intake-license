<?php

// MARKER-BTI-TITLE-RULE — a distributor-level title recipe for BTI.
//
// Additive and idempotent: one row in catalog_title_settings, keyed on
// (distributor_code, category_key). The composer walks distributor rules
// before the '*' catch-all, and fills each field from the first rule that
// has a value — so this row overrides the global recipe for BTI only, and
// only for the fields it sets.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $row = [
            // {brand} {model} and nothing else. BTI's `name` comes from
            // item_description and already carries size, casing and compound;
            // the catch-all's extra tokens repeat it and append {unit} "EA"
            // plus {type0}, the category the item is already filed under.
            'title_template'    => '{brand} {model}',
            'subtitle_template' => '{mpn}',

            // Search is never read by a person — width beats tidiness.
            'search_template'   => '{mpn} {brand} {model} {type0} {size} {color} {allattr}',

            // Named attributes, so a future category rule using {size} or
            // {color} resolves them properly instead of falling back to
            // scraping the description (which finds TPI before the size).
            'size_attribute_priority'  => json_encode(['Size', 'Width']),
            'color_attribute_priority' => json_encode(['Color']),

            'is_active' => true,
            'notes'     => 'BTI ships a descriptive item_description as the model, '
                         . 'so the title adds only the brand. Attribute priorities are '
                         . 'set for category rules added later.',
            'updated_at' => $now,
        ];

        $exists = DB::table('catalog_title_settings')
            ->where('distributor_code', 'BTI')
            ->where(fn ($q) => $q->whereNull('category_key')->orWhere('category_key', ''))
            ->first();

        if ($exists) {
            DB::table('catalog_title_settings')->where('id', $exists->id)->update($row);
        } else {
            DB::table('catalog_title_settings')->insert($row + [
                'distributor_code' => 'BTI',
                'category_key'     => '',
                'created_at'       => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('catalog_title_settings')
            ->where('distributor_code', 'BTI')
            ->where(fn ($q) => $q->whereNull('category_key')->orWhere('category_key', ''))
            ->delete();
    }
};
