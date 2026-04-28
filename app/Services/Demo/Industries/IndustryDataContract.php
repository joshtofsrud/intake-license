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
}
