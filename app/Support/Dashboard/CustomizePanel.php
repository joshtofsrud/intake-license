<?php

namespace App\Support\Dashboard;

/**
 * MARKER-DASH-AUDIENCE: the "Customize" panel on a shop's dashboard — the
 * blocks one person can hide (and, if the template allows, reorder), and the
 * cleaning of what they send back.
 */
class CustomizePanel
{
    /**
     * Null when there's nothing this person can change here (customizing is
     * off platform-wide or on this template, or every block is locked).
     *
     * @param array $roleRows  the template's rows with role filtering applied, before personal choices
     */
    public static function for(array $template, array $roleRows, array $prefs, string $rearrange): ?array
    {
        if ($rearrange === 'none') {
            return null;
        }
        $names = TemplateStore::blockNames();
        $hidden = array_map('strval', (array) ($prefs['hidden'] ?? []));
        $order = $rearrange === 'both' ? (array) ($prefs['order'] ?? []) : [];

        $allowed = [];
        foreach ($roleRows as $rr) foreach ($rr['cols'] as $cc) foreach ($cc as $p) $allowed[$p['b']] = $p;

        $groups = [];
        $hideable = 0;
        $hiddenCount = 0;
        foreach ($template['rows'] as $r => $row) {
            foreach ($row['cols'] as $c => $col) {
                // the role-filtered column, matched by block key
                $keys = array_column($col, 'b');
                $blocks = [];
                foreach ($keys as $k) {
                    if (! isset($allowed[$k])) continue;
                    $locked = ! empty($allowed[$k]['lock']);
                    $isHidden = ! $locked && in_array($k, $hidden, true);
                    $hideable += $locked ? 0 : 1;
                    $hiddenCount += $isHidden ? 1 : 0;
                    $blocks[] = ['key' => $k, 'name' => $names[$k] ?? $k, 'locked' => $locked, 'hidden' => $isHidden];
                }
                $mine = array_values(array_map('strval', (array) ($order[$r . '-' . $c] ?? [])));
                if ($mine) {
                    usort($blocks, function ($a, $b) use ($mine) {
                        $ia = array_search($a['key'], $mine, true); $ib = array_search($b['key'], $mine, true);
                        return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
                    });
                }
                if (! $blocks) continue;
                $where = count($row['split']) > 1
                    ? ['Left', 'Middle', 'Right'][count($row['split']) === 2 && $c === 1 ? 2 : $c] . ' column'
                    : 'Full width';
                $groups[] = ['key' => $r . '-' . $c, 'label' => 'Row ' . ($r + 1) . ' · ' . $where, 'blocks' => $blocks];
            }
        }

        if ($hideable === 0 && $rearrange !== 'both') {
            return null;
        }

        return ['template' => $template['key'], 'name' => $template['name'], 'mode' => $rearrange, 'groups' => $groups, 'hiddenCount' => $hiddenCount];
    }

    /** What a person sent, kept to what this template lets them change. Null = not allowed at all. */
    public static function clean(array $template, ?string $role, array $data, string $rearrange): ?array
    {
        if ($rearrange === 'none') {
            return null;
        }
        $roleRows = Layout::personal($template['rows'], $role, [], 'none');
        $placed = [];
        foreach ($roleRows as $row) foreach ($row['cols'] as $col) foreach ($col as $p) $placed[$p['b']] = $p;

        $hidden = array_values(array_filter(array_unique(array_map('strval', (array) ($data['hidden'] ?? []))),
            fn ($k) => isset($placed[$k]) && empty($placed[$k]['lock'])));

        $order = [];
        if ($rearrange === 'both') {
            foreach ($template['rows'] as $r => $row) {
                foreach ($row['cols'] as $c => $col) {
                    $keys = array_column($col, 'b');
                    $sent = array_values(array_filter(array_map('strval', (array) (($data['order'] ?? [])[$r . '-' . $c] ?? [])), fn ($k) => in_array($k, $keys, true)));
                    if ($sent && $sent !== array_values(array_intersect($keys, $sent))) {
                        $order[$r . '-' . $c] = array_values(array_unique($sent));
                    }
                }
            }
        }

        return array_filter(['hidden' => $hidden, 'order' => $order]);
    }
}
