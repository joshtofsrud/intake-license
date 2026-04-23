<?php

namespace App\Services\Demo\Industries;

/**
 * Contract for industry-specific demo data providers.
 *
 * To add a new industry, create a class in this namespace that implements
 * this contract, then register it in DemoPopulate::INDUSTRY_MAP.
 */
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
}
