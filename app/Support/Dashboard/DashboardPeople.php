<?php

namespace App\Support\Dashboard;

use App\Models\DashboardMove;
use App\Models\DashboardTemplate;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * MARKER-DASH-PEOPLE: who is on which dashboard, and moving people between them.
 *
 * What a person actually sees follows the same rule as their own dashboard
 * (DashboardTemplates::activeFor): the dashboard they picked if it is live and
 * their shop is offered it, otherwise the default. Everything here is read from
 * the same functions, so master admin shows what the shop sees.
 *
 * A move sets tenant_users.dashboard_view. Moving someone to the default clears
 * their pick, so they follow the default from then on. Deactivated people are
 * left out: they have no dashboard until they are switched back on.
 */
class DashboardPeople
{
    /** Most people one move can take, so a single save stays small. */
    public const MAX_SELECT = 1000;

    public static function defaultKey(): string
    {
        return DashboardTemplates::activeFor(null)['key'];
    }

    /**
     * Every active person with what they picked and what they get.
     *
     * status: picked   chose a dashboard they can have
     *         default  hasn't chosen, follows the default
     *         fell     chose one they can't have now, so sees the default
     *
     * @return array{rows: array<int, array>, templates: \Illuminate\Support\Collection, default: string}
     */
    public static function people(): array
    {
        $templates = DashboardTemplate::orderBy('sort')->orderBy('id')->get()->keyBy('key');
        $defaultKey = self::defaultKey();

        $users = TenantUser::query()->where('is_active', true)
            ->get(['id', 'tenant_id', 'name', 'email', 'role', 'dashboard_view', 'dashboard_prefs']);
        $tenants = Tenant::whereIn('id', $users->pluck('tenant_id')->unique()->all())->get()->keyBy('id');

        $offered = [];
        $rows = [];
        foreach ($users as $u) {
            $tenant = $tenants[$u->tenant_id] ?? null;
            if (! $tenant) {
                continue;
            }
            $pick = $u->dashboard_view ?: null;
            $status = 'default';
            $effective = $defaultKey;
            $why = null;
            if ($pick !== null) {
                $keys = $offered[$tenant->id] ??= DashboardTemplates::keys($tenant);
                if (in_array($pick, $keys, true)) {
                    $status = 'picked';
                    $effective = $pick;
                } else {
                    $status = 'fell';
                    $why = self::whyNot($templates[$pick] ?? null, $tenant);
                }
            }
            $rows[] = [
                'id'        => (string) $u->id,
                'tenant_id' => (string) $tenant->id,
                'shop'      => (string) $tenant->name,
                'plan'      => \App\Support\Dashboard\Audience::PLANS[$tenant->plan_tier ?? 'starter'] ?? (string) $tenant->plan_tier,
                'name'      => (string) $u->name,
                'email'     => (string) $u->email,
                'role'      => ucfirst((string) $u->role),
                'pick'      => $pick,
                'effective' => $effective,
                'status'    => $status,
                'why'       => $why,
                'custom'    => ! empty(((array) ($u->dashboard_prefs ?? []))[$effective] ?? null),
            ];
        }

        return ['rows' => $rows, 'templates' => $templates, 'default' => $defaultKey];
    }

    /** Why a shop can't have the dashboard a person picked, in words. */
    private static function whyNot(?DashboardTemplate $t, Tenant $tenant): string
    {
        if (! $t) {
            return 'it no longer exists';
        }
        if ($t->state === 'retired') {
            return 'it was retired';
        }
        if (! in_array($t->state, ['live', 'beta'], true) || ! $t->live_version_id) {
            return 'it is not published';
        }
        [$ok, $why] = Audience::check($t->audience, $tenant);

        return $ok ? 'it is not available right now' : "this shop isn't offered it ({$why})";
    }

    /**
     * Per-dashboard counts for the summary: people seeing it, people who chose
     * it, people following it as the default, and people who chose it but fell
     * back to the default.
     */
    public static function summary(array $rows, $templates, string $defaultKey): array
    {
        $out = [];
        foreach ($templates as $t) {
            $out[$t->key] = ['seeing' => 0, 'shops' => [], 'picked' => 0, 'following' => 0, 'fell' => 0];
        }
        $out['_gone'] = ['seeing' => 0, 'shops' => [], 'picked' => 0, 'following' => 0, 'fell' => 0];
        foreach ($rows as $r) {
            $e = $r['effective'];
            $out[$e]['seeing']++;
            $out[$e]['shops'][$r['tenant_id']] = true;
            if ($r['status'] === 'picked') {
                $out[$r['pick']]['picked']++;
            } elseif ($r['status'] === 'default') {
                $out[$defaultKey]['following']++;
            } else {
                $out[isset($out[$r['pick']]) ? $r['pick'] : '_gone']['fell']++;
            }
        }
        foreach ($out as $k => $v) {
            $out[$k]['shops'] = count($v['shops']);
        }

        return $out;
    }

    /** Ids of active people who chose this dashboard (whether or not they can still have it). */
    public static function pickedIds(string $key, ?int $limit = self::MAX_SELECT + 1): array
    {
        $q = TenantUser::where('is_active', true)->where('dashboard_view', $key);
        if ($limit !== null) {
            $q->limit($limit);
        }

        return $q->pluck('id')->map(fn ($i) => (string) $i)->all();
    }

