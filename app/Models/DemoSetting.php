<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-DEMO-RESET — key/value for demo state: epoch, anchor week, pause, kill.
class DemoSetting extends Model
{
    protected $table = 'demo_settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        $row = static::find($key);
        return $row ? $row->value : $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
