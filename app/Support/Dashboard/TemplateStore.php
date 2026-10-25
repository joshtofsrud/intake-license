<?php

namespace App\Support\Dashboard;

use App\Models\DashboardBlock;
use App\Models\DashboardTemplate;
use App\Models\DashboardTemplateVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * MARKER-DASH-ADMIN: every change master admin makes to dashboard templates.
 *
 * Shops only ever see a template's live version. Editing writes draft_layout;
 * publishing copies the draft into a new numbered version and points the
 * template at it. Nothing here touches a person's own pick (dashboard_view):
 * a retired template simply stops being offered, and anyone who had it falls
 * back to the default on their next load.
 */
class TemplateStore
{
    public static function find(string $key): DashboardTemplate
    {
        $t = DashboardTemplate::with('liveVersion')->where('key', $key)->first();
        if (! $t) {
            throw new InvalidArgumentException('No such dashboard template.');
        }

        return $t;
    }

    public static function liveLayout(DashboardTemplate $t): ?array
    {
        return $t->liveVersion ? Layout::sanitize((array) $t->liveVersion->layout) : null;
    }

    /** The layout being edited: the saved draft, else the live one, else one empty row. */
    public static function workingLayout(DashboardTemplate $t): array
    {
        if (is_array($t->draft_layout)) {
            return Layout::sanitize($t->draft_layout);
        }

        return self::liveLayout($t) ?? [['split' => [12], 'cols' => [[]]]];
    }

    /** Block names as master admin sees them, for change lists. */
    public static function blockNames(): array
    {
        $names = array_map(fn ($d) => $d['name'], BlockRegistry::builtIns());
        try {
            foreach (DashboardBlock::pluck('name', 'key') as $k => $n) {
                if ($n) $names[$k] = $n;
            }
        } catch (\Throwable $e) {
            // table not there yet: registry names will do
        }

        return $names;
    }

    public static function changes(DashboardTemplate $t, ?array $layout = null): array
    {
        $layout ??= self::workingLayout($t);

        return Layout::diff(self::liveLayout($t), $layout, self::blockNames());
    }

    /** Whether Publish has anything to do: a change, or a template not yet offered. */
    public static function canPublish(DashboardTemplate $t, ?array $layout = null): bool
    {
        $layout ??= self::workingLayout($t);
        if (! Layout::blockKeys($layout)) {
            return false;
        }

        return self::changes($t, $layout) !== [] || $t->state !== 'live';
    }

