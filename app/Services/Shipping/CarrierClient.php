<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * MARKER-SHIPPING-RATES: one carrier's rating API, using the SHOP's own credentials.
 *
 * Request building and response reading are pure static functions (testable with a
 * sample payload, no network). Only token() and rates() touch the network.
 *
 * Package  ['oz' => float, 'l' => ?float, 'w' => ?float, 'h' => ?float]
 * Address  ['zip' =>, 'state' => ?, 'country' => 'US']
 * Option   ['carrier' =>, 'service' => code, 'name' =>, 'cents' => int, 'days' => ?int]
 */
abstract class CarrierClient
{
    abstract public static function code(): string;
    abstract public static function label(): string;
    /** @return array<string,string> service code => name the shop may offer */
    abstract public static function services(): array;
    /** @return array<string,array{label:string,secret?:bool,required?:bool}> credential fields to ask for */
    abstract public static function fields(): array;

    abstract protected function tokenRequest(array $c): array;          // [url, mode('basic'|'form'|'json'), payload]
    abstract protected function rateCalls(array $c, array $from, array $to, array $packages, array $services): array; // [[url, payload, headers, meta], ...]
    abstract public static function parse(array $responses, array $services): array;  // list of decoded responses -> options

    public function __construct(protected bool $sandbox = false) {}

    abstract protected function host(): string;

    public function token(array $c): string
    {
        $key = 'carrier_tok:' . static::code() . ':' . md5(($c['client_id'] ?? '') . ($this->sandbox ? 's' : 'p'));
        if ($t = Cache::get($key)) return $t;

        [$path, $mode, $payload] = $this->tokenRequest($c);
        $req = Http::timeout(15)->acceptJson();
        if ($mode === 'basic') $req = $req->withBasicAuth($c['client_id'], $c['client_secret'])->asForm();
        elseif ($mode === 'form') $req = $req->asForm();
        else $req = $req->asJson();
        $r = $req->post($this->host() . $path, $payload);
        if (! $r->successful() || ! ($tok = $r->json('access_token'))) {
            throw new \RuntimeException(static::label() . ' sign-in failed: ' . self::errorText($r));
        }
        Cache::put($key, $tok, max(60, (int) $r->json('expires_in', 3600) - 120));
        return $tok;
    }

    /** @return array{ok:bool,message:string} */
    public function test(array $c): array
    {
        try {
            $this->token($c);
            return ['ok' => true, 'message' => static::label() . ' accepted these credentials' . ($this->sandbox ? ' (test mode).' : '.')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param string[] $services service codes the shop offers (empty = all)
     * @return Option[]  list of options; throws \RuntimeException with the carrier's own message if no call worked
     */
    public function rates(array $c, array $from, array $to, array $packages, array $services = []): array
    {
        $services = $services ?: array_keys(static::services());
        $token = $this->token($c);
        $responses = [];
        $errors = [];
        foreach ($this->rateCalls($c, $from, $to, $packages, $services) as $call) {
            $r = Http::timeout(20)->acceptJson()->withToken($token)->withHeaders($call['headers'] ?? [])->asJson()->post($this->host() . $call['path'], $call['payload']);
            if ($r->successful()) {
                $responses[] = ['meta' => $call['meta'] ?? [], 'body' => (array) $r->json()];
            } else {
                $errors[] = self::errorText($r);
            }
        }
        if (! $responses) {
            throw new \RuntimeException(static::label() . ' gave no rates: ' . ($errors[0] ?? 'no answer'));
        }
        return static::parse($responses, $services);
    }

    protected static function errorText($r): string
    {
        $j = (array) $r->json();
        $msg = $j['response']['errors'][0]['message']      // UPS
            ?? $j['errors'][0]['message']                   // FedEx
            ?? $j['error']['message']                       // USPS
            ?? $j['error_description']
            ?? $j['message']
            ?? null;
        return trim(($msg ?: 'HTTP ' . $r->status()) . ' (HTTP ' . $r->status() . ')');
    }

    protected static function lb(float $oz): float { return max(0.1, round($oz / 16, 2)); }

    public static function registry(): array
    {
        return ['ups' => UpsClient::class, 'usps' => UspsClient::class, 'fedex' => FedexClient::class];
    }
}
