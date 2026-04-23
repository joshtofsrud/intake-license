<?php

namespace App\Services\Demo\Industries;

use App\Models\Tenant;

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
     * Page content for home/about/contact. Keyed by page slug.
     * Each page returns:
     *   [
     *     'meta_title' => string|null,
     *     'meta_description' => string|null,
     *     'sections' => [
     *        ['type' => 'hero', 'content' => [...], 'padding' => ..., 'bg_color' => ...],
     *        ...
     *     ],
     *   ]
     */
    public function pageContent(Tenant $tenant): array;
}
