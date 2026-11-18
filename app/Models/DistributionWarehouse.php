<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** MARKER-DISTRIBUTION-WAREHOUSES: one supplier warehouse, as found in a distributor's feed. */
class DistributionWarehouse extends Model
{
    use HasUuids;

    protected $table = 'platform_distribution_warehouses';

    protected $fillable = [
        'distributor_code', 'code', 'name', 'short_name', 'ship_from_zip', 'ship_from_city', 'ship_from_state',
        'handling_days', 'shown_to_shops', 'dropship_enabled', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'shown_to_shops'   => 'boolean',
        'dropship_enabled' => 'boolean',
        'handling_days'    => 'integer',
        'first_seen_at'    => 'datetime',
        'last_seen_at'     => 'datetime',
    ];

    private const STATES = [
        'Alabama' => 'AL', 'Alaska' => 'AK', 'Arizona' => 'AZ', 'Arkansas' => 'AR', 'California' => 'CA', 'Colorado' => 'CO',
        'Connecticut' => 'CT', 'Delaware' => 'DE', 'Florida' => 'FL', 'Georgia' => 'GA', 'Hawaii' => 'HI', 'Idaho' => 'ID',
        'Illinois' => 'IL', 'Indiana' => 'IN', 'Iowa' => 'IA', 'Kansas' => 'KS', 'Kentucky' => 'KY', 'Louisiana' => 'LA',
        'Maine' => 'ME', 'Maryland' => 'MD', 'Massachusetts' => 'MA', 'Michigan' => 'MI', 'Minnesota' => 'MN', 'Mississippi' => 'MS',
        'Missouri' => 'MO', 'Montana' => 'MT', 'Nebraska' => 'NE', 'Nevada' => 'NV', 'New Hampshire' => 'NH', 'New Jersey' => 'NJ',
        'New Mexico' => 'NM', 'New York' => 'NY', 'North Carolina' => 'NC', 'North Dakota' => 'ND', 'Ohio' => 'OH', 'Oklahoma' => 'OK',
        'Oregon' => 'OR', 'Pennsylvania' => 'PA', 'Rhode Island' => 'RI', 'South Carolina' => 'SC', 'South Dakota' => 'SD',
        'Tennessee' => 'TN', 'Texas' => 'TX', 'Utah' => 'UT', 'Vermont' => 'VT', 'Virginia' => 'VA', 'Washington' => 'WA',
        'West Virginia' => 'WV', 'Wisconsin' => 'WI', 'Wyoming' => 'WY',
    ];

    /**
     * What a shop sees for this warehouse. Master admin's short name wins;
     * otherwise it is worked out from the feed's name: the distributor prefix
     * comes off, a "Q-Central - Minnesota" style name keeps the part before
     * the dash, and a plain state name becomes its two letters.
     */
    public function label(): string
    {
        $short = trim((string) ($this->short_name ?? ''));
        return $short !== '' ? $short : self::defaultLabel((string) $this->name, (string) $this->code);
    }

    public static function defaultLabel(string $name, string $code = ''): string
    {
        $n = trim(preg_replace('/^.*?\s+[—–]\s+/u', '', $name, 1) ?? $name);
        if (str_contains($n, ' - ')) {
            $n = trim(explode(' - ', $n, 2)[0]);
        }
        // longest state names first, so "West Virginia" never becomes "West VA"
        $states = self::STATES;
        uksort($states, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($states as $state => $abbr) {
            $n = preg_replace('/\b' . preg_quote($state, '/') . '\b/u', $abbr, $n) ?? $n;
        }
        return $n !== '' ? $n : ($code !== '' ? $code : $name);
    }
}
