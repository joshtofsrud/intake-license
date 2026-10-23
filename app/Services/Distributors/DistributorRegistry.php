<?php

namespace App\Services\Distributors;

use App\Models\Tenant\TenantDistributorCatalogSubscription;
use InvalidArgumentException;
// QbpClient, HlcClient and BtiClient share this namespace, so no import.

/**
 * Resolves the right DistributorAdapter for a distributor code or a tenant
 * subscription. Nothing downstream (sync, availability, UI) should `new
 * HlcClient` directly — they ask the registry. Adding QBP/J&B/BTI later is a
 * single map entry plus the adapter class; the rest of the system is untouched.
 */
class DistributorRegistry
{
    /** distributor_code => adapter class */
    private const ADAPTERS = [
        'HLC' => HlcClient::class,
        // credentials ride in api_key as
        // "username:password"; BtiClient splits on the first colon, so the
        // shared (apiKey, region) constructor shape still holds.
        'BTI' => BtiClient::class,
        // present so master admin can hold the key and
        // test it. Only testConnection() works; the data methods throw.
        'QBP' => QbpClient::class,
        // an SFTP spreadsheet feed. One platform login serves every shop.
        'SHIMANO' => ShimanoClient::class,
    ];

    /** Marker stored in a shop's subscription when the platform login is used. */
    public const PLATFORM_MARK = 'platform';

    /**
     * Distributors whose login belongs to Intake, not to each shop. A shop
     * "connects" by switching it on; no key is asked for, and the one set in
     * master admin is used for every shop.
     */
    public function platformSupplied(string $code): bool
    {
        return strtoupper($code) === 'SHIMANO';
    }

    /**
     * what each distributor asks a shop for.
     *
     * HLC issues one API key. BTI issues a username and a password for HTTP
     * Basic. Both end up in the api_key credential slot (BTI's joined with a
     * colon) so make() keeps its single shape, but the shop sees the fields
     * its own distributor actually uses.
     *
     * @return array<int,array{name:string,label:string,type:string,hint:?string}>
     */
    public function credentialFields(string $code): array
    {
        // a shop is asked for nothing; see platformCredentialFields for master admin
        if ($this->platformSupplied($code)) {
            return [];
        }

        return match (strtoupper($code)) {
            'BTI' => [
                ['name' => 'username', 'label' => 'BTI username', 'type' => 'text',
                 'hint' => 'The account number on your BTI Inventory Data Download page.'],
                ['name' => 'password', 'label' => 'BTI password', 'type' => 'password',
                 'hint' => 'The long code on the same page.'],
            ],
            // two services, two keys, one header name.
            'QBP' => [
                ['name' => 'api_key', 'label' => 'API1 key (Point-of-Sale)', 'type' => 'password',
                 'hint' => 'Free from QBP. Supplies the catalog, dealer cost and stock.'],
                ['name' => 'cls_key', 'label' => 'API3 key (Content License Service)', 'type' => 'password',
                 'hint' => 'Optional, and licensed separately. Needed only for product images.'],
            ],
            default => [
                ['name' => 'api_key', 'label' => 'API key', 'type' => 'password',
                 'hint' => 'Issued by the distributor for your dealer account.'],
            ],
        };
    }

    /** What master admin types in for the platform login of a distributor. */
    public function platformCredentialFields(string $code): array
    {
        if (strtoupper($code) === 'SHIMANO') {
            return [
                ['name' => 'username', 'label' => 'SFTP username', 'type' => 'text',
                 'hint' => 'The Shimano SFTP account for the country, for example ibd_user_us.'],
                ['name' => 'password', 'label' => 'SFTP password', 'type' => 'password',
                 'hint' => 'Shared by every shop. Stored encrypted.'],
            ];
        }
        return $this->credentialFields($code);
    }

    /**
     * Master admin's version of packCredentials. Shimano's username and
     * password join with a colon like BTI's; every other distributor packs
     * exactly as it does for a shop.
     *
     * @param  array<string,string> $input
     */
    public function packPlatformCredentials(string $code, array $input, ?string $stored = null): ?string
    {
        if (strtoupper($code) === 'SHIMANO') {
            $stored = (string) $stored;
            [$cu, $cp] = str_contains($stored, ':') ? explode(':', $stored, 2) : ['', ''];
            $u = trim($input['username'] ?? '') ?: $cu;
            $p = trim($input['password'] ?? '') ?: $cp;
            return ($u === '' || $p === '') ? null : $u . ':' . $p;
        }
        return $this->packCredentials($code, $input, $stored);
    }

    /**
     * pull the CLS half out of a stored credential.
     *
     * Stored as "api1:cls". Returns '' when no CLS key has been given, which
     * is a supported state: everything except images still works.
     */
    public static function clsKey(?string $stored): string
    {
        $stored = (string) $stored;
        if (! str_contains($stored, ':')) {
            return '';
        }
        return trim(explode(':', $stored, 2)[1] ?? '');
    }

    /** Human label for a code, without building an adapter. */
    public function label(string $code): string
    {
        return match (strtoupper($code)) {
            'HLC' => 'HLC',
            'BTI' => 'BTI',
            'QBP' => 'QBP',
            'SHIMANO' => 'Shimano',
            default => strtoupper($code),
        };
    }

