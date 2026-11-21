<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantGoogleConnection;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantSiteHealth;
use App\Models\Tenant\TenantSiteIssue;
use App\Services\SiteHealth\GoogleSiteData;
use App\Services\SiteHealth\SiteChecker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** MARKER-SITE-HEALTH: everything the Site health page (and its dashboard tiles) draws. */
class SiteHealthView
{
    public const GROUPS = ['text' => 'Titles & descriptions', 'media' => 'Photos', 'business' => 'Business details', 'links' => 'Links'];
    private const SHORT = ['text' => 'Words', 'media' => 'Photos', 'business' => 'Details', 'links' => 'Links'];
    private const ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];

    public static function data(Tenant $tenant): array
    {
        $h = TenantSiteHealth::where('tenant_id', $tenant->id)->first();
        $conn = GoogleSiteData::available() ? TenantGoogleConnection::where('tenant_id', $tenant->id)->first() : null;
        $visits = GoogleSiteData::visitsFor($conn);

        $all = TenantSiteIssue::where('tenant_id', $tenant->id)->get();
        $pages = TenantPage::whereIn('id', $all->where('subject_type', 'page')->pluck('subject_id')->filter()->unique()->all())
            ->get(['id', 'title', 'meta_title', 'meta_description'])->keyBy('id');

        $rows = $all->map(function (TenantSiteIssue $i) use ($tenant, $pages, $visits) {
            $page = $i->subject_type === 'page' && $i->subject_id ? ($pages[$i->subject_id] ?? null) : null;
            $d = (array) $i->detail;
            $field = $page ? ($d['field'] ?? null) : null;

            return [
                'id' => $i->id, 'grp' => $i->grp, 'code' => $i->code, 'kind' => $i->kind, 'label' => $i->label, 'path' => $i->path,
                'problem' => $i->problem, 'impact' => $i->impact, 'status' => $i->status, 'detail' => $d, 'weight' => (int) $i->weight,
                'visits' => $i->path !== null ? ($visits[$i->path] ?? null) : null,
                'page' => $page, 'field' => $field, 'items' => $i->subject_type === 'items',
                'now' => $field === 'title' ? (string) ($page->meta_title ?: $page->title) : ($field === 'description' ? (string) $page->meta_description : null),
                'suggest' => $field === 'title' && $i->status === 'open' ? SiteChecker::suggestTitle($tenant, $page, $i->code, $d) : null,
                'can_undo' => $i->status === 'fixed' && $i->undo !== null,
            ];
        })->sort(fn ($a, $b) => [self::ORDER[$a['impact']], -(int) $a['visits'], $a['label']] <=> [self::ORDER[$b['impact']], -(int) $b['visits'], $b['label']])->values();

        $open = $rows->where('status', 'open')->values();
        $counts = ['all' => $open->count()];
        foreach (self::GROUPS as $g => $_) {
            $counts[$g] = $open->where('grp', $g)->count();
        }

        $st = (array) ($h?->state ?? []);
        $run = [
            'running' => (bool) ($st['running'] ?? false), 'stopped' => (bool) ($st['stopped'] ?? false), 'error' => $st['error'] ?? null,
            'pct' => SiteChecker::percent($st), 'done' => (int) ($st['done'] ?? 0), 'total' => (int) ($st['total'] ?? 0), 'what' => SiteChecker::phaseWord($st),
        ];
        // a check nobody is working on any more (the queue was restarted): let Resume pick it up
        if ($run['running'] && $h && $h->updated_at?->lt(now()->subMinutes(10))) {
            $run['running'] = false;
            $run['stopped'] = true;
            $run['error'] = 'The check stopped part way.';
        }

        return [
            'health' => $h, 'checked' => (bool) $h?->checked_at, 'run' => $run, 'live' => self::live($st, $counts),
            'open' => $open, 'done' => $rows->where('status', '!=', 'open')->values(), 'counts' => $counts,
            'tiles' => $h?->checked_at ? self::tiles($tenant, $h, $open, $conn) : [],
            'basics' => (array) ($h?->basics ?? []),
            'suffix' => ' — ' . $tenant->name, 'host' => (string) parse_url($tenant->publicUrl(), PHP_URL_HOST), 'shop' => $tenant->name,
            'google' => self::google($tenant, $conn),
            'has_visits' => (bool) $visits,
        ];
    }

    /** In a few plain sentences: what the score is made of and what would raise it most. */
    public static function scoreTip(TenantSiteHealth $h, $open): string
    {
        $total = max(1, (int) $h->weight_total);
        $lost = (int) $open->sum('weight');
        $high = $open->where('impact', 'high');
        $s = $h->score . ' out of 100. Every check on every page and item counts toward it, and high impact ones count most. ';
        if ($open->isEmpty()) {
            return $s . 'Nothing is open, so there is nothing to raise right now.';
        }
        if ($high->isNotEmpty()) {
            $after = (int) max(0, min(100, round(100 * (1 - ($lost - (int) $high->sum('weight')) / $total))));

            return $s . 'Fixing your ' . $high->count() . ' high impact ' . Str::plural('row', $high->count()) . ' would take it to about ' . $after . '.';
        }
        $top = $open->sortByDesc('weight')->first();

        return $s . 'The row that would raise it most is "' . $top['problem'] . '" on ' . $top['label'] . '.';
    }

    /** What a running check has found so far: rows by kind, and items counted but not yet written up as rows. */
    public static function live(array $st, array $counts): array
    {
        $photo = 0; $words = 0;
        foreach ((array) ($st['cats'] ?? []) as $c) {
            $photo += (int) ($c['photo_n'] ?? 0);
            $words += (int) ($c['words_n'] ?? 0);
        }
        unset($counts['all']);

        return ['found' => $counts, 'photo' => $photo, 'words' => $words];
    }

    private static function tiles(Tenant $tenant, TenantSiteHealth $h, $open, ?TenantGoogleConnection $conn): array
    {
        $hist = array_map(fn ($r) => (int) $r[1], (array) ($h->history ?? []));
        $prev = count($hist) > 1 ? $hist[count($hist) - 2] : null;
        $move = $prev === null ? 'first check' : ($h->score === $prev ? 'same as the check before' : ($h->score > $prev ? 'up ' . ($h->score - $prev) : 'down ' . ($prev - $h->score)) . ' since the check before');

        $parts = [];
        foreach (self::SHORT as $g => $label) {
            $parts[] = ['label' => $label, 'n' => $open->where('grp', $g)->count(), 'bad' => $g === 'links'];
        }
        $high = $open->where('impact', 'high')->count();

        $dates = array_map(fn ($r) => (string) $r[0], (array) ($h->history ?? []));
        $cur = count($hist) > 1 ? $hist : [(int) $h->score, (int) $h->score];
        $tiles = [
            ['tone' => 3, 'title' => 'Site health', 'value' => (int) $h->score, 'pre' => '', 'sub' => $move, 'link' => '#shFix', 'tip' => self::scoreTip($h, $open),
                'chart' => ['type' => 'spark', 'cur' => $cur, 'prev' => [], 'slots' => count($cur),
                    'axis' => [count($dates) > 1 ? Carbon::parse($dates[0])->format('M j') : '', 'Latest check']]],
            ['tone' => 2, 'title' => 'To fix', 'value' => $open->count(), 'pre' => '', 'link' => '#shFix',
                'sub' => $open->count() ? ($high ? $high . ' high impact' : 'none high impact') : 'nothing right now', 'chart' => ['type' => 'stack', 'parts' => $parts]],
        ];

        $gsc = (array) (($conn?->data ?? [])['gsc'] ?? []);
        if ($gsc) {
            $now = (int) $gsc['clicks']; $was = (int) $gsc['clicks_prev'];
            $mx = max(1, max($gsc['weeks']));
            $bars = [];
            foreach ($gsc['weeks'] as $k => $n) {
                $bars[] = ['h' => $n / $mx, 'n' => $n, 'now' => $k === count($gsc['weeks']) - 1, 'label' => $k === count($gsc['weeks']) - 1 ? 'This week' : (count($gsc['weeks']) - 1 - $k) . ' weeks ago'];
            }
            $tiles[] = ['tone' => 1, 'title' => 'Visits from search', 'value' => $now, 'pre' => '', 'link' => '#shGoogle',
                'sub' => $was > 0 ? ($now >= $was ? 'up ' : 'down ') . abs((int) round(($now - $was) / $was * 100)) . '% on the 4 weeks before' : 'last 4 weeks',
                'chart' => ['type' => 'bars', 'bars' => $bars, 'axis' => ['12 weeks ago', '', 'This week']]];
        } else {
            $tiles[] = ['tone' => 1, 'title' => 'Pages and items checked', 'value' => (int) $h->checked, 'pre' => '', 'link' => '#shFix', 'sub' => 'every public page, checked nightly',
                'chart' => ['type' => 'meter', 'max' => 1, 'used' => 1, 'label' => 'Last checked ' . $h->checked_at->diffForHumans()]];
        }

        return $tiles;
    }

    private static function google(Tenant $tenant, ?TenantGoogleConnection $conn): array
    {
        if (! GoogleSiteData::available()) {
            return ['available' => false];
        }
        $out = ['available' => true, 'connected' => (bool) $conn?->refresh_token, 'email' => $conn?->email, 'sources' => [],
            'running' => (bool) (($conn?->state ?? [])['running'] ?? false), 'error' => ($conn?->state ?? [])['error'] ?? null,
            'synced' => $conn?->synced_at?->diffForHumans()];
        // a read nobody is working on any more
        if ($out['running'] && $conn->updated_at?->lt(now()->subMinutes(6))) {
            $out['running'] = false;
            $out['error'] = 'Reading from Google stopped part way. Try Refresh.';
        }
        $tips = [
            'gsc' => 'Your real searches, clicks and average position, from Google Search Console.',
            'ga'  => 'Where visits come from, from Google Analytics.',
            'mc'  => 'Which items Google is showing, and which it turned down and why, from Merchant Center.',
            'gbp' => 'Your Google rating and reviews, from your Business Profile. Reply without leaving Intake.',
        ];
        foreach (GoogleSiteData::sources($tenant) as $src) {
            $opts = (array) (($conn?->options ?? [])[$src] ?? []);
            $out['sources'][$src] = [
                'name' => GoogleSiteData::SOURCES[$src], 'tip' => $tips[$src],
                'data' => (($conn?->data ?? [])[$src] ?? null), 'error' => (($conn?->state ?? [])['errors'] ?? [])[$src] ?? null,
                'options' => $opts, 'pick' => (($conn?->picks ?? [])[$src] ?? null),
                'needs_pick' => $conn && count($opts) > 1 && empty(($conn->picks ?? [])[$src]),
                'reconnect' => $conn && ! GoogleSiteData::allowed($conn, $src),
            ];
        }

        return $out;
    }

    /** "2 days ago", "last week" for a review. */
    public static function ago(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->diffForHumans(['parts' => 1]) : '';
    }

    // ------------------------------------------------------- dashboard tiles

    /** The Site health tile for a dashboard, or null until the shop's site has been checked. */
    public static function dashTile(Tenant $tenant): ?array
    {
        $h = TenantSiteHealth::where('tenant_id', $tenant->id)->whereNotNull('checked_at')->first();
        if (! $h) {
            return null;
        }
        $open = TenantSiteIssue::where('tenant_id', $tenant->id)->where('status', 'open')->count();

        return [
            'key' => 'site_health', 'title' => 'Site health', 'tone' => 4, 'value' => (int) $h->score, 'pre' => '',
            'sub' => $open ? $open . ' ' . Str::plural('thing', $open) . ' to fix' : 'nothing to fix',
            'link' => route('tenant.site_health.index'), 'say' => 'Opening Site health',
            'chart' => ['type' => 'ring', 'pct' => (int) $h->score, 'label' => 'checked ' . $h->checked_at->diffForHumans()],
        ];
    }

    /** The Reviews tile, or null until the shop's Google reviews have been read. */
    public static function reviewsTile(Tenant $tenant): ?array
    {
        if (! GoogleSiteData::available() || ! GoogleSiteData::settings()['reviews']) {
            return null;
        }
        $g = (array) ((TenantGoogleConnection::where('tenant_id', $tenant->id)->value('data') ?? [])['gbp'] ?? []);
        if (! $g || $g['rating'] === null) {
            return null;
        }
        $w = (int) $g['waiting'];

        return [
            'key' => 'reviews', 'title' => 'Reviews', 'tone' => 1, 'value' => (float) $g['rating'], 'decimals' => 1, 'pre' => '',
            'sub' => number_format((int) $g['total']) . ' on Google' . ($w ? ' · ' . $w . ' ' . ($w === 1 ? 'needs' : 'need') . ' a reply' : ''),
            'link' => route('tenant.site_health.index') . '#reviews', 'say' => 'Opening Site health',
            'chart' => ['type' => 'dist', 'dist' => $g['dist'], 'of' => max(1, (int) $g['dist_of'])],
        ];
    }
}
