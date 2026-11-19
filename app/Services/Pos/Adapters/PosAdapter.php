<?php

namespace App\Services\Pos\Adapters;

/**
 * MARKER-POS-CONNECT: the contract every POS adapter implements.
 *
 * A shop keeps its own POS; Intake's storefront, checkout and orders only ever
 * talk to this interface, never to a POS directly. A new POS is a new adapter
 * plus one registry entry. Vendor names, endpoints and credentials live in the
 * adapter's defaults and master admin, never in shared code or UI copy.
 *
 * This first slice covers connecting and discovery. Pull (items, stock,
 * customers) and push (sales, refunds, cancellations) join the contract in
 * the sync patches, each adapter declaring what it supports in capabilities().
 */
interface PosAdapter
{
    /** Stable code stored on connections, e.g. 'lightspeed-r'. */
    public static function code(): string;

    /**
     * Seed values for the master-admin row: label, scopes, endpoints.
     *
     * @return array{label: string, scopes?: string, authorize_url?: string, token_url?: string, api_base?: string}
     */
    public static function defaults(): array;

    /** How a shop connects: 'oauth' (Connect button) or 'api_key' (shop pastes a key). */
    public static function connectStyle(): string;

    /**
     * What this POS's API can do. Intake shows a feature only when its capability is true.
     *
     * @return array<string,bool>
     */
    public static function capabilities(): array;

    /** Where to send the shop owner to approve Intake. */
    public function authorizeUrl(string $state, string $redirectUri): string;

    /**
     * Trade the authorisation code for tokens.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    public function exchangeCode(string $code, string $redirectUri): array;

    /**
     * New access token from a refresh token. A POS that rotates refresh tokens returns the new one.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    public function refreshToken(string $refreshToken): array;

    /**
     * The POS account the token belongs to.
     *
     * @return array{id: string, name: string}
     */
    public function account(string $accessToken): array;

    /** @return list<array{id: string, name: string}> */
    public function locations(string $accessToken, string $accountId): array;

    /** @return list<array{id: string, name: string}> */
    public function paymentTypes(string $accessToken, string $accountId): array;
}
