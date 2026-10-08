<?php

namespace App\Filament\Pages;

use App\Models\SalesProspect;
use App\Models\SalesSetting;
use App\Support\AdminAccess;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * MARKER-IG-QUEUE: Instagram follows. One prospect at a time: Open loads
 * their profile in a fixed popup window beside this page, and a PERSON taps
 * Follow there. Nothing here follows anyone; Instagram bans automated
 * follows. Opening marks the prospect followed and logs it on the timeline;
 * a daily cap keeps the account under Instagram's radar.
 */
class SalesInstagramFollows extends Page
{
    use \App\Support\UsesAdminNav;
    protected static ?string $navigationIcon  = 'heroicon-o-user-plus';
    protected static ?string $navigationLabel = 'Instagram follows';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?int    $navigationSort  = 9;
    protected static string  $view            = 'filament.pages.sales-instagram';
    protected static ?string $slug            = 'sales-instagram';
    protected static ?string $title           = 'Instagram follows';

    public const CAP_KEY = 'ig_follow_cap';
    public const CAPS = [15, 20, 25, 30];
    // the cap's "day" runs on Pacific time, not the server clock
    public const TZ = 'America/Los_Angeles';

    private static function dayStart()
    {
        return now(self::TZ)->startOfDay()->setTimezone(config('app.timezone'));
    }

    public string $industryId = '';
    public string $state = '';
    public string $stage = '';
    public string $sort = 'score';
    public bool   $withRep = false;
    public int    $cap = 25;

    public static function canAccess(): bool
    {
        return AdminAccess::allows(Auth::guard('web')->user(), 'crm');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $c = (int) (SalesSetting::get(self::CAP_KEY) ?? 25);
        $this->cap = in_array($c, self::CAPS, true) ? $c : 25;
    }

    public function updatedCap($v): void
    {
        $v = (int) $v;
        $this->cap = in_array($v, self::CAPS, true) ? $v : 25;
        SalesSetting::put(self::CAP_KEY, (string) $this->cap);
    }

    /** Prospects with an Instagram link, open, not followed yet. */
    private function base()
    {
        return SalesProspect::query()
            ->whereNotNull('socials->instagram')
            ->where('socials->instagram', '!=', '')
            ->whereNull('ig_followed_at')
            ->whereNotIn('stage', ['won', 'lost']);
    }

    private function filtered()
    {
        return $this->base()
            ->when($this->industryId !== '', fn ($q) => $q->where('channel_id', $this->industryId))
            ->when($this->state !== '', fn ($q) => $q->where('state', $this->state))
            ->when($this->stage !== '', fn ($q) => $q->where('stage', $this->stage))
            ->when($this->withRep, fn ($q) => $q->whereNotNull('sales_rep_id'));
    }

    private function ordered()
    {
        // skipped ones go to the back, oldest skip first
        $q = $this->filtered()->orderByRaw('ig_skipped_at IS NOT NULL')->orderBy('ig_skipped_at');
        return $this->sort === 'new' ? $q->orderByDesc('created_at') : $q->orderByDesc('lead_score')->orderBy('shop');
    }

    public function industries()
    {
        $counts = $this->base()->selectRaw('channel_id, COUNT(*) n')->groupBy('channel_id')->pluck('n', 'channel_id');
        return \App\Models\SalesChannel::query()->whereIn('id', $counts->keys()->filter())->orderBy('name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name, 'n' => (int) ($counts[$c->id] ?? 0)])
            ->sortByDesc('n')->values()->all();
    }

    public function states(): array
    {
        return $this->base()->whereNotNull('state')->distinct()->orderBy('state')->pluck('state')->all();
    }

    public function stats(): array
    {
        $today = SalesProspect::query()->whereNotNull('ig_followed_at')->where('ig_followed_at', '>=', self::dayStart())->count();
        return [
            'today' => $today,
            'left'  => max(0, $this->cap - $today),
            'queue' => (clone $this->filtered())->count(),
            'all'   => SalesProspect::query()->whereNotNull('ig_followed_at')->count(),
        ];
    }

    public function queue()
    {
        return $this->ordered()->with('rep:id,name')->limit(26)->get();
    }

    public function followedToday()
    {
        return SalesProspect::query()->whereNotNull('ig_followed_at')->where('ig_followed_at', '>=', self::dayStart())
            ->orderByDesc('ig_followed_at')->limit(40)->get(['id', 'shop', 'city', 'state', 'ig_followed_at', 'socials']);
    }

    public static function handle(?array $socials): string
    {
        $u = trim((string) ($socials['instagram'] ?? ''));
        $path = trim((string) parse_url(str_contains($u, '://') ? $u : 'https://' . $u, PHP_URL_PATH), '/');
        return explode('/', $path)[0] ?? '';
    }

    public static function profileUrl(?array $socials): string
    {
        $h = self::handle($socials);
        return $h !== '' ? 'https://www.instagram.com/' . rawurlencode($h) . '/' : '';
    }

    /** Called right after the popup opens (the click itself opened it). */
    public function followed(string $id): void
    {
        if ($this->stats()['left'] <= 0) { return; }
        $p = SalesProspect::find($id);
        if (! $p || $p->ig_followed_at) { return; }
        $p->forceFill(['ig_followed_at' => now(), 'ig_skipped_at' => null])->save();
        $p->activities()->create(['type' => 'system', 'body' => 'Followed on Instagram (@' . self::handle($p->socials) . ')', 'occurred_at' => now()]);
    }

    public function skip(string $id): void
    {
        $p = SalesProspect::find($id);
        if ($p) { $p->forceFill(['ig_skipped_at' => now()])->save(); }
    }

    /** The link points at the wrong account: take it off so it can be fixed in the prospect drawer. */
    public function wrongAccount(string $id): void
    {
        $p = SalesProspect::find($id);
        if (! $p) { return; }
        $old = (string) ($p->socials['instagram'] ?? '');
        $s = (array) ($p->socials ?? []);
        unset($s['instagram']);
        $p->forceFill(['socials' => $s ?: null])->save();
        $p->activities()->create(['type' => 'system', 'body' => 'Instagram link removed as the wrong account: ' . $old, 'occurred_at' => now()]);
    }

    public function undo(string $id): void
    {
        $p = SalesProspect::find($id);
        if (! $p || ! $p->ig_followed_at) { return; }
        $p->forceFill(['ig_followed_at' => null])->save();
        $p->activities()->create(['type' => 'system', 'body' => 'Instagram follow undone', 'occurred_at' => now()]);
    }
}
