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
 * MARKER-POS-CONTEXT: every call after connecting gets the shop's saved context.
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
     * Trade the authorisation code for tokens. $callback is every query value the POS
     * sent back; anything the adapter must remember per shop goes in 'context'.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int, context?: array}
     */
    public function exchangeCode(string $code, string $redirectUri, array $callback = []): array;

    /**
     * New access token from a refresh token. A POS that rotates refresh tokens returns the new one.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_in: ?int}
     */
    public function refreshToken(string $refreshToken, array $context = []): array;

    /**
     * The POS account the token belongs to.
     *
     * @return array{id: string, name: string}
     */
    public function account(string $accessToken, array $context = []): array;

    /** @return list<array{id: string, name: string}> */
    public function locations(string $accessToken, string $accountId, array $context = []): array;

    /** @return list<array{id: string, name: string}> */
    public function paymentTypes(string $accessToken, string $accountId, array $context = []): array;

    /**
     * MARKER-CONNECTED-POS-CATALOG: one page of products changed since $cursor
     * (null = from the beginning). Cursors are opaque to Intake.
     *
     * Each item: id, parent_id, name, sku, upc, ean, mpn, brand, price_cents (int|null), active (bool).
     *
     * @return array{items: list<array>, cursor: ?string, more: bool}
     */
    public function pullItems(string $accessToken, string $accountId, array $context, ?string $cursor): array;

    /**
     * One page of stock counts changed since $cursor.
     *
     * @return array{rows: list<array{item_id: string, location_id: string, qty: int}>, cursor: ?string, more: bool}
     */
    public function pullStock(string $accessToken, string $accountId, array $context, ?string $cursor): array;

    // ── MARKER-CONNECTED-POS-PUSH: Intake → Connected POS ─────────────

    /** Registers a sale can be recorded on. @return list<array{id: string, name: string}> */
    public function registers(string $accessToken, string $accountId, array $context): array;

    /** Staff a sale can be recorded under. @return list<array{id: string, name: string}> */
    public function users(string $accessToken, string $accountId, array $context): array;

    /** Tax rates a sale line can carry; empty when the POS applies tax itself. @return list<array{id: string, name: string}> */
    public function taxes(string $accessToken, string $accountId, array $context): array;

    /**
     * Record a completed sale (negative quantities and amount = a return).
     *
     * $sale: reference, note, outlet_id, register_id, user_id, payment_type_id, tax_id (nullable),
     *        return_for (external sale id or null), lines: list<{item_id, qty, unit_cents, tax_cents}>,
     *        total_cents.
     *
     * @return string the POS's sale id
     */
    public function createSale(string $accessToken, string $accountId, array $context, array $sale): string;

    /**
     * Create an item. $fields: name, sku, upc, ean, mpn, description, price_cents.
     *
     * @return string the POS's item id
     */
    public function createItem(string $accessToken, string $accountId, array $context, array $fields): string;

    /**
     * MARKER-POS-ITEM-FIELDS: set an item's price. Separate from createItem because a
     * POS may not let a brand-new product be edited for a moment after it is made.
     */
    public function setItemPrice(string $accessToken, string $accountId, array $context, string $itemId, int $priceCents): void;

    /** Upload one image file (the shop's own photo) to an item. */
    public function uploadImage(string $accessToken, string $accountId, array $context, string $itemId, string $filePath, string $fileName): void;

    /** Update shared fields on an item (only the keys given). */
    public function updateItem(string $accessToken, string $accountId, array $context, string $itemId, array $fields): void;

    /** One item as pullItems shapes it, or null when it no longer exists. */
    public function getItem(string $accessToken, string $accountId, array $context, string $itemId): ?array;

    /**
     * MARKER-POS-ITEM-DETAILS: fill in what an item is beyond its codes and price. Every key
     * is optional: brand, category, supplier (names, matched in the POS or created there),
     * supplier_code, cost_cents, weight_oz, track_inventory. Names are resolved by the adapter.
     */
    public function setItemDetails(string $accessToken, string $accountId, array $context, string $itemId, array $details): void;

    /**
     * MARKER-POS-CUSTOMERS: the POS's id for this person, found by email then phone, or
     * created. $c: first_name, last_name, email, phone, address1, address2, city, state,
     * postcode, country. Null when this POS can't attach customers yet.
     */
    public function findOrCreateCustomer(string $accessToken, string $accountId, array $context, array $c): ?string;
}
