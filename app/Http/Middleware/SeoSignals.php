<?php

namespace App\Http\Middleware;

use App\Support\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * adds search signals to public responses without
 * touching any view:
 *   - X-Robots-Tag: noindex on everything search engines should not index
 *   - <link rel="canonical"> on indexable HTML pages
 *   - JSON-LD structured data on the shop home page and product pages,
 *     and Organization data on the intake.works home page
 * Decisions come from App\Support\Seo. A failure here never breaks the page.
 */
class SeoSignals
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            return $this->apply($request, $response);
        } catch (\Throwable $e) {
            report($e);
            return $response;
        }
    }

    private function apply(Request $request, Response $response): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $response;
        }

        // robots.txt and sitemaps carry no page signals of their own.
        $name = (string) optional($request->route())->getName();
        if (str_ends_with($name, '.robots') || str_contains($name, 'sitemap')) {
            return $response;
        }

        $v = Seo::verdict($request);

        if (! $v['index']) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            return $response;
        }

        if ($response->getStatusCode() !== 200
            || $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse
            || stripos((string) $response->headers->get('Content-Type', ''), 'text/html') === false) {
            return $response;
        }

        $html = (string) $response->getContent();
        $at   = stripos($html, '</head>');
        if ($at === false) {
            return $response;
        }

        $inject = '';
        if ($v['canonical'] && ! preg_match('/<link[^>]+rel=["\']canonical["\']/i', substr($html, 0, $at))) {
            $inject .= '<link rel="canonical" href="' . e($v['canonical']) . '">' . "\n";
        }
        if ($v['jsonld']) {
            $inject .= '<script type="application/ld+json">'
                . json_encode($v['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
                . '</script>' . "\n";
        }
        if ($inject === '') {
            return $response;
        }

        $response->setContent(substr_replace($html, $inject, $at, 0));
        $response->headers->remove('Content-Length');

        return $response;
    }
}