    /**
     * What a move to $targetKey would do for these people, without doing it.
     *
     * move     person id => what they have now (null = follows the default)
     * already  people who already have it
     * skip     shops that can't have it: tenant id => [shop, why, n]
     * from     where the movers are now: dashboard key => people
     * value    what dashboard_view will be set to (null when the target is the default)
     */
    public static function plan(array $userIds, string $targetKey, int $cap = self::MAX_SELECT): array
    {
        $target = DashboardTemplate::where('key', $targetKey)->first();
        if (! $target || ! in_array($target->state, ['live', 'beta'], true) || ! $target->live_version_id) {
            throw new InvalidArgumentException('Choose a live dashboard to move people to.');
        }
        $defaultKey = self::defaultKey();
        $users = TenantUser::whereIn('id', array_slice($userIds, 0, $cap))->where('is_active', true)
            ->get(['id', 'tenant_id', 'dashboard_view']);
        $tenants = Tenant::whereIn('id', $users->pluck('tenant_id')->unique()->all())->get()->keyBy('id');

        $move = [];
        $already = 0;
        $skip = [];
        $from = [];
        $offered = [];
        $shops = [];
        foreach ($users as $u) {
            $tenant = $tenants[$u->tenant_id] ?? null;
            if (! $tenant) {
                continue;
            }
            $keys = $offered[$tenant->id] ??= DashboardTemplates::keys($tenant);
            if (! in_array($targetKey, $keys, true)) {
                [, $why] = Audience::check($target->audience, $tenant);
                $skip[$tenant->id] ??= ['shop' => (string) $tenant->name, 'why' => $why, 'n' => 0];
                $skip[$tenant->id]['n']++;
                continue;
            }
            $cur = $u->dashboard_view ?: null;
            if ($cur === $targetKey || ($cur === null && $targetKey === $defaultKey)) {
                $already++;
                continue;
            }
            $move[(string) $u->id] = $cur;
            $effective = ($cur !== null && in_array($cur, $keys, true)) ? $cur : $defaultKey;
            $from[$effective] = ($from[$effective] ?? 0) + 1;
            $shops[(string) $tenant->id] = true;
        }

        return [
            'target'  => $target,
            'value'   => $targetKey === $defaultKey ? null : $targetKey,
            'move'    => $move,
            'already' => $already,
            'skip'    => array_values($skip),
            'from'    => $from,
            'shops'   => count($shops),
            'toDefault' => $targetKey === $defaultKey,
        ];
    }

    /** Applies a plan and records it. Returns null when there is nobody to move. */
    public static function move(array $plan, string $fromLabel, string $reason, ?object $admin): ?DashboardMove
    {
        if (! $plan['move']) {
            return null;
        }

        return DB::transaction(function () use ($plan, $fromLabel, $reason, $admin) {
            foreach (array_chunk(array_keys($plan['move']), 500) as $ids) {
                TenantUser::whereIn('id', $ids)->update(['dashboard_view' => $plan['value']]);
            }
            $m = DashboardMove::create([
                'admin_user_id' => $admin->id ?? null,
                'admin_name'    => isset($admin->name) ? mb_substr((string) $admin->name, 0, 120) : null,
                'reason'        => $reason,
                'from_label'    => mb_substr($fromLabel, 0, 190),
                'to_key'        => $plan['target']->key,
                'to_name'       => mb_substr((string) $plan['target']->name, 0, 80),
                'written'       => $plan['value'],
                'people'        => count($plan['move']),
                'shops'         => $plan['shops'],
                'previous'      => $plan['move'],
            ]);
            debug_log()->audit(
                'dashboard.people_moved',
                'Moved ' . count($plan['move']) . ' ' . \Illuminate\Support\Str::plural('person', count($plan['move']))
                    . " in {$plan['shops']} " . \Illuminate\Support\Str::plural('shop', $plan['shops'])
                    . " to {$plan['target']->name}" . ($reason === 'retire' ? ' while retiring a dashboard' : '') . ": {$fromLabel}",
                $plan['target']
            );

            return $m;
        });
    }

    /**
     * Puts people back as they were. Anyone whose dashboard has changed since
     * the move (they picked another one themselves, or were moved again) is
     * left alone.
     *
     * @return array{0:int,1:int} restored, left alone
     */
    public static function undo(DashboardMove $m): array
    {
        if ($m->undone_at) {
            throw new InvalidArgumentException('That move was already undone.');
        }
        $previous = (array) $m->previous;
        $current = TenantUser::whereIn('id', array_keys($previous))->pluck('dashboard_view', 'id');

        $groups = [];
        $left = 0;
        foreach ($previous as $id => $before) {
            if (! $current->has($id) || ($current[$id] ?: null) !== $m->written) {
                $left++;
                continue;
            }
            $groups[$before === null ? '' : $before][] = $id;
        }
        $restored = 0;
        DB::transaction(function () use ($groups, &$restored, $m) {
            foreach ($groups as $before => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    TenantUser::whereIn('id', $chunk)->update(['dashboard_view' => $before === '' ? null : (string) $before]);
                    $restored += count($chunk);
                }
            }
            $m->update(['undone_at' => now()]);
        });
        debug_log()->audit('dashboard.move_undone', "Undid a move to {$m->to_name}: {$restored} restored, {$left} left as they are now", null);

        return [$restored, $left];
    }
}
