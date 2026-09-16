<?php
// MARKER-SALES-FIND — key/value settings for the sales workspace (same shape as RaiseSetting).

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SalesSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';
    protected $fillable   = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::find($key)?->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Google Places key, stored encrypted; empty string means "leave it". */
    public static function putPlacesKey(?string $plain): void
    {
        static::put('places_api_key', $plain ? Crypt::encryptString($plain) : null);
    }

    public static function placesKey(): ?string
    {
        $v = static::get('places_api_key');
        if (! $v) return null;
        try { return Crypt::decryptString($v); } catch (\Throwable $e) { return null; }
    }

    /** Estimated cents per billable Places request. Text Search with contact fields is Enterprise tier (~$0.035–0.040). */
    public static function placesCostCents(): int
    {
        return (int) (static::get('places_cost_cents', '4') ?: 4);
    }

    public static function placesBudgetCents(): int
    {
        return (int) (static::get('places_budget_cents', '5000') ?: 5000);
    }
}
