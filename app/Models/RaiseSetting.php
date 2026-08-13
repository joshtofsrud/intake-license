<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-RAISE-RECORDS
class RaiseSetting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::find($key)->value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Everything the wire-instruction message and the portal need. */
    public static function wireInstructions(): array
    {
        return [
            'bank'      => static::get('wire_bank'),
            'account'   => static::get('wire_account'),
            'routing'   => static::get('wire_routing'),
            'reference' => static::get('wire_reference'),
        ];
    }
}
