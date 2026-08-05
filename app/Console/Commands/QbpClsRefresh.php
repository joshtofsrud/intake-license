<?php

// MARKER-QBP-CLS-CREDS

namespace App\Console\Commands;

use App\Models\PlatformDistributorConnection;
use App\Models\Tenant\TenantDistributorCatalogSubscription;
use App\Services\Distributors\DistributorRegistry;
use App\Services\Distributors\QbpClsClient;
use Illuminate\Console\Command;

/**
 * Fetch each QBP subscription's image service prefix and store it.
 *
 * The prefix embeds an Image Service ID unique to one QBP account, so it is
 * stored per subscription and never shared. It changes rarely — asking CLS on
 * every page render would be a call per image, which the licence would allow
 * and common sense would not.
 */
class QbpClsRefresh extends Command
{
    protected $signature = 'qbp:cls-refresh {--tenant= : Limit to one tenant id}';

    protected $description = "Fetch and store each tenant's QBP CLS image service URL and sizes.";

    public function handle(): int
    {
        $subs = TenantDistributorCatalogSubscription::query()
            ->where('distributor_code', 'QBP')
            ->where('is_active', true)
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', $this->option('tenant')))
            ->get();

        if ($subs->isEmpty()) {
            $this->warn('No active QBP subscriptions.');
        }

        $ok = 0;
        $skipped = 0;

        foreach ($subs as $sub) {
            $stored = $this->credential($sub);
            $cls = DistributorRegistry::clsKey($stored);

            if ($cls === '') {
                $this->line(sprintf('  %-38s no CLS key — images unavailable, everything else fine', $sub->tenant_id));
                $skipped++;
                continue;
            }

            $info = (new QbpClsClient($cls))->imageServiceInfo();

            if (! $info['ok']) {
                $this->error(sprintf('  %-38s %s', $sub->tenant_id, $info['error']));
                continue;
            }

            $sub->forceFill([
                'cls_image_url'   => $info['imageUrl'],
                'cls_image_sizes' => $info['imageSizes'],
                'cls_checked_at'  => now(),
            ])->save();

            $this->info(sprintf('  %-38s %s  (%d sizes)',
                $sub->tenant_id, $info['imageUrl'], count($info['imageSizes'])));
            $ok++;
        }

        // The platform key too, so master admin can investigate CLS without
        // it ever being the source for a tenant's storefront.
        $conn = PlatformDistributorConnection::where('distributor_code', 'QBP')->first();
        $platformCls = DistributorRegistry::clsKey($conn->api_key ?? null);

        if ($platformCls !== '') {
            $info = (new QbpClsClient($platformCls))->imageServiceInfo();
            $this->newLine();
            $this->line('platform key: ' . ($info['ok']
                ? $info['imageUrl'] . '  sizes: ' . implode(', ', $info['imageSizes'])
                : $info['error']));
        }

        $this->newLine();
        $this->line(sprintf('%d refreshed, %d without a CLS key.', $ok, $skipped));

        return self::SUCCESS;
    }

    /** Subscriptions store credentials encrypted; fall back gracefully. */
    private function credential(TenantDistributorCatalogSubscription $sub): string
    {
        $raw = $sub->credentials_encrypted;

        if (is_array($raw)) {
            return (string) ($raw['api_key'] ?? '');
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return (string) ($decoded['api_key'] ?? '');
            }
            return $raw;
        }
        return '';
    }
}
