<?php

namespace App\Models;

use App\Services\Pos\Adapters\PosAdapterRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * MARKER-POS-CONNECT: Intake's own app credentials for one POS adapter.
 * Set once in master admin; every shop authorises against these.
 * Endpoints are editable so a POS changing its URLs needs no code change.
 */
class PlatformPosAdapter extends Model
{
    protected $table = 'platform_pos_adapters';

    protected $fillable = [
        'code', 'label', 'is_active', 'client_id', 'client_secret',
        'scopes', 'authorize_url', 'token_url', 'api_base', 'redirect_uri', // MARKER-POS-CALLBACK
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'client_id'     => 'encrypted',
        'client_secret' => 'encrypted',
    ];

    /** The row for an adapter code, seeded from the adapter's own defaults. */
    public static function forCode(string $code): self
    {
        $class = app(PosAdapterRegistry::class)->adapterClass($code);
        $d = $class ? $class::defaults() : [];

        return static::firstOrCreate(['code' => $code], [
            'label'         => $d['label'] ?? $code,
            'is_active'     => false,
            'scopes'        => $d['scopes'] ?? null,
            'authorize_url' => $d['authorize_url'] ?? null,
            'token_url'     => $d['token_url'] ?? null,
            'api_base'      => $d['api_base'] ?? null,
        ]);
    }

    public function configured(): bool
    {
        return filled($this->client_id) && filled($this->client_secret)
            && filled($this->authorize_url) && filled($this->token_url) && filled($this->api_base);
    }

    /** MARKER-POS-CALLBACK: the saved callback URL, or Intake's one generic POS callback. */
    public function callbackUrl(): string
    {
        return filled($this->redirect_uri) ? $this->redirect_uri : static::defaultCallbackUrl();
    }

    public static function defaultCallbackUrl(): string
    {
        return 'https://app.' . config('intake.domain', 'intake.works') . '/integrations/pos/callback';
    }

    public function displayLabel(): string
    {
        return $this->label ?: $this->code;
    }
}
