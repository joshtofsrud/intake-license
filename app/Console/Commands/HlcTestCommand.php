<?php
// MARKER-PATCH-HLC1

namespace App\Console\Commands;

use App\Services\Distributors\HlcClient;
use Illuminate\Console\Command;

/**
 * distributors:hlc-test — connectivity + live-shape probe for the HLC adapter.
 *
 * The fast unblock: the moment a real key exists, this confirms auth AND dumps
 * a live Products / Prices / Inventory sample, so we can lock the
 * PriceTypeId -> cost/MAP/MSRP mapping and confirm UPC coverage before the
 * sync patches are written.
 *
 *   php artisan distributors:hlc-test --key=XXXX --region=us
 *   php artisan distributors:hlc-test --key=XXXX --sku=500027-01 --sku=620185-05
 */
class HlcTestCommand extends Command
{
    protected $signature = 'distributors:hlc-test
        {--key= : HLC API key (falls back to HLC_API_KEY env)}
        {--region=us : HLC region (us|ca)}
        {--sku=* : One or more variant numbers / UPCs to sample}';

    protected $description = 'Probe the HLC API: connectivity, then dump a live product/price/inventory sample.';

    public function handle(): int
    {
        $key = (string) ($this->option('key') ?: env('HLC_API_KEY', ''));
        if ($key === '') {
            $this->error('No API key. Pass --key= or set HLC_API_KEY.');
            return self::FAILURE;
        }

        $client = new HlcClient($key, (string) $this->option('region'));

        $this->info('-> System/Echo (connectivity + auth)...');
        $echo = $client->testConnection();
        if (! ($echo['ok'] ?? false)) {
            $this->error('Echo failed: ' . json_encode($echo));
            return self::FAILURE;
        }
        $this->line('   ok · HTTP ' . ($echo['status'] ?? '?'));

        $this->info('-> Probing auth header styles against Catalog/Brands...');
        $styles = ['authorization_apikey', 'bare_apikey', 'authorization_bearer', 'authorization_raw'];
        $winner = null;
        foreach ($styles as $style) {
            $client->setAuthStyle($style);
            try {
                $brands = $client->brands();
                $n = is_countable($brands) ? count($brands) : 0;
                $this->line("   [{$style}] OK · brands: {$n}");
                $winner = $style;
                break;
            } catch (\Throwable $e) {
                $this->warn("   [{$style}] " . $e->getMessage());
            }
        }
        if ($winner === null) {
            $this->error('No auth header style worked. The key reaches HLC (Echo 200) but every catalog call 401s — this usually means the key needs catalog/API scope enabled on your HLC account. Contact your HLC rep.');
            return self::FAILURE;
        }
        $this->info("Working auth style: {$winner}  ->  set HLC_AUTH_STYLE={$winner} in .env (or tell me to bake it into config).");
        $client->setAuthStyle($winner);

        $skus = array_values(array_filter((array) $this->option('sku')));
        if (empty($skus)) {
            $this->info('No --sku given. Re-run with --sku=<variant/UPC> to dump a live sample.');
            return self::SUCCESS;
        }

        $this->info('-> Catalog/Products (first page · shape)...');
        try {
            $this->line($this->pretty($client->products(['pageSize' => 5])));
        } catch (\Throwable $e) {
            $this->warn('   Products failed: ' . $e->getMessage());
        }

        $this->info('-> Catalog/Products/Prices for ' . implode(', ', $skus) . ' (cost / MAP / MSRP mapping)...');
        try {
            $this->line($this->pretty($client->prices($skus)));
        } catch (\Throwable $e) {
            $this->warn('   Prices failed: ' . $e->getMessage());
        }

        $this->info('-> Catalog/Products/Inventory for ' . implode(', ', $skus) . ' (warehouse shape)...');
        try {
            $this->line($this->pretty($client->inventory($skus)));
        } catch (\Throwable $e) {
            $this->warn('   Inventory failed: ' . $e->getMessage());
        }

        // MARKER-PATCH-370 — image URL/path shape probe
        $this->info('-> Catalog/Products/Images for ' . implode(', ', $skus) . ' (image URL/path shape)...');
        try {
            $this->line($this->pretty($client->images($skus)));
        } catch (\Throwable $e) {
            $this->warn('   Images failed: ' . $e->getMessage());
        }

        $this->newLine();
        $this->info('Paste the Prices + Products + Images output back to lock the field mappings (incl. image URLs/paths).');
        return self::SUCCESS;
    }

    private function pretty(mixed $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