    /**
     * Collapse a submitted credential form into the stored shape. BTI's two
     * fields join with a colon because BtiClient splits on the first one.
     *
     * @param  array<string,string> $input
     */
    /**
     * merge submitted fields over what is stored.
     *
     * This used to require BOTH BTI fields and return null otherwise. The
     * form tells the user a blank field keeps the saved value, so typing
     * only a corrected password saved nothing at all and still reported
     * success — a silent no-op on the one screen where being wrong is
     * invisible, because the stored value is masked.
     *
     * Now each field replaces its own part and a blank one keeps what is
     * there, which is what the screen already promises.
     *
     * @param  array<string,string> $input
     * @param  string|null $stored  the currently saved credential
     */
    public function packCredentials(string $code, array $input, ?string $stored = null): ?string
    {
        $stored = (string) $stored;

        if ($this->platformSupplied($code)) {
            return self::PLATFORM_MARK;
        }

        if (strtoupper($code) === 'BTI') {
            $curUser = '';
            $curPass = '';
            if (str_contains($stored, ':')) {
                [$curUser, $curPass] = explode(':', $stored, 2);
            }

            $u = trim($input['username'] ?? '') ?: $curUser;
            $p = trim($input['password'] ?? '') ?: $curPass;

            // Still nothing to store if neither side has ever been given.
            return ($u === '' || $p === '') ? null : $u . ':' . $p;
        }

        // same colon packing BTI uses. A blank field
        // keeps whatever is stored, so saving one key never wipes the other.
        if (strtoupper($code) === 'QBP') {
            $curApi = $stored;
            $curCls = '';
            if (str_contains($stored, ':')) {
                [$curApi, $curCls] = explode(':', $stored, 2);
            }

            $api = trim($input['api_key'] ?? '') ?: $curApi;
            $cls = trim($input['cls_key'] ?? '') ?: $curCls;

            if ($api === '') {
                return null;   // no API1 key means no QBP at all
            }
            return $cls === '' ? $api : $api . ':' . $cls;
        }

        $k = trim($input['api_key'] ?? '');
        return $k !== '' ? $k : ($stored !== '' ? $stored : null);
    }

    /**
     * placeholder per credential field.
     *
     * Both BTI fields used to show the same masked string, which is the
     * username and password joined with a colon, so the username box hinted
     * at the password.
     *
     * @return array<string,string>
     */
    public function credentialHints(string $code, ?string $stored, callable $mask): array
    {
        $stored = (string) $stored;
        if ($stored === '' || $this->platformSupplied($code)) {
            return [];
        }

        if (strtoupper($code) === 'BTI' && str_contains($stored, ':')) {
            [$user, $pass] = explode(':', $stored, 2);
            return [
                // An account number, not a secret.
                'username' => $user,
                'password' => $mask($pass),
            ];
        }

        // QBP packs "api1:cls". Without this the API1 box
        // showed a mask of the JOINED string and the CLS box showed nothing,
        // so a saved license key looked like an empty field on every reload.
        if (strtoupper($code) === 'QBP') {
            if (str_contains($stored, ':')) {
                [$api, $cls] = explode(':', $stored, 2);
                return [
                    'api_key' => $mask($api),
                    'cls_key' => $mask($cls),
                ];
            }
            // API1 only — a valid state. Images are the sole casualty.
            return ['api_key' => $mask($stored)];
        }

        return ['api_key' => $mask($stored)];
    }

    /** @return array<int,string> supported distributor codes */
    public function supported(): array
    {
        return array_keys(self::ADAPTERS);
    }

    public function isSupported(string $code): bool
    {
        return isset(self::ADAPTERS[strtoupper($code)]);
    }

    /**
     * Build an adapter from raw credentials.
     *
     * @param array{api_key?: string, region?: string} $credentials
     */
    public function make(string $code, array $credentials): DistributorAdapter
    {
        $code = strtoupper($code);
        $class = self::ADAPTERS[$code] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("No distributor adapter registered for code [{$code}].");
        }

        $apiKey = (string) ($credentials['api_key'] ?? '');
        $region = (string) ($credentials['region'] ?? 'us');

        // a shop on a platform-supplied distributor stores only the marker;
        // the real login is the one master admin saved.
        if ($this->platformSupplied($code) && $apiKey === self::PLATFORM_MARK) {
            $conn   = \App\Models\PlatformDistributorConnection::where('distributor_code', $code)->first();
            $apiKey = (string) ($conn?->api_key ?? '');
            $region = (string) ($conn?->region ?: $region);
        }

        // Every current adapter shares the (apiKey, region) constructor shape.
        // If a future distributor needs a different signature, branch here.
        return new $class($apiKey, $region);
    }

    /**
     * Build an adapter for a tenant's saved subscription, reading its
     * (encrypted) credentials. Returns null when the distributor isn't
     * supported or the subscription has no usable key.
     */
    public function forSubscription(TenantDistributorCatalogSubscription $sub): ?DistributorAdapter
    {
        if (! $this->isSupported((string) $sub->distributor_code)) {
            return null;
        }

        $creds = (array) ($sub->credentials_encrypted ?? []);
        if ((string) ($creds['api_key'] ?? '') === '') {
            return null;
        }

        return $this->make((string) $sub->distributor_code, $creds);
    }
}