    public static function create(string $name, ?string $fromKey = null): DashboardTemplate
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Give the template a name of up to 80 characters.');
        }
        $layout = $fromKey ? self::workingLayout(self::find($fromKey)) : [['split' => [12], 'cols' => [[]]]];

        return DB::transaction(function () use ($name, $layout) {
            $t = DashboardTemplate::create([
                // a placeholder until the id exists; keys must fit dashboard_view (16)
                'key' => 'new' . substr(bin2hex(random_bytes(6)), 0, 12),
                'name' => $name, 'is_builtin' => false, 'frame' => 'standard', 'state' => 'draft',
                'switch_label' => mb_substr($name, 0, 40), 'beta_badge' => true, 'icon' => 'custom',
                'rearrange' => 'none', 'is_default' => false,
                'sort' => (int) DashboardTemplate::max('sort') + 10,
                'draft_layout' => $layout,
            ]);
            $t->update(['key' => 'c' . $t->id]);
            debug_log()->audit('dashboard.template_created', "Created dashboard template {$name}", $t);

            return $t;
        });
    }

    public static function duplicate(string $key): DashboardTemplate
    {
        $src = self::find($key);

        return self::create(mb_substr($src->name . ' copy', 0, 80), $key);
    }

    public static function saveDraft(string $key, array $layout): DashboardTemplate
    {
        $t = self::find($key);
        if ($t->state === 'retired') {
            throw new InvalidArgumentException('Restore this template before editing it.');
        }
        $layout = Layout::sanitize($layout);
        // a draft identical to live is no draft at all
        $t->update(['draft_layout' => ($t->liveVersion && self::changes($t, $layout) === []) ? null : $layout]);

        return $t->fresh('liveVersion');
    }

    public static function discardDraft(string $key): DashboardTemplate
    {
        $t = self::find($key);
        $t->update(['draft_layout' => null]);
        debug_log()->audit('dashboard.draft_discarded', "Discarded the draft of {$t->name}", $t);

        return $t->fresh('liveVersion');
    }

    public static function publish(string $key, array $layout, ?string $note, ?int $userId): DashboardTemplateVersion
    {
        $t = self::find($key);
        if ($t->state === 'retired') {
            throw new InvalidArgumentException('Restore this template before publishing it.');
        }
        $layout = Layout::sanitize($layout);
        if (! self::canPublish($t, $layout)) {
            throw new InvalidArgumentException(Layout::blockKeys($layout) ? 'Nothing has changed since the live version.' : 'Add at least one block before publishing.');
        }
        $changes = self::changes($t, $layout);

        $v = DB::transaction(function () use ($t, $layout, $note, $userId) {
            $n = (int) DashboardTemplateVersion::where('dashboard_template_id', $t->id)->lockForUpdate()->max('version') + 1;
            $v = DashboardTemplateVersion::create([
                'dashboard_template_id' => $t->id, 'version' => $n, 'layout' => $layout,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
                'published_by_user_id' => $userId, 'published_at' => now(),
            ]);
            $t->update(['live_version_id' => $v->id, 'draft_layout' => null, 'state' => 'live', 'retired_at' => null]);

            return $v;
        });
        DashboardTemplates::flush();
        debug_log()->audit('dashboard.published', "Published {$t->name} v{$v->version}: " . implode('; ', array_column($changes, 1)), $t);

        return $v;
    }

    /** Puts an old version back into the draft, for review before publishing. */
    public static function openVersion(string $key, int $versionId): DashboardTemplate
    {
        $t = self::find($key);
        $v = DashboardTemplateVersion::where('dashboard_template_id', $t->id)->findOrFail($versionId);
        $t->update(['draft_layout' => Layout::sanitize((array) $v->layout)]);

        return $t->fresh('liveVersion');
    }

    public static function saveSettings(string $key, string $name, string $label, bool $beta, ?string $rearrange = null): DashboardTemplate
    {
        $name = trim($name);
        $label = trim($label);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('The name needs 1 to 80 characters.');
        }
        if ($label === '' || mb_strlen($label) > 40) {
            throw new InvalidArgumentException('The switch label needs 1 to 40 characters.');
        }
        $t = self::find($key);
        $rearrange = in_array($rearrange, ['none', 'hide', 'both'], true) ? $rearrange : $t->rearrange;
        $t->update(['name' => $name, 'switch_label' => $label, 'beta_badge' => $beta, 'rearrange' => $rearrange]);
        DashboardTemplates::flush();
        $can = ['none' => 'nothing', 'hide' => 'hide blocks', 'both' => 'hide and reorder blocks'][$rearrange];
        debug_log()->audit('dashboard.settings_changed', "Settings for {$name}: switch label “{$label}”" . ($beta ? ', BETA label' : '') . "; people can change: {$can}", $t);

        return $t->fresh('liveVersion');
    }

    public static function retire(string $key): DashboardTemplate
    {
        $t = self::find($key);
        if ($t->is_default) {
            throw new InvalidArgumentException('Choose another default before retiring this one.');
        }
        $t->update(['state' => 'retired', 'retired_at' => now()]);
        DashboardTemplates::flush();
        debug_log()->audit('dashboard.retired', "Retired {$t->name}", $t);

        return $t;
    }

    /** Back as a draft: not offered to shops until it's published again. */
    public static function restore(string $key): DashboardTemplate
    {
        $t = self::find($key);
        $t->update(['state' => 'draft', 'retired_at' => null]);
        debug_log()->audit('dashboard.restored', "Restored {$t->name} as a draft", $t);

        return $t;
    }

    public static function setDefault(string $key): DashboardTemplate
    {
        $t = self::find($key);
        if ($t->state !== 'live') {
            throw new InvalidArgumentException('Only a live template can be the default.');
        }
        if (! Audience::isEveryone($t->audience)) {
            throw new InvalidArgumentException('The default has to be offered to every shop. Change who gets it first.');
        }
        DB::transaction(function () use ($t) {
            DashboardTemplate::query()->update(['is_default' => false]);
            $t->update(['is_default' => true]);
        });
        DashboardTemplates::flush();

        return $t;
    }

    /** Who gets the template. The default always goes to every shop. */
    public static function saveAudience(string $key, array $audience): DashboardTemplate
    {
        $t = self::find($key);
        $audience = Audience::normalize($audience);
        if ($t->is_default && ! Audience::isEveryone($audience)) {
            throw new InvalidArgumentException('This is the default, so every shop gets it. Choose another default first.');
        }
        if ($audience['mode'] === 'chosen' && ! $audience['include']) {
            throw new InvalidArgumentException('Add at least one shop, or choose another option.');
        }
        $t->update(['audience' => Audience::isEveryone($audience) ? null : $audience]);
        DashboardTemplates::flush();
        debug_log()->audit('dashboard.audience_changed', "Who gets {$t->name}: " . Audience::describe($audience), $t);

        return $t->fresh('liveVersion');
    }

    /** The platform-wide switch for people customizing their dashboards. */
    public static function setCustomizeEnabled(bool $on): void
    {
        $s = \App\Models\PlatformSettings::current();
        $s->update(['dashboard_customize_enabled' => $on]);
        \App\Models\PlatformSettings::forget();
        debug_log()->audit('dashboard.customize_switch', 'Dashboard customizing turned ' . ($on ? 'on' : 'off') . ' for every shop', $s);
    }

    /** People who picked each template themselves, keyed by template key. */
    public static function pickCounts(): array
    {
        return \App\Models\Tenant\TenantUser::query()
            ->where('is_active', true)
            ->whereNotNull('dashboard_view')
            ->select('dashboard_view', DB::raw('count(*) as n'))
            ->groupBy('dashboard_view')
            ->pluck('n', 'dashboard_view')
            ->all();
    }
}
