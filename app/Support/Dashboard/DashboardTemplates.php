<?php

namespace App\Support\Dashboard;

use App\Models\DashboardBlock;
use App\Models\DashboardTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Which dashboard templates a person can switch between, and which one they
 * see. Reads dashboard_templates / their live versions; if those tables can't
 * be read (a migration hasn't run yet) it falls back to the built-in
 * definitions below, which are exactly what the tables are seeded with — so
 * a shop's dashboard never breaks over it.
 */
class DashboardTemplates
{
    /** The frame is the page around the blocks: its header, switch and scripts. */
    public const FRAMES = [
        'overview' => 'tenant.dashboard',
        'tiles'    => 'tenant.dashboard-tiles',
        'brief'    => 'tenant.dashboard-brief',
    ];

    /** Switch icons. The same SVGs the three views drew by hand before. */
    public const ICONS = [
        'overview' => '<svg class="vs-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="14" height="14" rx="2"/><path d="M21 7v10"/></svg>',
        'tiles'    => '<svg class="vs-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
        'brief'    => '<svg class="vs-ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h10M4 18h13"/></svg>',
    ];

    private static ?array $cache = null;

    /**
     * Overview, Tiles and Brief as templates: the same blocks, in the same
     * order, as the three views drew before templates existed.
     */
    public static function builtInDefinitions(): array
    {
        $p = fn (string $b) => ['b' => $b];

        return [
            ['key' => 'overview', 'name' => 'Overview', 'frame' => 'overview', 'switch_label' => 'Overview', 'beta_badge' => false,
             'icon' => 'overview', 'is_default' => true, 'sort' => 10, 'state' => 'live', 'rearrange' => 'none',
             'layout' => [
                 ['split' => [12], 'cols' => [[$p('attention')]]],
                 ['split' => [12], 'cols' => [[$p('today')]]],
                 ['split' => [12], 'cols' => [[$p('growth')]]],
                 ['split' => [12], 'cols' => [[$p('launcher')]]],
             ]],
            ['key' => 'tiles', 'name' => 'Tiles', 'frame' => 'tiles', 'switch_label' => 'Tiles', 'beta_badge' => false,
             'icon' => 'tiles', 'is_default' => false, 'sort' => 20, 'state' => 'live', 'rearrange' => 'none',
             'layout' => [
                 ['split' => [12], 'cols' => [[$p('needs_you')]]],
                 ['split' => [12], 'cols' => [[$p('tiles')]]],
             ]],
            ['key' => 'brief', 'name' => 'Brief', 'frame' => 'brief', 'switch_label' => 'Brief', 'beta_badge' => true,
             'icon' => 'brief', 'is_default' => false, 'sort' => 30, 'state' => 'live', 'rearrange' => 'none',
             'layout' => [
                 ['split' => [12], 'cols' => [[$p('greeting'), $p('actions'), $p('setup')]]],
                 ['split' => [7, 5], 'cols' => [[$p('queue')], [$p('week'), $p('last30')]]],
             ]],
        ];
    }

    /** Writes the built-in blocks and templates (v1 of each). Safe to run twice. */
    public static function seedBuiltIns(): void
    {
        $now = now();

        foreach (BlockRegistry::builtIns() as $key => $def) {
            DB::table('dashboard_blocks')->updateOrInsert(
                ['key' => $key],
                ['name' => $def['name'], 'kind' => 'data', 'is_builtin' => true, 'origin' => $def['origin'], 'created_at' => $now, 'updated_at' => $now]
            );
        }

        foreach (self::builtInDefinitions() as $def) {
            if (DB::table('dashboard_templates')->where('key', $def['key'])->exists()) {
                continue;
            }
            $id = DB::table('dashboard_templates')->insertGetId([
                'key' => $def['key'], 'name' => $def['name'], 'is_builtin' => true, 'frame' => $def['frame'],
                'state' => $def['state'], 'switch_label' => $def['switch_label'], 'beta_badge' => $def['beta_badge'],
                'icon' => $def['icon'], 'rearrange' => $def['rearrange'], 'is_default' => $def['is_default'],
                'sort' => $def['sort'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $vid = DB::table('dashboard_template_versions')->insertGetId([
                'dashboard_template_id' => $id, 'version' => 1, 'layout' => json_encode($def['layout']),
                'note' => 'Made a template from the ' . $def['name'] . ' view. Nothing changed on screen.',
                'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('dashboard_templates')->where('id', $id)->update(['live_version_id' => $vid]);
        }
    }

    /**
     * Templates that appear in the dashboard switch, in order, each with its
     * live layout (hidden and unknown blocks already taken out).
     *
     * @return array<int, array{key: string, name: string, frame: string, label: string, beta: bool, icon: string, default: bool, rows: array}>
     */
    public static function switchable(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $hidden = DashboardBlock::whereNotNull('hidden_at')->pluck('key')->all();
            $list = DashboardTemplate::with('liveVersion')
                ->whereIn('state', ['live', 'beta'])
                ->whereNotNull('live_version_id')
                ->whereIn('frame', array_keys(self::FRAMES))
                ->orderBy('sort')->orderBy('id')
                ->get()
                ->map(fn (DashboardTemplate $t) => [
                    'key'     => $t->key,
                    'name'    => $t->name,
                    'frame'   => $t->frame,
                    'label'   => $t->switch_label ?: $t->name,
                    'beta'    => (bool) $t->beta_badge,
                    'icon'    => $t->icon ?: $t->frame,
                    'default' => (bool) $t->is_default,
                    'rows'    => Layout::visible((array) ($t->liveVersion?->layout ?? []), $hidden),
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            report($e);
            $list = [];
        }

        if (! $list) {
            $list = array_map(fn ($d) => [
                'key' => $d['key'], 'name' => $d['name'], 'frame' => $d['frame'], 'label' => $d['switch_label'],
                'beta' => $d['beta_badge'], 'icon' => $d['icon'], 'default' => $d['is_default'],
                'rows' => Layout::visible($d['layout']),
            ], self::builtInDefinitions());
        }

        return self::$cache = $list;
    }

    /** The template this person sees: the one they picked, else the default, else the first. */
    public static function activeFor(?string $picked): array
    {
        $list = self::switchable();
        foreach ($list as $t) {
            if ($picked !== null && $t['key'] === $picked) return $t;
        }
        foreach ($list as $t) {
            if ($t['default']) return $t;
        }

        return $list[0];
    }

    public static function keys(): array
    {
        return array_column(self::switchable(), 'key');
    }

    public static function frameView(array $template): string
    {
        return self::FRAMES[$template['frame']] ?? self::FRAMES['overview'];
    }

    /** For tests and long-running workers: forget what this process read. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
