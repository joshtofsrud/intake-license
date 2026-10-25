<?php

namespace App\Support\Dashboard;

use App\Models\DashboardBlock;
use App\Models\DashboardTemplate;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * MARKER-DASH-BUILDER: every change master admin makes to blocks — making,
 * editing, copying and deleting made-here blocks, and hiding any block
 * everywhere.
 *
 * Unlike templates, blocks have no draft: a saved change shows on every
 * dashboard that has the block on its next load (the builder says how many
 * shops that is before you save).
 */
class BlockStore
{
    public static function find(string $key): DashboardBlock
    {
        $b = DashboardBlock::where('key', $key)->first();
        if (! $b) throw new InvalidArgumentException('No such block.');

        return $b;
    }

    /** Creates or updates a made-here block. Returns it. */
    public static function save(?string $key, string $name, string $kind, array $config): DashboardBlock
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) throw new InvalidArgumentException('Give the block a name of up to 80 characters.');
        if (! in_array($kind, ['data', 'action', 'note'], true)) throw new InvalidArgumentException('Choose what kind of block it is.');
        $config = CustomBlocks::normalize($kind, $config);
        if ($kind === 'note' && $config['text'] === '') throw new InvalidArgumentException('Write the note first.');

        if ($key) {
            $b = self::find($key);
            if ($b->is_builtin) throw new InvalidArgumentException("Built-in blocks come from code and can't be edited here.");
            $b->update(['name' => $name, 'kind' => $kind, 'config' => $config]);
            debug_log()->audit('dashboard.block_changed', "Changed block {$name}: " . CustomBlocks::sentence($kind, $config), $b);
        } else {
            $b = DB::transaction(function () use ($name, $kind, $config) {
                $b = DashboardBlock::create(['key' => 'new' . bin2hex(random_bytes(8)), 'name' => $name, 'kind' => $kind,
                    'is_builtin' => false, 'origin' => 'custom', 'config' => $config]);
                $b->update(['key' => CustomBlocks::KEY_PREFIX . $b->id]);

                return $b;
            });
            debug_log()->audit('dashboard.block_created', "Made block {$name}: " . CustomBlocks::sentence($kind, $config), $b);
        }
        DashboardTemplates::flush();

        return $b->fresh();
    }

    public static function duplicate(string $key): DashboardBlock
    {
        $src = self::find($key);
        if ($src->is_builtin) throw new InvalidArgumentException("Built-in blocks can't be copied.");

        return self::save(null, mb_substr($src->name . ' copy', 0, 80), $src->kind, (array) $src->config);
    }

    /** Deletes a made-here block. It drops off every template it was on. */
    public static function delete(string $key): DashboardBlock
    {
        $b = self::find($key);
        if ($b->is_builtin) throw new InvalidArgumentException("Built-in blocks can't be deleted. Hide it everywhere instead.");
        $b->delete();
        DashboardTemplates::flush();
        debug_log()->audit('dashboard.block_deleted', "Deleted block {$b->name}", $b);

        return $b;
    }

    public static function setHidden(string $key, bool $hidden): DashboardBlock
    {
        $b = self::find($key);
        $b->update(['hidden_at' => $hidden ? now() : null]);
        DashboardTemplates::flush();
        debug_log()->audit($hidden ? 'dashboard.block_hidden' : 'dashboard.block_unhidden', ($hidden ? 'Hid ' : 'Unhid ') . "{$b->name} everywhere", $b);

        return $b;
    }

    /**
     * Where each block is placed: key => [[template name, live?, draft only?], ...].
     * Live means on the template's live version; a block only on a draft is
     * marked so.
     */
    public static function usage(): array
    {
        $out = [];
        foreach (DashboardTemplate::with('liveVersion')->where('state', '!=', 'retired')->orderBy('sort')->get() as $t) {
            $live = $t->liveVersion ? Layout::blockKeys((array) $t->liveVersion->layout) : [];
            $draft = is_array($t->draft_layout) ? Layout::blockKeys($t->draft_layout) : [];
            foreach (array_unique(array_merge($live, $draft)) as $k) {
                $out[$k][] = ['name' => $t->name, 'key' => $t->key, 'live' => $t->state === 'live' && in_array($k, $live, true)];
            }
        }

        return $out;
    }

    /** Shops that have a live template with this block on it (audiences applied, roles aside). */
    public static function shopCount(string $key): int
    {
        $n = 0;
        $everyone = false;
        $auds = [];
        foreach (DashboardTemplate::with('liveVersion')->where('state', 'live')->get() as $t) {
            if (! $t->liveVersion || ! in_array($key, Layout::blockKeys((array) $t->liveVersion->layout), true)) continue;
            if ($t->is_default || Audience::isEveryone($t->audience)) { $everyone = true; break; }
            $auds[] = $t->audience;
        }
        if ($everyone) return \App\Models\Tenant::where('is_active', true)->count();
        if (! $auds) return 0;
        \App\Models\Tenant::where('is_active', true)->chunk(200, function ($chunk) use ($auds, &$n) {
            foreach ($chunk as $tn) {
                foreach ($auds as $a) {
                    if (Audience::allows($a, $tn)) { $n++; break; }
                }
            }
        });

        return $n;
    }
}
