<?php

use Illuminate\Database\Migrations\Migration;

/**
 * bring the intake.works Pricing page's comparison
 * table in line with what each plan really includes (and now enforces):
 *   Starter  — just you; no POS or inventory; classes are an add-on
 *   Branded  — up to 3 team members; POS + inventory up to 120 items
 *   Scale    — up to 10 team members; unlimited inventory (POS add-on included)
 *   Online store — an add-on on Branded and Scale
 * Only rows about those topics are changed (missing ones are added); every
 * other row is left exactly as written. Skips safely if the table isn't the
 * Starter / Branded / Scale one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $plat = \App\Models\Tenant::where('is_platform', true)->first();
        if (! $plat) return;
        $page = \App\Models\Tenant\TenantPage::where('tenant_id', $plat->id)->where('slug', 'pricing')->first();
        if (! $page) return;

        $want = [
            ['re' => '/^\s*(team members?|users|seats|staff accounts?)\b/i',                   'feature' => 'Team members',      'values' => ['Just you', 'Up to 3', 'Up to 10']],
            ['re' => '/^\s*(pos|point of sale)\b/i',         'feature' => 'Point of sale',     'values' => ['no', 'yes', 'yes']],
            ['re' => '/^\s*inventory\b/i',                        'feature' => 'Inventory',         'values' => ['no', 'Up to 120 items', 'Unlimited']],
            ['re' => '/^\s*class(es)?\b/i',                                  'feature' => 'Classes',           'values' => ['add-on', 'yes', 'yes']],
            ['re' => '/^\s*(online store|storefront|e-?commerce)\b/i',    'feature' => 'Online store',      'values' => ['no', 'add-on', 'add-on']],
        ];

        foreach ($page->sections()->where('section_type', 'comparison_table')->get() as $s) {
            $c = $s->content ?? [];
            $cols = array_map(fn ($x) => strtolower(trim((string) $x)), (array) ($c['competitors'] ?? []));
            if (count($cols) !== 3 || ! str_contains($cols[0], 'starter') || ! str_contains($cols[1], 'branded') || ! str_contains($cols[2], 'scale')) continue;

            $rows = array_values((array) ($c['rows'] ?? []));
            foreach ($want as $w) {
                $hit = false;
                foreach ($rows as $i => $r) {
                    if (is_array($r) && preg_match($w['re'], (string) ($r['feature'] ?? ''))) {
                        $rows[$i]['values'] = $w['values'];
                        $hit = true;
                    }
                }
                if (! $hit) $rows[] = ['feature' => $w['feature'], 'values' => $w['values']];
            }
            $c['rows'] = $rows;
            $s->content = $c;
            $s->save();
        }
    }

    public function down(): void {}
};
