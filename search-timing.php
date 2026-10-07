<?php
// Read-only. Times each step of a register search on the live database.
// Server:  cd /var/www/intake && runuser -u www-data -- env HOME=/tmp php /tmp/search-timing.php grndctrl "minion" "rock shox metric" "mi"
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$args = array_slice($argv, 1);
$sub = array_shift($args) ?: 'grndctrl';
$t = App\Models\Tenant::where('subdomain', $sub)->firstOrFail();
$nulls = DB::table('tenant_inventory_items')->where('tenant_id', $t->id)->whereNull('search_text')->count();
$total = DB::table('tenant_inventory_items')->where('tenant_id', $t->id)->count();
printf("%s: %d items, %d without search_text%s\n\n", $sub, $total, $nulls, $nulls > $total / 2 ? '  <-- run: php artisan inventory:search-text' : '');
$svc = app(App\Services\Tenant\RegisterProductSearch::class);
foreach ($args ?: ['minion', 'rock shox metric', 'mi'] as $q) {
    DB::enableQueryLog(); DB::flushQueryLog();
    $t0 = microtime(true);
    $b = App\Models\Tenant\TenantInventoryItem::where('tenant_id', $t->id)->where('is_active', true);
    $hit = App\Support\InventorySearch::apply($b, $t->id, $q);
    $t1 = microtime(true);
    $r = $svc->run($b, $t->id, null, $q, ['scope' => 'all']);
    $t2 = microtime(true);
    $log = DB::getQueryLog();
    printf("\"%s\": %d groups of %d rows  |  match %.0f ms, search+group %.0f ms, %d queries%s\n",
        $q, count($r['groups']), $r['total'], ($t1 - $t0) * 1000, ($t2 - $t1) * 1000, count($log), $hit['corrected'] ? "  corrected: {$hit['corrected']}" : '');
    usort($log, fn ($a, $b) => $b['time'] <=> $a['time']);
    foreach (array_slice($log, 0, 3) as $l) {
        printf("    %6.0f ms  %s\n", $l['time'], mb_substr(preg_replace('/\s+/', ' ', $l['query']), 0, 110));
    }
}
