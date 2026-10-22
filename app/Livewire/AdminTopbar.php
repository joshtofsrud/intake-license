<?php

namespace App\Livewire;

use App\Support\AdminAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * MARKER-ADMIN-TOPBAR: Inbox, Calendar, Follow-ups and Issues icons left of
 * Search on every master admin page. A count shows only when something is
 * waiting; hover says what. Polls once a minute; counts are cached for a
 * minute per person (display only, nothing durable lives in the cache).
 */
class AdminTopbar extends Component
{
    public function counts(): array
    {
        $u = Auth::guard('web')->user();
        if (! $u) return [];
        return Cache::remember('admin-topbar:' . $u->id, 55, fn () => $this->compute($u));
    }

    private function compute($u): array
    {
        $role = method_exists($u, 'roleName') ? $u->roleName() : null;
        $out = [];

        // Inbox: owner/admin only, like the page (unmapped area)
        if (in_array($role, ['owner', 'admin'], true)) {
            $out['inbox'] = $this->safe(function () {
                $q = \App\Models\PlatformInboxMessage::query()->messages()->unread();
                $n = (clone $q)->count();
                $last = $n ? (clone $q)->orderByDesc('last_message_at')->orderByDesc('created_at')->first() : null;
                $who = $last ? trim(($last->name ?: $last->email) . ($last->company && $last->company !== $last->name ? ' at ' . $last->company : '')) : '';
                return ['n' => $n, 'url' => \App\Filament\Pages\PlatformInbox::getUrl(),
                    'title' => $n ? "Inbox · $n unread" : 'Inbox',
                    'line' => $last ? 'Latest: ' . $who . ', ' . ($last->last_message_at ?? $last->created_at)->diffForHumans() : 'Nothing unread.'];
            });
        }

        if (AdminAccess::allows($u, 'scheduling')) {
            $out['calendar'] = $this->safe(function () {
                $tz = \App\Models\PlatformBookingSetting::get('timezone') ?: 'America/Los_Angeles';
                $start = now($tz)->startOfDay()->utc(); $end = now($tz)->endOfDay()->utc();
                $today = \App\Models\PlatformBooking::with('type')->whereIn('status', \App\Models\PlatformBooking::ACTIVE_STATUSES)
                    ->whereBetween('starts_at', [$start, $end])->orderBy('starts_at')->get();
                $next = $today->first(fn ($b) => $b->starts_at->greaterThanOrEqualTo(now()->subMinutes(15)));
                $line = $next
                    ? 'Next: ' . ($next->type?->name ?? 'Call') . ' with ' . trim($next->name . ($next->company ? ' (' . $next->company . ')' : '')) . ', ' . $next->starts_at->setTimezone($tz)->format('g:i a')
                    : ($today->count() ? 'All done for today.' : 'Nothing booked today.');
                return ['n' => $today->count(), 'url' => \App\Filament\Pages\Scheduling::getUrl(),
                    'title' => 'Today · ' . $today->count() . ' ' . ($today->count() === 1 ? 'call' : 'calls'), 'line' => $line];
            });
        }

        if (AdminAccess::allows($u, 'crm')) {
            $out['follow'] = $this->safe(function () {
                $n = \App\Services\Sales\OutreachResults::followUpCount();
                return ['n' => $n, 'url' => \App\Filament\Pages\SalesEmailOutreach::getUrl() . '?tab=results',
                    'title' => 'Follow up · ' . $n . ' ' . ($n === 1 ? 'shop' : 'shops'),
                    'line' => 'Opened or visited from your outreach email since you last emailed them, no reply yet.'];
            });
        }

        if (AdminAccess::allows($u, 'logs')) {
            $out['issues'] = $this->safe(function () {
                $q = \App\Models\DebugLog::query()->issues()->where('is_resolved', false)->where('created_at', '>=', now()->subDay());
                $n = (clone $q)->count();
                $last = $n ? (clone $q)->latest()->first() : null;
                return ['n' => $n, 'url' => \App\Filament\Resources\DebugLogResource::getUrl(),
                    'title' => $n ? "Issues · $n in the last day" : 'Issues',
                    'line' => $last ? mb_strimwidth((string) $last->message, 0, 90, '…') . ', ' . $last->created_at->diffForHumans() : 'No unresolved errors in the last day.'];
            });
        }

        return array_filter($out);
    }

    private function safe(callable $fn): ?array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    public function render()
    {
        return view('livewire.admin-topbar', ['c' => $this->counts()]);
    }
}
