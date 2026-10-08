<?php

namespace App\Support;

/**
 * unsaved inspector edits, held in the editor's own
 * session so the builder preview can show them before Save. Nothing here is
 * ever written to the page: Save writes the section and forgets its draft,
 * Revert and opening the editor clear them. Visitors and other staff never
 * see a draft — it lives in one person's session and only the builder
 * preview reads it.
 */
class BuilderDraft
{
    private const KEY = 'pb2_draft';
    private const MAX_BYTES = 200000;

    public static function put(string $pageId, string $sectionId, array $content): void
    {
        if (strlen((string) json_encode($content)) > self::MAX_BYTES) return;
        $all = session()->get(self::KEY, []);
        $all[$pageId][$sectionId] = $content;
        session()->put(self::KEY, $all);
    }

    public static function forget(string $pageId, string $sectionId): void
    {
        $all = session()->get(self::KEY, []);
        unset($all[$pageId][$sectionId]);
        session()->put(self::KEY, $all);
    }

    // unsaved menu rows for the builder preview.
    public static function putNav(string $pageId, array $rows): void
    {
        self::put($pageId, '__nav', ['rows' => array_slice($rows, 0, 40)]);
    }

    public static function navRows(string $pageId): ?array
    {
        $d = session()->get(self::KEY, [])[$pageId]['__nav'] ?? null;
        return is_array($d) && isset($d['rows']) && is_array($d['rows']) ? $d['rows'] : null;
    }

    public static function clear(string $pageId): void
    {
        $all = session()->get(self::KEY, []);
        unset($all[$pageId]);
        session()->put(self::KEY, $all);
    }

    /** Lay this session's drafts over the sections (in memory only). */
    public static function apply($sections, string $pageId)
    {
        $drafts = session()->get(self::KEY, [])[$pageId] ?? [];
        if (! $drafts) return $sections;
        foreach ($sections as $s) {
            $d = $drafts[(string) $s->id] ?? null;
            if (is_array($d)) {
                $s->content = array_merge((array) ($s->content ?? []), $d);
            }
        }
        return $sections;
    }
}
