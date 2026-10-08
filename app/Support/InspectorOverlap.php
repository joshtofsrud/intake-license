<?php

namespace App\Support;

/**
 * places the shared "Pull up over the section above"
 * control inside the section editor's Design tab. It used to be appended
 * after all the tab panels, so it showed under Content, Design and Advanced
 * alike, outside the panels' padding (slider against the edge).
 *
 * Order of preference: the Design ("style") panel, then Advanced, then —
 * for an editor with neither — appended as before.
 */
class InspectorOverlap
{
    public static function place(string $editorHtml, string $overlapHtml): string
    {
        foreach (['style', 'advanced'] as $tab) {
            $at = self::panelEnd($editorHtml, $tab);
            if ($at !== null) {
                return substr($editorHtml, 0, $at) . $overlapHtml . substr($editorHtml, $at);
            }
        }
        return $editorHtml . $overlapHtml;
    }

    /** Offset of the closing </div> of the first panel for $tab, or null. */
    private static function panelEnd(string $html, string $tab): ?int
    {
        if (! preg_match('/<div\b[^>]*\bclass="pb2-tab-panel"[^>]*\bdata-tab="' . preg_quote($tab, '/') . '"[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $pos   = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        while ($depth > 0 && preg_match('/<div\b|<\/div\s*>/i', $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
            $isClose = $t[0][0][1] === '/';
            if ($isClose) {
                $depth--;
                if ($depth === 0) return $t[0][1];
            } else {
                $depth++;
            }
            $pos = $t[0][1] + strlen($t[0][0]);
        }
        return null;
    }
}
