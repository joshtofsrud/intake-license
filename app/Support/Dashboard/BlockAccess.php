<?php

namespace App\Support\Dashboard;

use App\Models\Tenant\TenantUser;
use App\Support\SectionRegistry;

/**
 * MARKER-DASH-ACCESS: what one person's dashboard may show them.
 *
 * Shops build their own roles in Team & access, so a dashboard can't go by
 * role names. It goes by the areas a role can open (SectionRegistry): a
 * block, a figure or a link only appears for someone whose role can open the
 * area its data comes from. Whatever a shop calls its roles, the dashboard
 * follows them.
 *
 * Each block in BlockRegistry lists its areas under 'access':
 *   ['any' => [...]]  shown if the person can open at least one (the block
 *                     then leaves out the parts they can't)
 *   ['all' => [...]]  shown only if they can open every one
 *
 * No signed-in person (a render outside a request) sees everything.
 */
class BlockAccess
{
    private array $memo = [];

    public function __construct(private readonly ?TenantUser $user) {}

    public static function for(?TenantUser $user): self
    {
        return new self($user);
    }

    /**
     * Can this person open the area (a SectionRegistry key)? The shop has to
     * have the area turned on too (Register needs Retail, and so on), as the
     * sidebar does.
     */
    public function can(string $section): bool
    {
        return $this->memo[$section] ??= $this->shopHas($section) && (! $this->user || $this->user->canAccessSection($section));
    }

    private function shopHas(string $section): bool
    {
        $gate = SectionRegistry::all()[$section]['gate'] ?? null;
        if (! $gate) return true;
        try {
            $tenant = (function_exists('tenant') ? tenant() : null) ?? $this->user?->tenant;

            return $tenant ? (bool) $tenant->{$gate} : true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** Can they open the page this route goes to? Routes no area governs are open to all. */
    public function canRoute(?string $route): bool
    {
        $section = SectionRegistry::sectionForRoute($route);

        return $section === null || $this->can($section);
    }

    /** Whether the block shows for this person at all. */
    public function seesBlock(string $key): bool
    {
        $rule = BlockRegistry::all()[$key]['access'] ?? [];
        if (! empty($rule['all'])) {
            foreach ($rule['all'] as $s) {
                if (! $this->can($s)) return false;
            }
        }
        if (! empty($rule['any'])) {
            foreach ($rule['any'] as $s) {
                if ($this->can($s)) return true;
            }

            return false;
        }

        return true;
    }

    /** Block keys this person doesn't get. */
    public function blocked(): array
    {
        return array_values(array_filter(array_keys(BlockRegistry::all()), fn ($k) => ! $this->seesBlock($k)));
    }

    /**
     * The attention data with only the cards whose list this person can
     * open. Counts are worked out again so a heading never counts things
     * they can't see.
     */
    public function attention(array $attention): array
    {
        $cards = array_values(array_filter((array) ($attention['cards'] ?? []), fn ($c) => $this->canRoute($c['route'] ?? null)));
        $attention['cards'] = $cards;
        $attention['total_items'] = count($cards);

        return $attention;
    }

    /** Shortcut tiles, minus those going to pages this person can't open. */
    public function tiles(array $layout): array
    {
        foreach (['visible', 'hidden'] as $part) {
            $layout[$part] = array_filter((array) ($layout[$part] ?? []), fn ($def) => $this->canRoute($def['route'] ?? null));
        }

        return $layout;
    }

    /** The areas a block's data comes from, as people read them: "Schedule or Reports". */
    public static function describe(string $key): ?string
    {
        $rule = BlockRegistry::all()[$key]['access'] ?? [];
        $labels = fn (array $keys) => array_map(fn ($s) => SectionRegistry::all()[$s]['label'] ?? $s, $keys);
        if (! empty($rule['all'])) return implode(' and ', $labels($rule['all']));
        if (! empty($rule['any'])) return implode(count($rule['any']) > 2 ? ', ' : ' or ', $labels($rule['any']));

        return null;
    }
}
