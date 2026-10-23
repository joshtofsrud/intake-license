<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Services\Distributors\BrandControl;
use App\Services\Distributors\DistributorRegistry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Master-admin control of which distributor brands one SHOP is offered.
 *
 * Only shops that have the distributor add-on appear: the add-on is what gives
 * a shop distributor import at all. For each brand an admin chooses:
 *
 *   Shop decides   no admin row; the shop's own Brands tab governs it
 *   Locked on      the shop is offered it and cannot switch it off
 *   Locked off     the shop is not offered it and cannot switch it on
 *
 * A lock only decides what NEW items can be imported. It never removes,
 * hides or deactivates an item the shop already carries.
 */
class TenantBrandAccess extends Page
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'catalog';

    protected static ?string $navigationIcon  = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationLabel = 'Shop brand access';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 55;
    protected static ?string $title = 'Shop brand access';

    protected static string $view = 'filament.pages.tenant-brand-access';

    public ?string $tenantId = null;
    public string $code = '';
    public string $find = '';

    /**
     * h(brand_key) => shop | on | off, what the admin has chosen on screen.
     * Keyed by a hash because brand names carry spaces and dots, and a dot in a
     * Livewire property path is read as nesting.
     */
    public array $choices = [];

    public static function h(string $brandKey): string
    {
        return substr(md5($brandKey), 0, 12);
    }

    public function mount(): void
    {
        $codes = array_keys($this->distributorOptions);
        $this->code = $codes[0] ?? '';
    }

    /** @return array<string,string> tenant id => name, add-on shops only */
    public function getTenantOptionsProperty(): array
    {
        return Tenant::query()->orderBy('name')->get(['id', 'name'])
            ->filter(fn (Tenant $t) => (bool) $t->distributor_sync_enabled)
            ->mapWithKeys(fn (Tenant $t) => [(string) $t->id => (string) $t->name])
            ->all();
    }

    /** @return array<string,string> */
    public function getDistributorOptionsProperty(): array
    {
        $registry = app(DistributorRegistry::class);
        $out = [];
        foreach ($registry->supported() as $c) {
            $out[$c] = $registry->label($c);
        }
        return $out;
    }

    public function updatedTenantId(): void { $this->loadChoices(); }
    public function updatedCode(): void     { $this->loadChoices(); }

    /** The brands on screen, with the shop's current state for each. */
    public function getBrandsProperty(): array
    {
        if (! $this->tenantId || $this->code === '') {
            return [];
        }
        if (! array_key_exists($this->tenantId, $this->tenantOptions)) {
            return [];   // not an add-on shop: nothing to control
        }

        $brands = app(BrandControl::class)->brandsForTenant($this->tenantId, $this->code);
        $q = mb_strtolower(trim($this->find));

        return $q === '' ? $brands
            : array_values(array_filter($brands, fn ($b) => str_contains(mb_strtolower($b['name']), $q)));
    }

    public function loadChoices(): void
    {
        $this->choices = [];
        if (! $this->tenantId || $this->code === '') {
            return;
        }
        $rows = app(BrandControl::class)->tenantRows($this->tenantId, $this->code);
        foreach ($rows as $key => $row) {
            if ($row->set_by === 'admin') {
                $this->choices[self::h((string) $key)] = $row->enabled ? 'on' : 'off';
            }
        }
    }

    public function save(): void
    {
        abort_unless($this->tenantId && array_key_exists($this->tenantId, $this->tenantOptions), 403);

        $bc   = app(BrandControl::class);
        $who  = (string) Auth::guard('web')->id();
        $rows = $bc->tenantRows($this->tenantId, $this->code);
        $changed = 0;

        foreach ($bc->brandsForTenant($this->tenantId, $this->code) as $b) {
            $choice = $this->choices[self::h($b['key'])] ?? 'shop';
            $row    = $rows[$b['key']] ?? null;
            $current = ($row && $row->set_by === 'admin') ? ($row->enabled ? 'on' : 'off') : 'shop';

            if ($choice === $current) {
                continue;
            }
            if ($choice === 'shop') {
                // hand it back: drop the admin row, keep nothing behind
                $bc->releaseTenant($this->tenantId, $this->code, $b['name']);
            } else {
                $bc->setTenant($this->tenantId, $this->code, $b['name'], $choice === 'on', 'admin', $who);
            }
            $changed++;
        }

        Notification::make()->success()
            ->title($changed === 0 ? 'No changes to save' : "Saved {$changed} brand" . ($changed === 1 ? '' : 's'))
            ->body($changed === 0 ? null : 'Items the shop already carries are untouched.')
            ->send();

        $this->loadChoices();
    }
}
