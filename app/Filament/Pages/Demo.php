<?php

namespace App\Filament\Pages;

use App\Models\DemoSetting;
use App\Models\Tenant;
use App\Support\AdminAccess;
use Illuminate\Support\Facades\Auth;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * MARKER-DEMO-ENTRY — the demo's control room.
 *
 * Everything here is reversible and fast. Rebuilding the template is not: it
 * reads live tenant data and takes minutes, so it stays on the CLI.
 */
class Demo extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-play-circle';
    protected static ?string $navigationLabel = 'Demo';
    protected static ?string $navigationGroup = 'Platform';
    protected static ?int    $navigationSort  = 82;
    protected static string  $view            = 'filament.pages.demo';
    protected static ?string $slug            = 'demo';

    public const SLUG = 'demo';

    public string $anchorWeek  = '';
    public string $offlineNote = '';

    public static function canAccess(): bool
    {
        // MARKER-DEMO-FIXES — allows() is ($user, $area). Passing the area
        // alone threw inside the nav render, so every admin page 500'd.
        return AdminAccess::allows(Auth::guard('web')->user(), 'scheduling');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->anchorWeek  = (string) (DemoSetting::get('anchor_week:' . self::SLUG) ?: ($this->manifest()['busiest_week'] ?? ''));
        $this->offlineNote = (string) DemoSetting::get('offline_reason:' . self::SLUG, '');
    }

    // ---- data ----------------------------------------------------

    public function manifest(): array
    {
        $path = 'demo/' . self::SLUG . '/manifest.json';
        if (! Storage::disk('local')->exists($path)) return [];
        return json_decode(Storage::disk('local')->get($path), true) ?: [];
    }

    public function tenant(): ?Tenant
    {
        return Tenant::where('subdomain', self::SLUG)->where('is_demo', true)->first();
    }

    /** Weeks from the manifest, busiest first, with the anchor marked. */
    public function weekOptions(): array
    {
        $weeks = $this->manifest()['weeks'] ?? [];
        arsort($weeks);
        return array_slice($weeks, 0, 12, true);
    }

    public function state(): array
    {
        $m       = $this->manifest();
        $paused  = DemoSetting::get('paused_until:' . self::SLUG);
        $tenant  = $this->tenant();
        return [
            'built_at'    => $m['built_at'] ?? null,
            'rows'        => array_sum($m['row_counts'] ?? []),
            'tables'      => count($m['tables'] ?? []),
            'tenant_id'   => $tenant?->id,
            'live_rows'   => $tenant ? DB::table('tenant_appointments')->where('tenant_id', $tenant->id)->count() : 0,
            'last_reset'  => DemoSetting::get('last_reset_at:' . self::SLUG),
            'shift_days'  => DemoSetting::get('shift_days:' . self::SLUG),
            // MARKER-DEMO-COUNTS — raw hits since launch: every entry, including
            // repeats and crawlers, and the only record of entries from before
            // the funnel table worked. Kept as-is; people are counted below.
            'entries'     => (int) DemoSetting::get('entries:' . self::SLUG, '0'),
            'people'      => $this->distinctVisitors(),
            'bot_entries' => $this->botEntries(),
            'last_entry'  => DemoSetting::get('last_entry_at:' . self::SLUG),
            'offline'     => DemoSetting::get('offline:' . self::SLUG) === '1',
            'paused_until' => ($paused && CarbonImmutable::parse($paused)->isFuture()) ? $paused : null,
            'entry_url'   => url('/demo'),
        ];
    }

    /**
     * MARKER-DEMO-COUNTS — how many DIFFERENT people walked in, ignoring bots.
     * This is the number worth quoting; the raw counter is not.
     */
    private function distinctVisitors(): int
    {
        $platform = \App\Models\Tenant::where('is_platform', true)->first();
        if (! $platform) return 0;

        return (int) DB::table('tenant_funnel_events')
            ->where('tenant_id', $platform->id)
            ->where('event_type', 'demo_entered')
            ->where(function ($w) { $w->whereNull('device')->orWhere('device', '!=', 'bot'); })
            ->distinct()->count('session_id');
    }

    /** Crawlers that followed the link. Each one triggers a full demo login. */
    private function botEntries(): int
    {
        $platform = \App\Models\Tenant::where('is_platform', true)->first();
        if (! $platform) return 0;

        return (int) DB::table('tenant_funnel_events')
            ->where('tenant_id', $platform->id)
            ->where('event_type', 'demo_entered')
            ->where('device', 'bot')
            ->count();
    }

    /** Suppressed sends: proof the kill worked, and a talking point. */
    public function suppressed(): array
    {
        $tenant = $this->tenant();
        if (! $tenant) return [];
        return DB::table('tenant_messages')
            ->join('tenant_threads', 'tenant_messages.thread_id', '=', 'tenant_threads.id')
            ->where('tenant_threads.tenant_id', $tenant->id)
            ->where('tenant_messages.meta', 'like', '%demo_suppressed%')
            ->orderByDesc('tenant_messages.created_at')
            ->limit(10)
            ->get(['tenant_messages.body', 'tenant_messages.channel', 'tenant_messages.created_at'])
            ->map(fn ($r) => [
                'body'    => \Illuminate\Support\Str::limit((string) $r->body, 90),
                'channel' => strtoupper((string) $r->channel),
                'at'      => $r->created_at,
            ])->all();
    }

    // ---- actions -------------------------------------------------

    public function saveAnchor(): void
    {
        DemoSetting::put('anchor_week:' . self::SLUG, $this->anchorWeek ?: null);
        Notification::make()->title('Anchor week saved')
            ->body('Takes effect at the next reset — or hit Reset now.')->success()->send();
    }

    public function resetNow(): void
    {
        Artisan::call('demo:reset', ['--force' => true]);
        Notification::make()->title('Demo reset')->body(trim(Artisan::output()))->success()->send();
    }

    public function pauseHour(): void
    {
        DemoSetting::put('paused_until:' . self::SLUG, now()->addHour()->toIso8601String());
        Notification::make()->title('Resets paused for an hour')
            ->body('Nothing will wipe under you mid-walkthrough.')->success()->send();
    }

    public function resumeResets(): void
    {
        DemoSetting::put('paused_until:' . self::SLUG, null);
        Notification::make()->title('Hourly resets resumed')->success()->send();
    }

    public function toggleOffline(): void
    {
        $now = DemoSetting::get('offline:' . self::SLUG) === '1';
        DemoSetting::put('offline:' . self::SLUG, $now ? '0' : '1');
        DemoSetting::put('offline_reason:' . self::SLUG, $this->offlineNote ?: null);
        Notification::make()
            ->title($now ? 'Demo is live again' : 'Demo switched off')
            ->body($now ? 'The /demo link works.' : 'Visitors see a plain "not available" page.')
            ->success()->send();
    }
}
