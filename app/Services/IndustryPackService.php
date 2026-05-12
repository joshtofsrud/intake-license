<?php

namespace App\Services;

class IndustryPackService
{
    public function get(string $slug): ?array
    {
        $packs = config('industry_packs', []);
        return $packs[$slug] ?? null;
    }

    public function all(): array
    {
        return config('industry_packs', []);
    }

    public function byCategory(string $category): array
    {
        return array_filter($this->all(), fn($p) => ($p['category'] ?? '') === $category);
    }
}
