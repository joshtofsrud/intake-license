<?php

namespace App\Filament\Pages;

use App\Jobs\SyncDistributorCatalogJob;
use App\Models\Tenant;
use App\Services\Distributors\DistributorRegistry;
use App\Services\Distributors\KonaStockFile;
use App\Support\AdminAccess;
use App\Support\BrandDirect;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\WithFileUploads;

/**
 * MARKER-BRAND-DIRECT: master admin for brands sold direct to their dealers
 * (Kona first). Where the brand's data comes from, which shops may see it,
 * a check of a dealer's stock file against the catalog, the clean-up rules,
 * and a preview of the catalog as shops will get it.
 */
class BrandSuppliers extends Page
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    use WithFileUploads;

    protected static string $adminArea = 'catalog';

    protected static ?string $navigationIcon  = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Brand suppliers';
    protected static ?string $navigationGroup = 'Distribution';
    protected static ?int    $navigationSort  = 12;
    protected static ?string $title           = 'Brand suppliers';
    protected static ?string $slug            = 'brand-suppliers';
    protected static string  $view            = 'filament.pages.brand-suppliers';

    public string $code = '';
    public string $shopSearch = '';
    public string $previewSearch = '';
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $stockFile = null;
    public ?array $check = null;
    public string $checkName = '';

    public function mount(): void
    {
        $codes = $this->codes();
        $this->code = $codes[0] ?? '';
    }

    public function canEdit(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'catalog') === 'full';
    }

    /** @return string[] brand-direct codes the registry supports */
    public function codes(): array
    {
        $supported = app(DistributorRegistry::class)->supported();
        return array_values(array_filter(BrandDirect::codes(), fn ($c) => in_array($c, $supported, true)));
    }

    public function label(?string $code = null): string
    {
        return app(DistributorRegistry::class)->label($code ?: $this->code);
    }

    public function updatedCode(): void
    {
        $this->check = null;
        $this->checkName = '';
    }

    // ------------------------------------------------------------ catalog

    /** Where the catalog read stands, for the Sources row and its live line. */
    public function catalog(): array
    {
        $code = $this->code;
        $state = DB::table('distributor_sync_state')
            ->where('distributor_code', $code)->where('source_ref', 'catalog')->first();
        $rows = DB::table('platform_distributor_catalogs')->where('distributor_code', $code)->whereNull('deleted_at');
        $count = (clone $rows)->count();
        $products = (clone $rows)->distinct()->count('distributor_product_no');

        $queuedAt = Cache::get('brand_suppliers.queued.' . $code);
        $class = app(DistributorRegistry::class)->adapterClass($code);
        $prog = $class && defined($class . '::PROGRESS_KEY') ? Cache::get($class::PROGRESS_KEY) : null;

        $lastRun = $state?->last_run_at ? \Carbon\Carbon::parse($state->last_run_at, 'UTC') : null;
        $running = ($state?->last_status === 'running' && $lastRun && $lastRun->gt(now()->subMinutes(15)))
            || ($prog && ($prog['phase'] ?? '') !== 'done' && ($prog['at'] ?? 0) > now()->subMinutes(5)->timestamp);
        $queued = ! $running && $queuedAt && (! $lastRun || $lastRun->timestamp < $queuedAt);

        // what the read is doing right now
        $line = null;
        $pct = null;
        if ($running) {
            $phase = $prog['phase'] ?? '';
            if ($state?->last_status === 'running' && ($phase === 'done' || $phase === '' || ($prog['at'] ?? 0) < ($lastRun?->timestamp ?? 0) - 5)) {
                $line = 'Writing to the catalog · ' . number_format((int) $state->last_count) . ' part numbers so far';
                $pct = $count > 0 ? min(99, (int) round(100 * $state->last_count / max($count, 1))) : null;
            } elseif ($phase === 'feed') {
                $line = 'Reading ' . $this->label() . '\'s product list · page ' . (int) $prog['done'];
                $pct = 3;
            } elseif ($phase === 'pages') {
                $line = 'Reading spec tables · ' . number_format((int) $prog['done']) . ' of ' . number_format((int) $prog['total']) . ' bikes'
                    . ((int) ($prog['read'] ?? 0) > 0 ? ' · ' . number_format((int) $prog['read']) . ' pages changed and were read' : ' · unchanged pages are skipped');
                $pct = $prog['total'] ? (int) round(5 + 70 * $prog['done'] / max(1, $prog['total'])) : 5;
            } else {
                $line = 'Working…';
            }
        }

        $errors = [];
        if ($state?->last_error) {
            $e = json_decode((string) $state->last_error, true);
            $errors = is_array($e) ? $e : [(string) $state->last_error];
        }

        return [
            'state'    => $running ? 'running' : ($queued ? 'queued' : ($state?->last_status ?: 'never')),
            'line'     => $line,
            'pct'      => $pct,
            'started'  => $lastRun,
            'synced'   => $state?->last_synced_at ? \Carbon\Carbon::parse($state->last_synced_at, 'UTC') : null,
            'last'     => (int) ($state?->last_count ?? 0),
            'count'    => $count,
            'products' => $products,
            'errors'   => array_slice($errors, 0, 5),
            'live'     => $running || $queued,
        ];
    }

    public function readCatalog(): void
    {
        abort_unless($this->canEdit(), 403);
        if ($this->code === '') {
            return;
        }
        Cache::put('brand_suppliers.queued.' . $this->code, now()->timestamp, now()->addHour());
        SyncDistributorCatalogJob::dispatch($this->code);
        Notification::make()->success()->title('Reading ' . $this->label() . '\'s catalog')
            ->body('Progress shows in the Sources row.')->send();
    }

    // -------------------------------------------------------------- shops

    /** Shops with distributor import (the add-on), with their switch. */
    public function shops(): array
    {
        $on = BrandDirect::shopsFor($this->code);
        $q = mb_strtolower(trim($this->shopSearch));
        return Tenant::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Tenant $t) => (bool) $t->distributor_sync_enabled)
            ->filter(fn (Tenant $t) => $q === '' || str_contains(mb_strtolower((string) $t->name), $q))
            ->map(fn (Tenant $t) => [
                'id' => (string) $t->id, 'name' => (string) $t->name,
                'on' => isset($on[(string) $t->id]),
                'since' => isset($on[(string) $t->id]) ? \Carbon\Carbon::parse($on[(string) $t->id])->format('M j, Y') : null,
            ])
            ->sortByDesc('on')->values()->all();
    }

    public function onCount(): int
    {
        return count(BrandDirect::shopsFor($this->code));
    }

    public function toggleShop(string $tenantId): void
    {
        abort_unless($this->canEdit(), 403);
        $t = Tenant::find($tenantId);
        if (! $t || ! $t->distributor_sync_enabled) {
            return;
        }
        $on = ! isset(BrandDirect::shopsFor($this->code)[$tenantId]);
        $by = Auth::guard('web')->user()?->email;
        BrandDirect::set($tenantId, $this->code, $on, $by);

        $ready = BrandDirect::shopSideReady($this->code);
        Notification::make()->success()
            ->title($this->label() . ($on ? ' switched on for ' : ' switched off for ') . $t->name)
            ->body($on
                ? ($ready ? 'They see it on their Distributors page now.' : 'They will see it once the shop side of ' . $this->label() . ' ships.')
                : 'Their box and new imports are hidden. Items they already have stay.')
            ->send();
    }

    public function shopSideReady(): bool
    {
        return BrandDirect::shopSideReady($this->code);
    }

    // --------------------------------------------------- stock file check

    public function updatedStockFile(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(['stockFile' => ['file', 'max:20480', 'mimes:xlsx,csv,txt']]);
        $name = (string) $this->stockFile->getClientOriginalName();
        try {
            $read = KonaStockFile::read($this->stockFile->getRealPath(), $name);
        } catch (\Throwable $e) {
            $this->check = ['error' => 'Could not read that file: ' . $e->getMessage()];
            return;
        }
        if ($read['missing']) {
            $this->check = ['error' => 'That file has no ' . implode(', ', array_map(fn ($m) => match ($m) {
                'id' => 'Inventory ID', 'cost' => 'Base Price', 'qty' => 'Qty. Hard Available', default => $m,
            }, $read['missing'])) . ' column. Kona\'s "Item Valuation Data" export has them.'];
            return;
        }
        $this->checkName = $name;
        $this->check = KonaStockFile::check($read['rows'], $this->code);
        $this->stockFile = null;
    }

    public function clearCheck(): void
    {
        $this->check = null;
        $this->checkName = '';
    }

    /** The rows not in the catalog, as a CSV. */
    public function downloadMissing()
    {
        $rows = (array) ($this->check['missing'] ?? []);
        $name = strtolower($this->code) . '-not-in-catalog.csv';
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Inventory ID', 'Description', 'Year', 'Qty', 'Base Price', 'Retail Price', 'UPC'], ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($out, [$r['id'], $r['description'], $r['year'], $r['qty'], $r['cost'], $r['retail'], $r['upc']], ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    // ------------------------------------------------------- rules, preview

    public function rules(): array
    {
        return DB::table('distributor_field_maps')->where('distributor_code', $this->code)
            ->orderBy('sort_order')->orderBy('canonical_field')
            ->get(['id', 'canonical_field', 'source_path', 'transform', 'transform_args', 'cleanup', 'notes', 'is_active'])
            ->map(function ($r) {
                $args = json_decode((string) $r->transform_args, true) ?: [];
                $clean = json_decode((string) $r->cleanup, true) ?: [];
                $how = match ($r->transform) {
                    'fixed' => 'always "' . ($args['value'] ?? '') . '"',
                    'json_passthrough' => 'kept whole',
                    'bool' => 'yes / no',
                    default => isset($args['cast']) ? 'as ' . $args['cast'] : 'copied',
                };
                $cl = [];
                foreach ((array) ($clean['replace'] ?? []) as $rep) {
                    $cl[] = 'replace ' . ($rep['pattern'] ?? '') . ' → ' . (($rep['with'] ?? '') === '' ? 'nothing' : $rep['with']);
                }
                foreach ((array) ($clean['value_map'] ?? []) as $from => $to) {
                    $cl[] = '"' . $from . '" → "' . $to . '"';
                }
                if (! empty($clean['blank_values'])) {
                    $cl[] = 'blank when ' . implode(', ', array_map(fn ($b) => '"' . $b . '"', $clean['blank_values']));
                }
                if (! empty($clean['label_case'])) {
                    $cl[] = 'labels: ' . str_replace('_', ' ', (string) $clean['label_case']);
                }
                if (! empty($clean['value_case'])) {
                    $cl[] = 'values: ' . str_replace('_', ' ', (string) $clean['value_case']);
                }
                return [
                    'id' => $r->id, 'field' => $r->canonical_field, 'from' => $r->source_path ?: '—',
                    'how' => $how, 'cleanup' => $cl, 'notes' => $r->notes, 'active' => (bool) $r->is_active,
                    'url' => \App\Filament\Resources\DistributorFieldMapResource::getUrl('edit', ['record' => $r->id]),
                ];
            })->all();
    }

    public function preview(): array
    {
        $q = trim($this->previewSearch);
        return DB::table('platform_distributor_catalogs')
            ->where('distributor_code', $this->code)->whereNull('deleted_at')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('distributor_variant_no', 'like', '%' . $q . '%')
                ->orWhere('display_name', 'like', '%' . $q . '%')->orWhere('name', 'like', '%' . $q . '%')))
            ->orderBy('name')->orderBy('distributor_variant_no')->limit(25)
            ->get(['distributor_variant_no', 'display_name', 'name', 'manufacturer', 'size', 'color', 'category_path', 'msrp_cents', 'images', 'attributes', 'is_sellable', 'last_synced_at'])
            ->map(function ($r) {
                $attrs = json_decode((string) $r->attributes, true) ?: [];
                $year = null;
                foreach ($attrs as $a) {
                    if (mb_strtolower((string) ($a['Name'] ?? '')) === 'model year') {
                        $year = $a['Value'] ?? null;
                    }
                }
                $imgs = json_decode((string) $r->images, true) ?: [];
                return [
                    'id' => $r->distributor_variant_no, 'title' => $r->display_name ?: $r->name, 'brand' => $r->manufacturer,
                    'size' => $r->size, 'color' => $r->color, 'category' => $r->category_path,
                    'msrp' => $r->msrp_cents !== null ? '$' . number_format($r->msrp_cents / 100, 2) : '—',
                    'images' => count($imgs), 'thumb' => is_string($imgs[0] ?? null) ? $imgs[0] : null,
                    'specs' => count($attrs), 'year' => $year, 'sellable' => (bool) $r->is_sellable,
                ];
            })->all();
    }

    public function fieldMapsUrl(): string
    {
        return \App\Filament\Resources\DistributorFieldMapResource::getUrl('index');
    }
}
