<?php
// MARKER-PATCH-HLC16

namespace Database\Seeders;

use App\Models\CatalogTitlePattern;
use App\Models\CatalogTitleSetting;
use Illuminate\Database\Seeder;

class CatalogTitleSeeder extends Seeder
{
    public function run(): void
    {
        CatalogTitleSetting::updateOrCreate(
            ['distributor_code' => '*'],
            [
                'title_template' => '{brand} {model} {size} {color}',
                'subtitle_template' => '{mpn}',
                'color_attribute_priority' => ['Color', 'Primary Color'],
                'is_active' => true,
                'notes' => 'Global default recipe — edit per distributor by adding a row.',
            ]
        );

        // Ordered size grammar. Lower sort_order = tried first. Editable in admin.
        $patterns = [
            [10, 'dimension pair', '\d+(?:\.\d+)?\s*["”]?\s*[x×X]\s*\d+(?:\.\d+)?\s*["”c]?',
                'e.g. 27.5×2.4, 26"x1.75, 242x145, 700x25c'],
            [20, 'metric length', '\b\d+(?:\.\d+)?\s?(?:mm|cm)\b',
                'e.g. 780mm, 170mm, 216cm — bars, stems, posts, tape'],
            [30, 'inch diameter', '\b\d+(?:\.\d+)?\s?["”]',
                'e.g. 26" — wheels/tires stated as a single diameter'],
            [40, 'angle', '±?\s?\d+(?:\.\d+)?\s?°',
                'e.g. ±6° — stem angle'],
        ];

        foreach ($patterns as [$order, $label, $pattern, $notes]) {
            CatalogTitlePattern::updateOrCreate(
                ['distributor_code' => '*', 'label' => $label],
                ['pattern' => $pattern, 'sort_order' => $order, 'is_active' => true, 'notes' => $notes]
            );
        }
    }
}
