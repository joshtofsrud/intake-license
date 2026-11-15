<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\WebTemplate;
use App\Support\ShopSearch\SearchConfig;
use App\Support\SiteLook;
use Illuminate\Http\Request;

/**
 * MARKER-WEB-TEMPLATES: Website › Templates. The shop picks a template (made in master admin),
 * light or dark, and changes any piece. Nothing reaches the public site until Apply; the
 * preview beside the controls is the shop's real site rendered with the unsaved choices.
 */
class SiteTemplateController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        $tier = SearchConfig::tier($tenant);
        $templates = WebTemplate::published()
            ->filter(fn ($t) => $t->availableTo($tier) || $t->key === $tenant->site_template)
            ->map(fn ($t) => $t->live())->values();

        $current = $tenant->site_template && $templates->firstWhere('key', $tenant->site_template) ? $tenant->site_template : null;
        $custom = (array) ($tenant->site_custom ?? []);
        $live = [
            'tpl'  => $current ?? ($templates->first()['key'] ?? null),
            'mode' => in_array($tenant->site_mode, SiteLook::MODES, true) ? $tenant->site_mode : 'dark',
            'c'    => ['dark' => (array) ($custom['c']['dark'] ?? []), 'light' => (array) ($custom['c']['light'] ?? [])],
            'p'    => (array) ($custom['p'] ?? []),
        ];
        // a colour or font changed in Settings › Branding since the last Apply shows as a change here too
        if ($current && ($look = SiteLook::for($tenant))) {
            $tpl = $templates->firstWhere('key', $current);
            // the Branding columns hold the dark colours when the site matches the visitor's device
            $m = ($look['setting'] ?? '') === 'auto' ? 'dark' : $look['mode'];
            foreach (SiteLook::ROLES as $r => $_) {
                $have = $look['roles'][$m][$r];
                if ($have !== strtolower((string) ($tpl['modes'][$m][$r] ?? ''))) $live['c'][$m][$r] = $have;
            }
            foreach (['fh', 'fb'] as $k) {
                if ($look['pieces'][$k] !== ($tpl['pieces'][$k] ?? null)) $live['p'][$k] = $look['pieces'][$k];
            }
        }

        $term = \Illuminate\Support\Facades\DB::table('tenant_search_queries')->where('tenant_id', $tenant->id)->where('results_count', '>', 0)
            ->selectRaw('LOWER(query) AS k, COUNT(*) AS n')->groupBy('k')->orderByDesc('n')->value('k');

        return view('tenant.templates.index', [
            'templates'  => $templates,
            'live'       => $live,
            'onTemplate' => (bool) $current,
            'searchTerm' => $term ?: 'a',
        ]);
    }

    /** Hold the unsaved choices for this signed-in session so the preview frame can show them. */
    public function preview(Request $request)
    {
        $d = $this->clean($request);
        if (! $d) return response()->json(['ok' => false], 422);
        session([SiteLook::PREVIEW_KEY => ['tenant' => (string) tenant()->id] + $d]);
        return response()->json(['ok' => true]);
    }

    public function apply(Request $request)
    {
        $tenant = tenant();
        $d = $this->clean($request);
        if (! $d) return back()->with('flash_error', 'That template is no longer available.');

        $tpl = WebTemplate::cached($d['tpl']);
        $live = $tpl->live();

        // keep only what differs from the template, so a later template update still moves
        // everything the shop never changed
        $c = [];
        foreach (['dark', 'light'] as $m) {
            foreach ($d['c'][$m] as $r => $v) {
                if ($v !== strtolower((string) ($live['modes'][$m][$r] ?? ''))) $c[$m][$r] = $v;
            }
        }
        $p = [];
        foreach ($d['p'] as $k => $v) {
            if ((string) $v !== (string) ($live['pieces'][$k] ?? '')) $p[$k] = $v;
        }

        // booking and email read these five columns; a site that matches
        // the visitor's device gives them its dark colours (email can't follow a device)
        $look = SiteLook::build($live, $d['mode'] === 'auto' ? 'dark' : $d['mode'], ['c' => $c, 'p' => $p]);
        $col = SiteLook::colours($look);
        $cols = ['accent_color' => $col['ac'], 'text_color' => $col['tx'], 'bg_color' => $col['bg'],
                 'font_heading' => $look['pieces']['fh'], 'font_body' => $look['pieces']['fb']];

        $tenant->forceFill($cols + [
            'site_template' => $d['tpl'],
            'site_mode'     => $d['mode'],
            'site_custom'   => ['c' => $c ?: new \stdClass(), 'p' => $p ?: new \stdClass(), 'written' => $cols],
        ])->save();
        SiteLook::forget();
        session()->forget(SiteLook::PREVIEW_KEY);

        return redirect()->route('tenant.templates.index')->with('flash', $tpl->name . ' is live on your site.');
    }

    /** The posted choices, checked against what this shop may use. */
    private function clean(Request $request): ?array
    {
        $tenant = tenant();
        $key = (string) $request->input('tpl');
        $tpl = $key !== '' ? WebTemplate::cached($key) : null;
        if (! $tpl) return null;
        if (! $tpl->availableTo(SearchConfig::tier($tenant)) && $tenant->site_template !== $key) return null;

        $mode = in_array($request->input('mode'), SiteLook::MODES, true) ? $request->input('mode') : 'dark';
        $c = ['dark' => [], 'light' => []];
        foreach (['dark', 'light'] as $m) {
            foreach ((array) $request->input("c.$m", []) as $r => $v) {
                if (isset(SiteLook::ROLES[$r]) && ($h = SiteLook::hex($v))) $c[$m][$r] = $h;
            }
        }
        $p = [];
        foreach ((array) $request->input('p', []) as $k => $v) {
            if (isset(SiteLook::PIECES[$k])) $p[$k] = $v;
        }
        $p = array_intersect_key(SiteLook::cleanPieces(array_merge($tpl->live()['pieces'], $p)), $p);

        return ['tpl' => $key, 'mode' => $mode, 'c' => $c, 'p' => $p];
    }
}
