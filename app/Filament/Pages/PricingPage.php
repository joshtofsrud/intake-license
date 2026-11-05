<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AddonResource;
use App\Models\Addon;
use App\Models\SiteSettings;
use App\Support\PricingCatalog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * MARKER-LIVE-PRICING: what intake.works/pricing will show, built from the
 * same PricingCatalog the public section uses, so the preview cannot disagree
 * with the page. Edits here: the plan one-liners, the "basics" rows, and who
 * gets each add-on. Prices stay where they are dated (Plan prices, Add-ons).
 */
class PricingPage extends Page
{
    use \App\Support\UsesAdminNav;
    use \App\Support\GatedByAdminArea;
    protected static string $adminArea = 'config';

    protected static ?string $navigationIcon  = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Pricing page';
    protected static ?string $navigationGroup = 'Billing';
    protected static ?int    $navigationSort  = 35;
    protected static ?string $title           = 'Pricing page';
    protected static ?string $slug            = 'pricing-page';
    protected static string  $view            = 'filament.pages.pricing-page';

    /** tier => one-liner */
    public array $blurbs = [];

    /** rows of [label, starter, branded, scale, phone] */
    public array $basics = [];

    /** add-on codes whose card line shows on phones (MARKER-PRICING-MOBILE-PRIORITY) */
    public array $mobileAddons = [];

    public string $savedSnapshot = '';

    public ?int $editId = null;

    public array $edit = [];

    /** MARKER-ADDON-SAFETY: set when a status change needs a yes first. */
    public ?string $statusWarning = null;

    public function mount(): void
    {
        $this->blurbs = PricingCatalog::blurbs();
        $this->basics = array_map(fn ($r) => array_values($r), PricingCatalog::basics());
        $this->mobileAddons = PricingCatalog::mobileAddons();
        $this->savedSnapshot = $this->snapshot();
    }

    private function snapshot(): string
    {
        $basics = array_map(fn ($r) => [(string) ($r[0] ?? ''), (string) ($r[1] ?? ''), (string) ($r[2] ?? ''), (string) ($r[3] ?? ''), ! empty($r[4])], $this->basics);
        $mobile = array_values(array_unique(array_map('strval', $this->mobileAddons)));
        sort($mobile);
        return json_encode([$this->blurbs, $basics, $mobile]);
    }

    public function isDirty(): bool
    {
        return $this->snapshot() !== $this->savedSnapshot;
    }

    public function addRow(): void
    {
        $this->basics[] = ['', '', '', '', false];
    }

    public function removeRow(int $i): void
    {
        unset($this->basics[$i]);
        $this->basics = array_values($this->basics);
    }

    public function moveRow(int $i, int $dir): void
    {
        $j = $i + $dir;
        if (! isset($this->basics[$i], $this->basics[$j])) {
            return;
        }
        [$this->basics[$i], $this->basics[$j]] = [$this->basics[$j], $this->basics[$i]];
    }

    public function saveText(): void
    {
        $blurbs = [];
        foreach (PricingCatalog::TIERS as $t) {
            $blurbs[$t] = mb_substr(trim((string) ($this->blurbs[$t] ?? '')), 0, 200);
        }
        $rows = [];
        foreach ($this->basics as $r) {
            $label = mb_substr(trim((string) ($r[0] ?? '')), 0, 80);
            if ($label === '') {
                continue;
            }
            $rows[] = [$label,
                mb_substr(trim((string) ($r[1] ?? '')), 0, 60),
                mb_substr(trim((string) ($r[2] ?? '')), 0, 60),
                mb_substr(trim((string) ($r[3] ?? '')), 0, 60),
                ! empty($r[4]) ? '1' : ''];
        }
        $known  = Addon::query()->pluck('code')->all();
        $mobile = array_values(array_intersect($known, array_map('strval', $this->mobileAddons)));
        SiteSettings::current()->update(['pricing_blurbs' => $blurbs, 'pricing_basics' => $rows, 'pricing_mobile_addons' => $mobile]);

        $this->blurbs = PricingCatalog::blurbs();
        $this->basics = array_map(fn ($r) => array_values($r), PricingCatalog::basics());
        $this->mobileAddons = PricingCatalog::mobileAddons();
        $this->savedSnapshot = $this->snapshot();
        Notification::make()->title('Pricing page updated')->body('intake.works/pricing shows it now.')->success()->send();
    }

    public function discardText(): void
    {
        $this->mount();
    }

    public function openAddon(int $id): void
    {
        $a = Addon::findOrFail($id);
        $this->editId = $a->id;
        $this->statusWarning = null;
        $this->edit = [
            'included' => array_values((array) ($a->included_in_plans ?? [])),
            'min'      => (string) ($a->min_plan_tier ?? ''),
            'visibility' => (string) ($a->visibility ?? Addon::VIS_SELF_SERVE),
            'status'   => (string) $a->status,
        ];
    }

    public function closeAddon(): void
    {
        $this->editId = null;
        $this->edit = [];
        $this->statusWarning = null;
    }

