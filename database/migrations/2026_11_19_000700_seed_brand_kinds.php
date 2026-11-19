<?php

use App\Models\PlatformSettings;
use Illuminate\Database\Migrations\Migration;

/**
 * MARKER-BRAND-KINDS: the first brand's kinds, as found in its tags, most
 * specific first. Editable on Brand suppliers; this only fills an empty list.
 */
return new class extends Migration {
    public function up(): void
    {
        $ps = PlatformSettings::current();
        $ds = (array) ($ps->distribution_settings ?? []);
        if (! empty($ds['brand_kinds']['KONA'])) {
            return;
        }
        $ds['brand_kinds']['KONA'] = [
            'Electric MTB', 'Electric Gravel / Commuter', 'Electric Commuter', 'Electric',
            'Mountain Full Suspension', 'Mountain Hardtail', 'MTB Hardtail', 'Classic Hardtails', 'Mountain',
            'Drop Bar / Gravel', 'Bike Packing', 'Urban', 'Kids',
        ];
        $ps->distribution_settings = $ds;
        $ps->save();
        PlatformSettings::forget();
    }

    public function down(): void
    {
    }
};
