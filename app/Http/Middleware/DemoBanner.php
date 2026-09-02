<?php

namespace App\Http\Middleware;

use App\Models\DemoSetting;
use Closure;
use Illuminate\Http\Request;

/**
 * MARKER-DEMO-RESET — everything a demo visitor cannot see for themselves, said
 * in plain words on every page: nothing they send actually sends, and the whole
 * thing resets on the hour. Appended globally so the staff side, the public
 * site and the booking flow all carry it without touching three layouts.
 */
class DemoBanner
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = tenant();
        if (! $tenant || ! $tenant->is_demo) {
            return $next($request);
        }

        $slug  = $tenant->subdomain ?: 'demo';
        $epoch = (int) DemoSetting::get("epoch:{$slug}", '0');

        // a session that predates the last reset belongs to data that no longer
        // exists — start them over rather than showing half a world
        if ($epoch && $request->hasSession() && $request->session()->has('demo_epoch')
            && (int) $request->session()->get('demo_epoch') !== $epoch
            && ! $request->ajax() && $request->isMethod('GET')) {
            $request->session()->flush();
            return redirect('/?demo_reset=1');
        }
        if ($epoch && $request->hasSession()) {
            $request->session()->put('demo_epoch', $epoch);
        }

        $response = $next($request);

        $ct = (string) $response->headers->get('Content-Type');
        if (! str_contains($ct, 'text/html') || $request->ajax()) {
            return $response;
        }
        $content = $response->getContent();
        if (! is_string($content) || ! str_contains($content, '</body>')) {
            return $response;
        }

        $pos = strrpos($content, '</body>');
        $response->setContent(substr($content, 0, $pos) . $this->banner($tenant) . substr($content, $pos));
        return $response;
    }

    private function banner($tenant): string
    {
        $label = e(DemoSetting::get('label:' . ($tenant->subdomain ?: 'demo'), 'Bike shop demo'));
        $reset = (bool) request()->query('demo_reset');
        $note  = $reset ? '<b>Just reset — you are starting fresh.</b> ' : '';

        return <<<HTML
<style>
  /* MARKER-DEMO-BAR-MOBILE — desktop: bottom, where nothing else sits. */
  .demo-bar{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;background:#111;color:#f0f0f0;
    border-top:2px solid #BEF264;font:13px/1.4 Inter,system-ui,sans-serif;padding:9px 14px;
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;box-shadow:0 -6px 24px rgba(0,0,0,.35)}
  .demo-bar b{color:#fff}
  .demo-bar .tag{background:#BEF264;color:#0a0a0a;font-weight:700;border-radius:5px;padding:2px 8px;font-size:11.5px;flex:none}
  .demo-bar .cd{margin-left:auto;font-variant-numeric:tabular-nums;opacity:.85;flex:none}
  .demo-bar .cd.soon{color:#ffb4b4}
  @media (min-width:1024px){ body{padding-bottom:52px} }
  /* Phones and tablets: the bottom belongs to the app's own tab bar
     (fixed, bottom:0, z-index:100, with 72px of body padding reserved for it),
     so the bar goes to the top and reserves its own room there instead. */
  @media (max-width:1023px){
    .demo-bar{top:0;bottom:auto;border-top:0;border-bottom:2px solid #BEF264;
      box-shadow:0 6px 24px rgba(0,0,0,.35);padding-top:calc(9px + env(safe-area-inset-top, 0px))}
    body{padding-top:calc(46px + env(safe-area-inset-top, 0px))}
  }
  @media (max-width:640px){.demo-bar{font-size:12px}.demo-bar .cd{margin-left:0;width:100%}
    body{padding-top:calc(66px + env(safe-area-inset-top, 0px))}}
</style>
<div class="demo-bar" role="status">
  <span class="tag">{$label}</span>
  <span>{$note}This is a demo — emails and texts are never really sent. Everything resets on the hour.</span>
  <span class="cd" data-demo-countdown>calculating…</span>
</div>
<script>
(function () {
  var el = document.querySelector('[data-demo-countdown]');
  if (!el) return;
  function tick() {
    var now = new Date();
    var next = new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours() + 1, 0, 5);
    var left = Math.max(0, Math.floor((next - now) / 1000));
    if (left <= 0) { location.reload(); return; }
    var m = Math.floor(left / 60), s = left % 60;
    el.textContent = m >= 1 ? ('Resets in ' + m + ' min') : ('Resets in ' + s + ' sec');
    el.classList.toggle('soon', left <= 60);
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
HTML;
    }
}
