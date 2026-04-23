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
}
