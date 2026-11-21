<?php

namespace App\Services\SiteHealth;

/** MARKER-SITE-HEALTH: reads one public page the way a search engine does. No network here. */
class PageReader
{
    /** @return array{title:string,description:string,canonical:?string,noindex:bool,links:array,phones:array} */
    public static function read(string $html): array
    {
        $out = ['title' => '', 'description' => '', 'canonical' => null, 'noindex' => false, 'links' => [], 'phones' => []];
        if (trim($html) === '') {
            return $out;
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $t = $dom->getElementsByTagName('title')->item(0);
        $out['title'] = $t ? self::squash($t->textContent) : '';

        foreach ($dom->getElementsByTagName('meta') as $m) {
            $name = strtolower((string) $m->getAttribute('name'));
            if ($name === 'description') {
                $out['description'] = self::squash($m->getAttribute('content'));
            } elseif ($name === 'robots' && stripos($m->getAttribute('content'), 'noindex') !== false) {
                $out['noindex'] = true;
            }
        }
        foreach ($dom->getElementsByTagName('link') as $l) {
            if (strtolower((string) $l->getAttribute('rel')) === 'canonical' && $l->getAttribute('href') !== '') {
                $out['canonical'] = $l->getAttribute('href');
            }
        }
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            if (stripos($href, 'tel:') === 0) {
                $digits = preg_replace('/\D+/', '', substr($href, 4));
                if (strlen($digits) >= 7) {
                    $out['phones'][$digits] = trim(substr($href, 4));
                }
                continue;
            }
            $text = self::squash($a->textContent);
            if ($text === '') {
                $img = $a->getElementsByTagName('img')->item(0);
                $text = $img ? self::squash($img->getAttribute('alt')) : '';
            }
            $out['links'][] = ['href' => $href, 'text' => mb_substr($text, 0, 80)];
        }

        return $out;
    }

    /**
     * A link's path on this site, or null when it leaves the site, is not a page
     * (mail, phone, script, a jump within the page) or goes somewhere never public.
     */
    public static function internalPath(string $href, string $host): ?string
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#' || preg_match('/^(mailto:|tel:|sms:|javascript:|data:)/i', $href)) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }
        if (preg_match('#^https?://#i', $href)) {
            $h = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($h !== strtolower($host) && $h !== 'www.' . strtolower($host)) {
                return null;
            }
            $path = (string) (parse_url($href, PHP_URL_PATH) ?: '/');
        } elseif ($href[0] === '/') {
            $path = (string) (parse_url($href, PHP_URL_PATH) ?: '/');
        } else {
            return null; // a relative link: the builder never writes these
        }
        $path = '/' . trim($path, '/');
        foreach (['/admin', '/account', '/cart', '/checkout', '/pay-display', '/d/', '/storage', '/media', '/build', '/css', '/js'] as $skip) {
            if ($path === rtrim($skip, '/') || str_starts_with($path, rtrim($skip, '/') . '/')) {
                return null;
            }
        }
        if (preg_match('/\.(png|jpe?g|gif|webp|svg|pdf|ics|xml|txt|zip|css|js)$/i', $path)) {
            return null;
        }

        return $path;
    }

    public static function squash(?string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
