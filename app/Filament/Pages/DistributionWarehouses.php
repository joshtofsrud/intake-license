<?php

namespace App\Filament\Pages;

use App\Models\DistributionWarehouse;
use App\Models\Tenant;
use App\Services\Distributors\WarehouseRegistry;
use App\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-DISTRIBUTION-WAREHOUSES: which supplier warehouses shops can see, and which
 * can ship straight to a shop's customers. Master switch, per-warehouse flags, and
 * per-shop exceptions. Warehouses appear on their own when a feed first reports them.
 */
class DistributionWarehouses extends Page
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'catalog';

    protected static ?string $navigationIcon  = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = 'Distribution warehouses';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 66;
    protected static ?string $title           = 'Distribution warehouses';
    protected static ?string $slug            = 'distribution-warehouses';
    protected static string  $view            = 'filament.pages.distribution-warehouses';

    public array $set = [];       // feature_on, shop_default, stale_hours
    public array $wh = [];        // warehouse id => [shown, drop, zip, days]
    public array $ov = [];        // tenant id => follow|allow|block
    public array $units = [];     // MARKER-SHIPPING-RATES: distributor code => [weight, length] its feed uses
    public string $dist = '';
    public string $status = '';
    public string $search = '';
    public string $shopSearch = '';

    public function mount(): void
    {
        $this->set = WarehouseRegistry::settings();
        $this->set['shop_default'] = $this->set['shop_default'] ? '1' : '0'; // a select holds strings
        foreach ($this->warehouses() as $w) {
            $this->wh[$w->id] = ['shown' => (bool) $w->shown_to_shops, 'drop' => (bool) $w->dropship_enabled,
                'zip' => (string) ($w->ship_from_zip ?? ''), 'days' => (int) $w->handling_days];
        }
        $plat = (array) (\App\Models\PlatformSettings::current()->distribution_settings ?? []);
        foreach ($this->unitCodes() as $code) {
            $this->units[$code] = \App\Services\Shipping\ItemShipSpec::unitsFor($code, $plat);
        }
        foreach (Tenant::query()->where('is_active', true)->get(['id', 'settings']) as $t) {
            $this->ov[$t->id] = (string) ($t->settings['distribution']['override'] ?? 'follow');
        }
    }

    /** Every distributor whose items a shop could ship: from the catalog, plus any with warehouses. */
    public function unitCodes(): array
    {
        $codes = \App\Models\PlatformDistributorCatalog::query()->distinct()->pluck('distributor_code')->all();
        if (WarehouseRegistry::ready()) {
            $codes = array_merge($codes, DistributionWarehouse::query()->distinct()->pluck('distributor_code')->all());
        }
        $codes = array_values(array_unique(array_map('strtolower', array_filter($codes))));
        sort($codes);
        return $codes;
    }

    public function canEdit(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'catalog') === 'full';
    }

    public function warehouses()
    {
        if (! WarehouseRegistry::ready()) return collect();
        return DistributionWarehouse::query()->orderBy('distributor_code')->orderBy('name')->get();
    }

    /** Warehouses after the filters, grouped by distributor. */
    public function grouped(): array
    {
        $q = mb_strtolower(trim($this->search));
        return $this->warehouses()->filter(function ($w) use ($q) {
            if ($this->dist !== '' && $w->distributor_code !== $this->dist) return false;
            $st = $this->wh[$w->id] ?? ['shown' => false, 'drop' => false];
            if ($this->status === 'drop' && ! $st['drop']) return false;
            if ($this->status === 'shown' && ! ($st['shown'] && ! $st['drop'])) return false;
            if ($this->status === 'hidden' && $st['shown']) return false;
            if ($q !== '' && ! str_contains(mb_strtolower($w->name . ' ' . $w->code), $q)) return false;
            return true;
        })->groupBy('distributor_code')->all();
    }

    public function distributors(): array
    {
        return $this->warehouses()->pluck('distributor_code')->unique()->values()->all();
    }

    public function totalShops(): int
    {
        return Tenant::query()->where('is_active', true)->count();
    }

    /** warehouse id => number of shops that ticked it. */
    public function shopsUsing(): array
    {
        return DB::table('tenant_warehouse_selections')->where('enabled', true)
            ->select('warehouse_id', DB::raw('COUNT(DISTINCT tenant_id) AS n'))->groupBy('warehouse_id')
            ->pluck('n', 'warehouse_id')->map(fn ($n) => (int) $n)->all();
    }

    /** tenant id => names of the warehouses they ticked. */
    public function shopChoices(): array
    {
        $names = $this->warehouses()->pluck('name', 'id');
        $out = [];
        foreach (DB::table('tenant_warehouse_selections')->where('enabled', true)->orderBy('priority')->get(['tenant_id', 'warehouse_id']) as $r) {
            if (isset($names[$r->warehouse_id])) $out[$r->tenant_id][] = $names[$r->warehouse_id];
        }
        return $out;
    }

    public function shops()
    {
        $q = Tenant::query()->where('is_active', true)->orderBy('name');
        if (trim($this->shopSearch) !== '') {
            $q->where('name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($this->shopSearch)) . '%');
        }
        return $q->limit(200)->get(['id', 'name', 'settings']);
    }

    /** Dropship can't be on for a hidden warehouse. */
    public function updatedWh($value, $name): void
    {
        [$id, $key] = array_pad(explode('.', (string) $name, 2), 2, null);
        if ($key === 'shown' && ! $value && isset($this->wh[$id])) $this->wh[$id]['drop'] = false;
        if ($key === 'drop' && $value && isset($this->wh[$id])) $this->wh[$id]['shown'] = true;
    }

    public function scan(): void
    {
        abort_unless($this->canEdit(), 403);
        $n = WarehouseRegistry::scan();
        $this->mount();
        Notification::make()->title($n ? "{$n} new warehouse" . ($n === 1 ? '' : 's') . ' found' : 'No new warehouses')
            ->body($n ? 'They start hidden and off until you turn them on.' : 'Everything in the feeds is already listed.')->success()->send();
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate([
            'set.stale_hours'  => ['required', 'integer', 'min:1', 'max:168'],
            'wh.*.zip'         => ['nullable', 'string', 'max:10'],
            'wh.*.days'        => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);
        $this->validate([
            'units.*.weight' => ['required', 'in:' . implode(',', \App\Services\Shipping\Units::weightUnits())],
            'units.*.length' => ['required', 'in:' . implode(',', \App\Services\Shipping\Units::lengthUnits())],
        ]);
        WarehouseRegistry::saveSettings(array_merge($this->set, ['shop_default' => (string) ($this->set['shop_default'] ?? '0') === '1']));

        $ps = \App\Models\PlatformSettings::current();
        $ds = (array) ($ps->distribution_settings ?? []);
        $ds['units'] = collect($this->units)->map(fn ($u) => ['weight' => $u['weight'], 'length' => $u['length']])->all();
        $ps->distribution_settings = $ds;
        $ps->save();
        \App\Models\PlatformSettings::forget();

        foreach ($this->warehouses() as $w) {
            $r = $this->wh[$w->id] ?? null;
            if (! $r) continue;
            $shown = (bool) $r['shown'];
            $w->forceFill([
                'shown_to_shops' => $shown, 'dropship_enabled' => $shown && (bool) $r['drop'],
                'ship_from_zip' => trim((string) $r['zip']) ?: null, 'handling_days' => max(0, (int) $r['days']),
            ])->save();
        }

        foreach (Tenant::query()->where('is_active', true)->get() as $t) {
            $v = in_array($this->ov[$t->id] ?? 'follow', ['follow', 'allow', 'block'], true) ? $this->ov[$t->id] : 'follow';
            $settings = (array) ($t->settings ?? []);
            if (($settings['distribution']['override'] ?? 'follow') === $v) continue;
            $settings['distribution']['override'] = $v;
            $t->settings = $settings;
            $t->save();
        }

        Notification::make()->title('Saved')->body($this->set['feature_on'] ? 'Shops see the changes straight away.' : 'The feature is off, so shops see none of this.')->success()->send();
    }
}
