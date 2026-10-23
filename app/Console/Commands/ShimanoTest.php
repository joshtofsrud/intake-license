<?php

namespace App\Console\Commands;

use App\Models\PlatformDistributorConnection;
use App\Services\Distributors\DistributorRegistry;
use App\Services\Distributors\ShimanoClient;
use Illuminate\Console\Command;

/**
 * Checks the Shimano SFTP login and host-key pin without touching any data.
 *
 *   php artisan shimano:test
 *   php artisan shimano:test --products   also downloads and parses the feed
 */
class ShimanoTest extends Command
{
    protected $signature = 'shimano:test {--products : also download the feed and count what it holds}';
    protected $description = 'Test the Shimano SFTP connection saved in master admin';

    public function handle(DistributorRegistry $registry): int
    {
        $conn = PlatformDistributorConnection::where('distributor_code', 'SHIMANO')->first();
        if (! $conn || blank($conn->api_key)) {
            $this->error('No Shimano login saved. Add it in master admin under Distributors.');
            return self::FAILURE;
        }

        /** @var ShimanoClient $client */
        $client = $registry->make('SHIMANO', ['api_key' => $conn->api_key, 'region' => $conn->region ?: 'us']);

        $res = $client->testConnection();
        $this->line(($res['ok'] ? 'OK    ' : 'FAILED') . '  ' . ($res['body'] ?? ''));
        if (! $res['ok']) {
            if (str_contains((string) $res['body'], 'curl exit 60')) {
                $this->warn('The server key did not match the pin. Your curl build may only accept the other form:');
                $this->warn('  SHIMANO_HOST_KEY_MD5=<md5 hex>   (ssh-keyscan -t ed25519 ftpsna.shimano.com | ssh-keygen -lf - -E md5)');
            }
            return self::FAILURE;
        }

        if ($this->option('products')) {
            $brands = $client->brands();   // refreshes the feed
            $this->info(count($brands) . ' brands: ' . implode(', ', array_column($brands, 'name')));
            $n = 0; $v = 0;
            foreach ($client->products()['Products'] as $p) { $n++; $v += count($p['Variants']); }
            $this->info("{$n} products, {$v} SKUs");
        }
        return self::SUCCESS;
    }
}
