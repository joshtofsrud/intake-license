<?php
// MARKER-SALES-FIND — thin client over the Places API (New) Text Search endpoint.

namespace App\Services\Sales;

use App\Models\SalesSetting;
use Illuminate\Support\Facades\Http;

class PlacesClient
{
    public const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    /** Everything a prospect row needs. Contact fields push the call into the Enterprise SKU — that's deliberate; it's the enrichment. */
    public const FIELDS = 'nextPageToken,places.id,places.displayName,places.formattedAddress,places.addressComponents,places.location,places.rating,places.userRatingCount,places.websiteUri,places.nationalPhoneNumber,places.businessStatus,places.primaryType,places.googleMapsUri,places.regularOpeningHours.weekdayDescriptions';

    /** Location only — the cheapest way to turn "Spokane, WA" into coordinates without the Geocoding API. */
    public const FIELDS_LOCATE = 'places.location,places.formattedAddress';

    public int $requests = 0;

    public function __construct(private ?string $key = null)
    {
        $this->key ??= SalesSetting::placesKey();
    }

    public function configured(): bool { return (bool) $this->key; }

    /** @return array{lat:float,lng:float,label:string}|null */
    public function locate(string $place): ?array
    {
        $r = $this->post(['textQuery' => $place, 'pageSize' => 1], self::FIELDS_LOCATE);
        $p = $r['places'][0] ?? null;
        if (! $p || empty($p['location'])) return null;
        return ['lat' => (float) $p['location']['latitude'], 'lng' => (float) $p['location']['longitude'], 'label' => $p['formattedAddress'] ?? $place];
    }

    /**
     * Text search biased to a circle. Up to $maxPages pages of 20.
     * @return array<int,array<string,mixed>> raw place objects
     */
    public function search(string $query, float $lat, float $lng, int $radiusMeters, int $maxPages = 3): array
    {
        $out = [];
        $token = null;
        for ($i = 0; $i < $maxPages; $i++) {
            $body = [
                'textQuery'    => $query,
                'pageSize'     => 20,
                'locationBias' => ['circle' => ['center' => ['latitude' => $lat, 'longitude' => $lng], 'radius' => min($radiusMeters, 50000)]],
            ];
            if ($token) $body['pageToken'] = $token;
            $r = $this->post($body, self::FIELDS);
            foreach ($r['places'] ?? [] as $p) $out[] = $p;
            $token = $r['nextPageToken'] ?? null;
            if (! $token) break;
        }
        return $out;
    }

    /** MARKER-SALES-BOARD — one place by id (Place Details). */
    public function details(string $placeId): ?array
    {
        if (! $this->key) throw new \RuntimeException('Google Places key is not set.');
        $this->requests++;
        $fields = str_replace(['nextPageToken,', 'places.'], '', self::FIELDS);
        $res = Http::timeout(15)->withHeaders(['X-Goog-Api-Key' => $this->key, 'X-Goog-FieldMask' => $fields])
            ->get('https://places.googleapis.com/v1/places/' . rawurlencode($placeId));
        if ($res->status() === 404) return null;
        if (! $res->ok()) throw new \RuntimeException('Places: ' . ($res->json('error.message') ?? ('HTTP ' . $res->status())));
        return $res->json() ?: null;
    }

    /** MARKER-SALES-BOARD — best single match for a free-text query (used when a prospect has no place id). */
    public function findOne(string $query): ?array
    {
        $r = $this->post(['textQuery' => $query, 'pageSize' => 1], self::FIELDS);
        return $r['places'][0] ?? null;
    }

    /** Normalise one raw place into the shape the finder and prospects use. */
    public static function normalise(array $p): array
    {
        $city = $state = $postcode = null;
        foreach ($p['addressComponents'] ?? [] as $c) {
            $types = $c['types'] ?? [];
            if (in_array('locality', $types, true))                    $city     = $c['longText'] ?? $city;
            if (! $city && in_array('postal_town', $types, true))       $city     = $c['longText'] ?? null;
            if (! $city && in_array('sublocality', $types, true))       $city     = $c['longText'] ?? null;
            if (in_array('administrative_area_level_1', $types, true))  $state    = $c['shortText'] ?? $state;
            if (in_array('postal_code', $types, true))                  $postcode = $c['longText'] ?? $postcode;
        }
        $hours = $p['regularOpeningHours']['weekdayDescriptions'] ?? null;
        return [
            'place_id'     => $p['id'] ?? null,
            'shop'         => $p['displayName']['text'] ?? '',
            'address'      => $p['formattedAddress'] ?? null,
            'city'         => $city,
            'state'        => $state,
            'postcode'     => $postcode,
            'lat'          => isset($p['location']['latitude']) ? (float) $p['location']['latitude'] : null,
            'lng'          => isset($p['location']['longitude']) ? (float) $p['location']['longitude'] : null,
            'rating'       => isset($p['rating']) ? (float) $p['rating'] : null,
            'rating_count' => isset($p['userRatingCount']) ? (int) $p['userRatingCount'] : null,
            'website'      => $p['websiteUri'] ?? null,
            'phone'        => $p['nationalPhoneNumber'] ?? null,
            'gstatus'      => $p['businessStatus'] ?? null,   // Google's business status; 'status' is ours (new|prospect|tenant)
            'primary_type' => $p['primaryType'] ?? null,
            'maps_url'     => $p['googleMapsUri'] ?? null,
            'hours'        => $hours ? self::compactHours($hours) : null,
        ];
    }

    /** "Monday: 10:00 AM – 6:00 PM" x7 → "Mon–Sat 10–6, Sun closed" style, capped for the column. */
    public static function compactHours(array $lines): string
    {
        $short = array_map(function ($l) {
            $l = preg_replace('/^(\w{3})\w*: /', '$1 ', $l);
            $l = str_replace([':00', ' AM', ' PM', ' – '], ['', 'a', 'p', '–'], $l);
            return $l;
        }, $lines);
        return mb_substr(implode(', ', $short), 0, 255);
    }

    private function post(array $body, string $fields): array
    {
        if (! $this->key) {
            throw new \RuntimeException('Google Places key is not set.');
        }
        $this->requests++;
        $res = Http::timeout(15)
            ->withHeaders(['X-Goog-Api-Key' => $this->key, 'X-Goog-FieldMask' => $fields])
            ->post(self::ENDPOINT, $body);
        if (! $res->ok()) {
            $msg = $res->json('error.message') ?? ('HTTP ' . $res->status());
            throw new \RuntimeException('Places: ' . $msg);
        }
        return $res->json() ?? [];
    }
}
