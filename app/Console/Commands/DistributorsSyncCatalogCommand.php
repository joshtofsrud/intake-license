<?php
// MARKER-PATCH-HLC3

namespace App\Console\Commands;

use App\Services\Distributors\DistributorCatalogSyncService;
use App\Services\Distributors\DistributorRegistry;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DistributorsSyncCatalogCommand extends Command
{
    protected $signature = 'distributors:sync-catalog
        {code=HLC : Distributor code}
        {--key= : API key (falls back to {CODE}_API_KEY env)}
        {--since= : Only sync variants modified after this date}
        {--delta : Use the stored watermark as --since}
        {--page-size=100}
        {--max-pages=2000}';

    protected $description = 'Tier-1 identity sync: pull a distributor catalog into platform_distributor_catalogs via the editable field map.';

    public function handle(DistributorRegistry $registry, DistributorCatalogSyncService $sync): int
    {
        $code = strtoupper((string) $this->argument('code'));

        $key = (string) ($this->option('key')
            ?: config('distributors.' . strtolower($code) . '.api_key')
            ?: env($code . '_API_KEY', ''));

        if ($key === '') {
            $this->error("No API key. Pass --key= or set {$code}_API_KEY in .env.");
            return self::FAILURE;
        }

        try {
            $adapter = $registry->make($code, ['api_key' => $key, 'region' => 'us']);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $since = null;
        if ($this->option('since')) {
            $since = Carbon::parse((string) $this->option('since'));
        } elseif ($this->option('delta')) {
            $state = DB::table('distributor_sync_state')
                ->where('distributor_code', $code)->where('source_ref', 'catalog')->first();
            if ($state?->last_synced_at) {
                $since = Carbon::parse($state->last_synced_at);
            }
        }

        $this->info("Syncing {$code} catalog " . ($since ? "(delta since {$since})" : '(full)') . ' ...');

        $res = $sync->syncIdentity(
            $adapter,
            $since,
            (int) $this->option('page-size'),
            (int) $this->option('max-pages'),
        );

        $this->table(
            ['metric', 'value'],
            collect($res)->except('errors')->map(fn ($v, $k) => [$k, is_array($v) ? json_encode($v) : $v])->values()->all()
        );

        if (! empty($res['errors'])) {
            $this->warn(count($res['errors']) . ' error(s) (first 5):');
            foreach (array_slice($res['errors'], 0, 5) as $e) {
                $this->line("  - {$e}");
            }
        }

        return self::SUCCESS;
    }
}
