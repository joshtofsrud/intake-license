<?php
// MARKER-SALES-ROUTE — one place that merges a Places record into a prospect.

namespace App\Services\Sales;

use App\Models\SalesProspect;

class ProspectEnricher
{
    /** @return string a short human result ("phone · hours") — throws on API failure, returns 'no record' when Places has nothing */
    public static function enrich(SalesProspect $p, ?PlacesClient $client = null): string
    {
        $client ??= new PlacesClient();
        if (! $client->configured()) throw new \RuntimeException('Add a Google Places key on Find shops first.');

        $raw = $p->google_place_id
            ? $client->details($p->google_place_id)
            : $client->findOne(trim("{$p->shop} {$p->city} {$p->state}"));
        if (! $raw) { $p->update(['enriched_at' => now()]); return 'no record'; }

        $n = PlacesClient::normalise($raw);
        $changes = ['enriched_at' => now()];
        // Google-owned facts always refresh
        foreach (['hours' => 'hours', 'rating' => 'rating', 'rating_count' => 'rating_count', 'lat' => 'lat', 'lng' => 'lng',
                  'google_maps_url' => 'maps_url', 'primary_type' => 'primary_type', 'business_status' => 'gstatus',
                  'google_place_id' => 'place_id', 'postcode' => 'postcode'] as $col => $k) {
            if ($n[$k] !== null && $n[$k] !== '') $changes[$col] = $n[$k];
        }
        // things a person may have typed are only ever filled, never overwritten
        foreach (['phone', 'website', 'address', 'city', 'state'] as $col) {
            if (blank($p->{$col}) && ! empty($n[$col])) $changes[$col] = $n[$col];
        }
        $p->update($changes);
        $p->activities()->create(['type' => 'system', 'body' => 'Details pulled from Places']);
        return ($n['phone'] ?: 'no phone') . ' · ' . ($n['hours'] ? 'hours' : 'no hours');
    }
}
