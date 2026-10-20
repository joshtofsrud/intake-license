<?php

namespace App\Support;

/**
 * MARKER-SEC-UPLOADS: strips anything executable from an SVG before it is
 * stored. Uploaded files are served from the app's own host, so a script in
 * an SVG opened directly would run as the signed-in user.
 * Removes: script / foreignObject / iframe / embed / object / handler
 * attributes (on*), and links or styles that call javascript:, data:text/html
 * or vbscript:. Returns null when the file isn't readable SVG.
 */
class SvgSanitizer
{
    private const DROP = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'handler', 'listener'];

    public static function clean(string $svg): ?string
    {
        if (stripos($svg, '<svg') === false) return null;
        if (preg_match('/<!ENTITY/i', $svg)) return null; // no entity tricks
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok || ! $doc->documentElement || strtolower($doc->documentElement->localName) !== 'svg') return null;

        $bad = '/^\s*(javascript|vbscript|data\s*:\s*text\/html)/i';
        $xp = new \DOMXPath($doc);
        $remove = [];
        foreach ($xp->query('//*') as $el) {
            if (in_array(strtolower($el->localName), self::DROP, true)) { $remove[] = $el; continue; }
            $drop = [];
            foreach ($el->attributes as $a) {
                $n = strtolower($a->localName);
                $v = preg_replace('/[\x00-\x20]+/', '', html_entity_decode((string) $a->value));
                if (str_starts_with($n, 'on')) $drop[] = $a;
                elseif (in_array($n, ['href', 'src', 'action', 'formaction', 'to', 'from', 'values'], true) && preg_match($bad, $v)) $drop[] = $a;
                elseif ($n === 'style' && preg_match('/(javascript:|expression\s*\(|url\s*\(\s*["\']?\s*javascript)/i', $v)) $drop[] = $a;
            }
            foreach ($drop as $a) $el->removeAttributeNode($a);
            if (strtolower($el->localName) === 'style' && preg_match('/(javascript:|expression\s*\(|@import)/i', $el->textContent)) $remove[] = $el;
        }
        foreach ($remove as $el) if ($el->parentNode) $el->parentNode->removeChild($el);
        return $doc->saveXML($doc->documentElement);
    }
}
