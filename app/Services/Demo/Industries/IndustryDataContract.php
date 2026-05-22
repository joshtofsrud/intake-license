<?php

namespace App\Services\Demo\Industries;

interface IndustryDataContract
{
    public function slug(): string;
    public function label(): string;
    public function defaultShopName(): string;
    public function categories(): array;
    public function servicesByCategory(): array;
    public function addons(): array;
    public function receivingMethods(): array;
    public function industryFormFields(): array;
    public function sampleResponses(): array;
    public function firstNamePool(): array;
    public function lastNamePool(): array;

    /**
     * Work-order field presets seeded when a tenant for this industry is created.
     * Return an array of field definitions:
     * [
     *   [
     *     'label' => 'Serial Number',
     *     'field_type' => 'text',
     *     'help_text' => 'Found under the bottom bracket',
     *     'is_required' => false,
     *     'is_identifier' => true,
     *     'is_customer_visible' => true,
     *     'options' => null, // or array of choices for select type
     *   ],
     *   ...
     * ]
     *
     * Exactly one field may have is_identifier=true. Seeder enforces.
     */
    public function workOrderFieldPresets(): array;

    /**
     * Sample values used when the demo seeder populates work-order responses
     * on seeded appointments. Keyed by field label.
     *
     * Values may be:
     *   - array of strings (one picked at random)
     *   - Closure returning a string (called each time)
     */
    /**
     * Additional resources beyond the auto-seeded owner resource.
     * TenantUserObserver creates ONE resource per owner on creation; this
     * method returns the rest. Each entry: name, subtitle, color_hex,
     * max_appointments_per_day (nullable).
     *
     * Empty array = single-resource shop. Demo seeder respects that.
     */
    public function additionalResources(): array;

    public function workOrderSampleValues(): array;

    /**
     * The booking mode this industry's tenants run in. Determines which
     * receiving methods the seeder uses to generate appointment data:
     *   - 'drop_off'   → seeded appointments use the drop-off receiving
     *                    method, no appointment_time set. Calendar shows
     *                    them via the drop-off (capacity bar) view.
     *   - 'time_slots' → seeded appointments use methods with
     *                    ask_for_time=true and get a real time slot.
     *
     * Other receiving methods (e.g. mail-in) stay installed on the tenant
     * for admin use, but aren't picked by the seeder.
     */
    public function bookingMode(): string;

    /**
     * Class templates for industries that run scheduled group classes
     * (yoga, fitness, etc). Empty array = no classes; demo seeder skips
     * the entire class pipeline (templates / sessions / memberships /
     * packs / customer assignments) and leaves classes_enabled=false.
     *
     * Each entry:
     *   name, slug, description, duration_minutes, default_capacity,
     *   price_cents, instructor_index (int — index into additionalResources(),
     *   or null = owner), schedule (array of ['dow' => int, 'time' => 'HH:MM']
     *   pairs — each becomes a recurring weekly session for ~2 weeks).
     */
    public function classTemplates(): array;

    /**
     * Recurring class membership products (monthly subscriptions).
     * Each entry: name, description, type ('unlimited'|'capped'),
     * monthly_limit (int|null — required when type=capped), price_cents.
     *
     * Empty array = no memberships seeded.
     */
    public function membershipProducts(): array;

    /**
     * Class pack products (bundles of class credits).
     * Each entry: name, description, credit_count, expiry_days,
     * price_cents.
     *
     * Empty array = no packs seeded.
     */
    public function packProducts(): array;

    // MARKER-PATCH-112-CONTRACT

    /**
     * Inventory categories to seed. Each entry: name, slug.
     * Items reference categories by slug.
     * Empty array = no categories, items get category_id=null.
     */
    public function inventoryCategories(): array;

    /**
     * Inventory items to seed. Each entry:
     *   sku, name, description (nullable), category_slug (nullable),
     *   shop_cost_cents, shop_sell_price_cents,
     *   stock_count (int — total stock; distributed evenly across locations),
     *   reorder_threshold (int|null — for low-stock dashboard card).
     *
     * Empty array = no inventory seeded.
     */
    public function inventoryItems(): array;

    /**
     * Number of quote-status (payment_status='quote') sales to seed.
     * 0 = skip quote seeding entirely.
     */
    public function quoteCount(): int;

    /**
     * Number of draft-status (payment_status='draft') sales to seed.
     * 0 = skip draft seeding entirely.
     */
    public function draftCount(): int;

    /**
     * Override the classes_enabled flag on the seeded tenant.
     *   - null  = auto-derive from classTemplates() (non-empty = true)
     *   - true  = force on regardless of classTemplates
     *   - false = force off regardless of classTemplates
     *
     * Use 'true' when classes are sold/marketed but no templates exist yet
     * (e.g. salon planning to add classes but hasn't built them).
     */
    public function classesEnabledOverride(): ?bool;
}
