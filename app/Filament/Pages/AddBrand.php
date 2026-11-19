<?php

namespace App\Filament\Pages;

use App\Jobs\ProbeBrandSiteJob;
use App\Jobs\SyncDistributorCatalogJob;
use App\Models\PlatformDistributorConnection;
use App\Services\Distributors\BrandSiteProbe;
use App\Services\Distributors\DistributorRegistry;
use App\Support\AdminAccess;
use App\Support\BrandDirect;
use App\Support\BrandFieldMapTemplate;
use App\Support\BrandKinds;
use App\Support\BrandSources;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-BRAND-BUILDER: Add a brand. Master admin gives a brand's website;
 * Intake works out the store type, samples a few products and shows what it
 * can read, then creates the brand: off for every shop, with a starting field
 * map, read by the general store reader. Nothing is saved before Create.
 */
class AddBrand extends Page
{
    protected static string $adminArea = 'catalog';

    protected static ?string $title = 'Add a brand';
    protected static ?string $slug = 'brand-suppliers/add';
    protected static string $view = 'filament.pages.add-brand';
    protected static bool $shouldRegisterNavigation = false;

    public int $step = 1;
    public string $url = '';
    public string $name = '';
    public string $token = '';
    public string $sizeOption = '';
    public string $colorOption = '';
    public string $kindsText = '';
    public bool $startRead = true;

    public function canEdit(): bool
    {
        return AdminAccess::level(Auth::guard('web')->user(), 'catalog') === 'full';
    }

    public function mount(): void
    {
        abort_unless($this->canEdit(), 403);
    }

    /** What the look at the site has found so far, or null before it starts. */
    public function probe(): ?array
    {
        return $this->token !== '' ? BrandSiteProbe::state($this->token) : null;
    }

    public function look(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->validate(['url' => ['required', 'string', 'max:255']], ['url.required' => 'Enter the brand\'s website.']);
        if (BrandSiteProbe::normalize($this->url) === '') {
            $this->addError('url', 'That isn\'t a web address.');
            return;
        }
        if ($this->token !== '') {
            BrandSiteProbe::cancel($this->token); // "Look again" replaces the earlier check, never stacks behind it
        }
        $this->token = (string) Str::uuid();
        Cache::put(BrandSiteProbe::key($this->token), ['status' => 'queued', 'steps' => [], 'started' => now()->timestamp], now()->addHours(6));
        ProbeBrandSiteJob::dispatch($this->token, $this->url);
        $this->step = 1;
    }

    /** Called while the look runs: fills the name and option names once they're known. */
    public function refreshProbe(): void
    {
        $p = $this->probe();
        if (! $p || ($p['status'] ?? '') !== 'done') {
            return;
        }
        if ($this->name === '' && ! empty($p['name'])) {
            $this->name = (string) $p['name'];
        }
        $opts = array_keys((array) ($p['options'] ?? []));
        if ($this->sizeOption === '') {
            $this->sizeOption = (string) (collect($opts)->first(fn ($o) => preg_match('/\b(size|frame)\b/i', $o)) ?? '');
        }
        if ($this->colorOption === '') {
            $this->colorOption = (string) (collect($opts)->first(fn ($o) => preg_match('/\b(colou?r|finish|paint)\b/i', $o)) ?? '');
        }
    }

    public function goTo(int $step): void
    {
        $p = $this->probe();
        $ready = $p && ($p['status'] ?? '') === 'done';
        if ($step > 1 && ! $ready) {
            return;
        }
        $this->refreshProbe();
        if ($step === 4 && trim($this->name) === '') {
            $this->addError('name', 'Give the brand a name.');
            $step = 1;
        }
        $this->step = max(1, min(4, $step));
    }

    public function addKind(string $tag): void
    {
        $this->kindsText = rtrim($this->kindsText) . ($this->kindsText !== '' ? "\n" : '') . $tag;
    }

    /** The code the brand will get: its name in capitals, made unique. */
    public function suggestedCode(): string
    {
        $base = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper(Str::ascii($this->name))) ?: 'BRAND', 0, 16);
        $reg = app(DistributorRegistry::class);
        $code = $base;
        for ($i = 2; $reg->taken($code) || DB::table('platform_distributor_connections')->where('distributor_code', $code)->exists(); $i++) {
            $code = substr($base, 0, 14) . $i;
        }
        return $code;
    }

    public function create()
    {
        abort_unless($this->canEdit(), 403);
        $p = $this->probe();
        if (! $p || ($p['status'] ?? '') !== 'done' || ! in_array($p['platform'] ?? '', ['shopify', 'woocommerce'], true)) {
            Notification::make()->danger()->title('Look at the site first')->send();
            return null;
        }
        $this->validate(['name' => ['required', 'string', 'max:120']]);
        $base = (string) $p['base'];
        $existing = DB::table('brand_sources')->where('base_url', $base)->value('label');
        if ($existing) {
            Notification::make()->warning()->title($existing . ' already reads this site')->body('Open it on Brand suppliers instead.')->send();
            return null;
        }
        $code = $this->suggestedCode();
        $label = trim($this->name);

        DB::transaction(function () use ($code, $label, $base, $p) {
            DB::table('brand_sources')->insert([
                'code' => $code, 'label' => $label, 'base_url' => $base, 'platform' => $p['platform'],
                'settings' => json_encode(array_filter([
                    'size_option' => $this->sizeOption, 'color_option' => $this->colorOption, 'estimate' => $p['total'] ?? null,
                    'shopify_route' => $p['route'] ?? null,
                ], fn ($v) => $v !== '' && $v !== null)),
                'created_by' => Auth::guard('web')->user()?->email, 'created_at' => now(), 'updated_at' => now(),
            ]);
            PlatformDistributorConnection::updateOrCreate(['distributor_code' => $code], ['brand_direct' => true, 'is_active' => true]);
            BrandFieldMapTemplate::seed($code, $label);
        });
        BrandSources::forget();
        BrandDirect::forget();
        BrandKinds::set($code, preg_split('/\r?\n/', $this->kindsText));

        if ($this->startRead) {
            Cache::put('brand_suppliers.queued.' . $code, now()->timestamp, now()->addHour());
            SyncDistributorCatalogJob::dispatch($code);
        }
        Notification::make()->success()->title($label . ' added')
            ->body($this->startRead ? 'Its first read has started; progress shows on its Catalog read tab. It is off for every shop.' : 'It is off for every shop. Press Read catalog when you are ready.')->send();
        return redirect(BrandSuppliers::getUrl(['code' => $code]));
    }
}