    public function saveAddon(bool $confirmed = false): void
    {
        $a = Addon::findOrFail($this->editId);
        // MARKER-ADDON-SAFETY: closing or retiring takes something away from
        // shops, so it says how many and waits for a yes.
        $next = (string) ($this->edit['status'] ?? $a->status);
        if (! $confirmed && $next !== $a->status && ($w = \App\Support\AddonStatusWarning::for($a, $next))) {
            $this->statusWarning = $w;
            return;
        }
        $this->statusWarning = null;
        $inc = array_values(array_intersect(PricingCatalog::TIERS, (array) ($this->edit['included'] ?? [])));
        $min = in_array($this->edit['min'] ?? '', ['branded', 'scale'], true) ? $this->edit['min'] : null;
        $vis = array_key_exists($this->edit['visibility'] ?? '', Addon::VISIBILITIES) ? $this->edit['visibility'] : $a->visibility;
        $st  = array_key_exists($this->edit['status'] ?? '', Addon::STATUSES) ? $this->edit['status'] : $a->status;

        $a->included_in_plans = $inc ?: null;
        $a->min_plan_tier = $min;
        $a->visibility = $vis;
        $a->status = $st;
        $a->save();

        $this->closeAddon();
        Notification::make()->title($a->name . ' saved')->body('Shops and the pricing page use it now.')->success()->send();
    }

    /** MARKER-PRICING-ALL-ADDONS: the add-on whose shops are listed under it, if any. */
    public ?string $shopsFor = null;

    public function toggleShops(string $code): void
    {
        $this->shopsFor = $this->shopsFor === $code ? null : $code;
    }

    /**
     * MARKER-PRICING-ALL-ADDONS: everything the public table leaves out, so
     * nothing is out of sight here: hidden add-ons no plan includes, closed
     * ones, retired ones. Each with how many shops still run it.
     *
     * @return array<string, array{title:string, note:string, rows:array}>
     */
    public function offPage(): array
    {
        $all = Addon::query()->orderBy('sort_order')->orderBy('name')->get();
        $counts = \Illuminate\Support\Facades\DB::table('tenant_feature_addons')
            ->whereIn('status', ['active', 'canceling', 'failed_payment'])
            ->selectRaw('addon_code, COUNT(*) as n')->groupBy('addon_code')->pluck('n', 'addon_code');
        $plans = \Illuminate\Support\Facades\DB::table('tenants')->whereNull('deleted_at')
            ->selectRaw('plan_tier, COUNT(*) as n')->groupBy('plan_tier')->pluck('n', 'plan_tier');
        $row = function (Addon $a) use ($counts, $plans) {
            $inc = array_values((array) ($a->included_in_plans ?? []));
            return [
                'addon'  => $a,
                'price'  => PricingCatalog::priceText($a),
                'shops'  => (int) ($counts[$a->code] ?? 0),
                'onPlan' => $inc ? (int) collect($inc)->sum(fn ($t) => (int) ($plans[$t] ?? 0)) : 0,
                'plans'  => $inc ? implode(', ', array_map(fn ($t) => PricingCatalog::NAMES[$t] ?? ucfirst($t), $inc)) : '',
            ];
        };
        $hidden = $all->filter(fn ($a) => $a->status === Addon::ACTIVE
            && ($a->visibility ?? Addon::VIS_SELF_SERVE) === Addon::VIS_HIDDEN
            && count((array) ($a->included_in_plans ?? [])) === 0);

        return [
            'hidden'  => ['title' => 'Hidden', 'note' => 'Active, but shops can\'t see them and no plan includes them, so they\'re only on for shops you turned them on for.', 'rows' => $hidden->map($row)->values()->all()],
            'closed'  => ['title' => 'Closed', 'note' => 'No new shops. Shops that have it keep it and are still billed.', 'rows' => $all->where('status', Addon::DEPRECATED)->map($row)->values()->all()],
            'retired' => ['title' => 'Retired', 'note' => 'Off for everyone. A shop listed here still has an activation on record, but it no longer works.', 'rows' => $all->where('status', Addon::RETIRED)->map($row)->values()->all()],
        ];
    }

    /** Shops with an activation of this add-on, newest first. */
    public function shopsRunning(string $code): array
    {
        return \Illuminate\Support\Facades\DB::table('tenant_feature_addons as fa')
            ->join('tenants as t', 't.id', '=', 'fa.tenant_id')
            ->where('fa.addon_code', $code)->whereIn('fa.status', ['active', 'canceling', 'failed_payment'])
            ->orderByDesc('fa.activated_at')
            ->get(['t.id', 't.name', 't.subdomain', 't.plan_tier', 'fa.status', 'fa.source', 'fa.activated_at'])
            ->map(fn ($r) => [
                'name' => $r->name, 'sub' => $r->subdomain, 'plan' => PricingCatalog::NAMES[$r->plan_tier] ?? ucfirst((string) $r->plan_tier),
                'status' => ['active' => 'Active', 'canceling' => 'Canceling', 'failed_payment' => 'Payment failed'][$r->status] ?? $r->status,
                'source' => $r->source, 'since' => $r->activated_at ? \Carbon\Carbon::parse($r->activated_at)->format('M j, Y') : '—',
                'url' => \App\Filament\Resources\TenantResource::getUrl('edit', ['record' => $r->id]),
            ])->all();
    }

    public function addonEditUrl(int $id): string
    {
        return AddonResource::getUrl('edit', ['record' => $id]);
    }

    public function editing(): ?Addon
    {
        return $this->editId ? Addon::find($this->editId) : null;
    }
}
