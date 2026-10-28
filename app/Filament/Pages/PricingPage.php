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

    public function addonEditUrl(int $id): string
    {
        return AddonResource::getUrl('edit', ['record' => $id]);
    }

    public function editing(): ?Addon
    {
        return $this->editId ? Addon::find($this->editId) : null;
    }
}
