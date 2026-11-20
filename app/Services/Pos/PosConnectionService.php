<?php

namespace App\Services\Pos;

use App\Models\Tenant;
use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\Adapters\PosAdapterRegistry;
use App\Support\JobFailureReporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * MARKER-POS-CONNECT: connects a shop to its POS and keeps the tokens fresh.
 *
 * Tenants live on their own hosts, so the OAuth round trip returns to ONE
 * fixed callback on the app host. The state handed to the POS is a random
 * nonce; what it stands for (shop, staff member, adapter, where to go back
 * to) stays server-side for ten minutes, so the callback trusts nothing the
 * browser brings back except the nonce. (Cache is fine here: a deploy
 * mid-connect only means clicking Connect again.)
 */
class PosConnectionService
{
    private const STATE_TTL_MIN = 10;

    public function __construct(private PosAdapterRegistry $registry) {}

    /** The callback URL registered with this POS — a master-admin setting (MARKER-POS-CALLBACK). */
    public static function callbackUrl(string $code): string
    {
        return \App\Models\PlatformPosAdapter::forCode($code)->callbackUrl();
    }

    /** Start a connection: remember who asked, return where to send them. */
    public function begin(Tenant $tenant, ?string $userId, string $code, string $returnUrl): string
    {
        $row = collect($this->registry->available())->firstWhere('code', $code);
        if (! $row) {
            throw new RuntimeException('That Connected POS is not available right now.');
        }

        $nonce = Str::random(48);
        Cache::put('pos_oauth:' . $nonce, [
            'tenant_id' => $tenant->id,
            'user_id'   => $userId,
            'code'      => $code,
            'return'    => $returnUrl,
        ], now()->addMinutes(self::STATE_TTL_MIN));

        return $this->registry->make($code)->authorizeUrl($nonce, self::callbackUrl($code));
    }

    /** The pending connection a nonce stands for, used once. */
    public function claim(string $nonce): ?array
    {
        return $nonce !== '' ? Cache::pull('pos_oauth:' . $nonce) : null;
    }

    /** Finish a connection: tokens, account, discovery. Returns the saved connection. */
    public function complete(array $pending, string $authCode, array $callback = []): TenantPosConnection
    {
        $code = $pending['code'];
        $adapter = $this->registry->make($code);
        $tok = $adapter->exchangeCode($authCode, self::callbackUrl($code), $callback);
        $ctx = (array) ($tok['context'] ?? []); // MARKER-POS-CONTEXT
        $acct = $adapter->account($tok['access_token'], $ctx);

        $conn = TenantPosConnection::firstOrNew(['tenant_id' => $pending['tenant_id']]);
        $conn->fill([
            'adapter_code'          => $code,
            'status'                => 'connected',
            'access_token'          => $tok['access_token'],
            'refresh_token'         => $tok['refresh_token'],
            'token_expires_at'      => $tok['expires_in'] ? now()->addSeconds($tok['expires_in']) : null,
            'external_account_id'   => $acct['id'],
            'external_account_name' => $acct['name'],
            'context'               => $ctx,
            'connected_by'          => $pending['user_id'],
            'connected_at'          => now(),
            'last_error'            => null,
        ])->save();

        $this->discover($conn);

        return $conn;
    }

    /** Re-read account, locations and payment types; record the outcome on the connection. */
    public function discover(TenantPosConnection $conn): bool
    {
        try {
            $adapter = $this->registry->make($conn->adapter_code);
            $token = $this->accessToken($conn);
            $ctx = (array) ($conn->context ?? []);
            $acct = $adapter->account($token, $ctx);
            $conn->fill([
                'external_account_id'   => $acct['id'],
                'external_account_name' => $acct['name'],
                'locations'             => $adapter->locations($token, $acct['id'], $ctx),
                'payment_types'         => $adapter->paymentTypes($token, $acct['id'], $ctx),
                // MARKER-CONNECTED-POS-PUSH: what a sale can be recorded with
                'setup_options'         => [
                    'registers' => $this->soft(fn () => $adapter->registers($token, $acct['id'], $ctx)),
                    'users'     => $this->soft(fn () => $adapter->users($token, $acct['id'], $ctx)),
                    'taxes'     => $this->soft(fn () => $adapter->taxes($token, $acct['id'], $ctx)),
                ],
                'status'                => 'connected',
                'last_checked_at'       => now(),
                'last_error'            => null,
            ])->save();

            return true;
        } catch (Throwable $e) {
            $conn->fill(['status' => 'error', 'last_checked_at' => now(), 'last_error' => Str::limit($e->getMessage(), 500)])->save();
            JobFailureReporter::report(self::class, 'POS connection check failed', $e, ['adapter' => $conn->adapter_code], $conn->tenant_id);

            return false;
        }
    }

    /** Optional lists (a connection made before a permission existed may not be allowed to read them yet). */
    private function soft(callable $fn): array
    {
        try {
            return (array) $fn();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** A usable access token, refreshed (and a rotated refresh token saved) when close to expiry. */
    public function accessToken(TenantPosConnection $conn): string
    {
        $fresh = fn () => $conn->access_token && (! $conn->token_expires_at || $conn->token_expires_at->gt(now()->addMinute()));
        if ($fresh()) {
            return $conn->access_token;
        }
        if (! $conn->refresh_token) {
            throw new RuntimeException('Access to your Connected POS has expired. Reconnect to fix it.');
        }

        // One refresh at a time per shop: a rotated refresh token used twice would lock the shop out.
        return Cache::lock('pos_refresh:' . $conn->id, 30)->block(20, function () use ($conn, $fresh) {
            $conn->refresh();
            if ($fresh()) {
                return $conn->access_token;
            }
            $tok = $this->registry->make($conn->adapter_code)->refreshToken($conn->refresh_token, (array) ($conn->context ?? []));
            $conn->fill([
                'access_token'     => $tok['access_token'],
                'refresh_token'    => $tok['refresh_token'] ?: $conn->refresh_token,
                'token_expires_at' => $tok['expires_in'] ? now()->addSeconds($tok['expires_in']) : null,
            ])->save();

            return $conn->access_token;
        });
    }

    /** Remove Intake's access. Tokens are deleted; the shop can also revoke from its POS. */
    public function disconnect(Tenant $tenant): void
    {
        TenantPosConnection::where('tenant_id', $tenant->id)->delete();
    }
}
