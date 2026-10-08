<?php

use App\Models\ChangelogEntry;
use Illuminate\Database\Migrations\Migration;

/**
 * the Oct 7 register and search entries,
 * rewritten to cover the whole day's work. Matched by title, so it updates
 * the entries added by or creates them if missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $entries = [
            'Find products faster at the register' => [
                'category' => 'Register',
                'body'     => 'Each product now shows once, with a button for every size and color and the stock for each. Filter by what is on the shelf here, at your other locations, or everything you carry; narrow by brand or supplier; and sort by best match, price or name. While a search runs, a card shows exactly what is being searched, and long searches no longer stop at 15 results.',
            ],
            'Smarter search everywhere' => [
                'category' => 'Inventory',
                'body'     => 'Search is much faster and puts the right item first: exact barcodes and part numbers, then whole-word matches, then what you actually sell most. Type any words in any order to find an item by name, brand, color, size, SKU, barcode or any supplier\'s part number, and misspelled words are corrected for you. Works in Inventory, the register, the top search bar, work orders and receiving.',
            ],
        ];

        foreach ($entries as $title => $e) {
            $row = ChangelogEntry::where('title', $title)->first();
            if ($row) {
                $row->update($e);
                continue;
            }
            ChangelogEntry::create($e + [
                'title'          => $title,
                'shipped_on'     => '2026-10-07',
                'is_published'   => true,
                'is_highlighted' => false,
            ]);
        }
    }

    public function down(): void
    {
        // Content; edit in master admin if needed.
    }
};
