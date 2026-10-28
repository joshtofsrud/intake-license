<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\Tenant\TenantUser;

/**
 * MARKER-NOTE-MENTIONS: @someone in a work order note.
 *
 * Notes stay plain text; a mention is "@" followed by a staff member's full
 * name (what the picker inserts), or their first name alone when nobody else
 * at the shop shares it (what people type by hand). Each person mentioned
 * gets one alert in their bell, never the author. Matching is
 * case-insensitive and needs a non-letter after the name, so "@Sam" doesn't
 * catch "Samantha".
 */
class NoteMentions
{
    /** Active staff at the shop: [id => name]. */
    public static function people(Tenant $tenant): array
    {
        return TenantUser::where('tenant_id', $tenant->id)->where('is_active', true)
            ->whereNotNull('name')->where('name', '!=', '')
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Every way each person can be written after "@", longest first so
     * "Sam Jones" wins over "Sam": [[label, id], ...].
     */
    public static function handles(array $people): array
    {
        $firsts = [];
        foreach ($people as $id => $name) {
            $f = mb_strtolower(strtok(trim($name), ' ') ?: '');
            if ($f !== '') $firsts[$f][] = $id;
        }
        $out = [];
        foreach ($people as $id => $name) {
            $name = trim(preg_replace('/\s+/u', ' ', $name));
            $out[] = [$name, $id];
            $first = strtok($name, ' ');
            if ($first && $first !== $name && count($firsts[mb_strtolower($first)] ?? []) === 1) $out[] = [$first, $id];
        }
        usort($out, fn ($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return $out;
    }

    /** Ids of the people mentioned in $text. */
    public static function find(string $text, array $people): array
    {
        $found = [];
        foreach (self::handles($people) as [$label, $id]) {
            if (isset($found[$id])) continue;
            if (preg_match(self::pattern($label), $text)) $found[$id] = true;
        }

        return array_keys($found);
    }

    /** The note as HTML: escaped, with mentions marked. */
    public static function html(?string $text, array $people): string
    {
        $html = e((string) $text);
        if ($people === [] || ! str_contains($html, '@')) return $html;

        // one pass over the escaped text, so a longer name can't be re-wrapped by a shorter one
        $alts = array_map(fn ($h) => preg_quote(e($h[0]), '/'), self::handles($people));
        if (! $alts) return $html;

        return preg_replace_callback('/(^|[^\pL\pN_])@(' . implode('|', $alts) . ')(?![\pL\pN_])/iu',
            fn ($m) => $m[1] . '<span class="ia-mention">@' . $m[2] . '</span>', $html) ?? $html;
    }

    /**
     * Alert everyone mentioned in a work order note, except whoever wrote it.
     * Returns the ids alerted.
     */
    public static function notifyForAppointment(Tenant $tenant, $appointment, $note, ?object $author): array
    {
        $people = self::people($tenant);
        $ids = array_values(array_diff(self::find((string) $note->note_content, $people), [$author?->id]));
        if (! $ids) return [];

        $who = trim((string) ($author->name ?? '')) ?: 'Someone';
        $ref = $appointment->ra_number ?: 'a work order';
        $snippet = trim(preg_replace('/\s+/u', ' ', (string) $note->note_content));
        $snippet = mb_strlen($snippet) > 140 ? mb_substr($snippet, 0, 139) . '…' : $snippet;
        $link = route('tenant.appointments.show', $appointment->id) . '#note-' . $note->id;

        $alerts = app(\App\Services\Tenant\StaffAlertService::class);
        foreach ($ids as $id) {
            $alerts->emit($tenant, 'note.mention', [
                'title'        => $who . ' mentioned you on ' . $ref,
                'body'         => $snippet,
                'link'         => $link,
                'meta'         => ['appointment_id' => $appointment->id, 'note_id' => $note->id],
                'only_user_id' => $id,
            ]);
        }

        return $ids;
    }

    private static function pattern(string $label): string
    {
        return '/(^|[^\pL\pN_])@' . preg_quote($label, '/') . '(?![\pL\pN_])/iu';
    }
}
