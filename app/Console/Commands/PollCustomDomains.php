<?php

namespace App\Console\Commands;

use App\Models\Tenant\TenantDomain;
use App\Services\DomainProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * domains:poll
 *
 * Walks non-terminal domain rows through their lifecycle by syncing
 * state from Cloudflare. Designed to be scheduled every minute.
 *
 * Per-domain failures don't kill the batch — one bad domain shouldn't
 * prevent others from progressing.
 *
 * Polling cadence (per design spec):
 *   pending_dns   - every 60s for 1h, then every 5m
 *   verifying     - every 30s for 10m, then every 5m
 *   issuing_cert  - every 5m (CF webhook is primary signal)
 *   active        - every 4h (sanity check)
 *
 * This command runs every minute and uses `last_check_at` to decide
 * whether each row is ready to be re-checked.
 */
class PollCustomDomains extends Command
{
    protected $signature = 'domains:poll
                            {--limit=50 : Max domains to check per run}
                            {--force : Ignore last_check_at backoff}';

    protected $description = 'Poll Cloudflare for custom domain state changes.';

    public function handle(DomainProvisioningService $provisioning): int
    {
        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');

        // Find domains that need a check. We only look at non-terminal
        // states; 'suspended' is admin-only and we never auto-poll it.
        $query = TenantDomain::query()
            ->whereIn('status', ['pending_dns', 'verifying', 'issuing_cert', 'active', 'error']);

        if (!$force) {
            $query->where(function ($q) {
                $now = now();
                // pending_dns / verifying: aggressive (60s/30s after creation, 5m after 1h/10m)
                $q->orWhere(function ($sub) use ($now) {
                    $sub->whereIn('status', ['pending_dns', 'verifying'])
                        ->where(function ($w) use ($now) {
                            $w->whereNull('last_check_at')
                              ->orWhere('last_check_at', '<=', $now->copy()->subSeconds(30));
                        });
                });
                // issuing_cert: every 5 minutes (webhook is primary)
                $q->orWhere(function ($sub) use ($now) {
                    $sub->where('status', 'issuing_cert')
                        ->where(function ($w) use ($now) {
                            $w->whereNull('last_check_at')
                              ->orWhere('last_check_at', '<=', $now->copy()->subMinutes(5));
                        });
                });
                // active: sanity check every 4 hours
                $q->orWhere(function ($sub) use ($now) {
                    $sub->where('status', 'active')
                        ->where(function ($w) use ($now) {
                            $w->whereNull('last_check_at')
                              ->orWhere('last_check_at', '<=', $now->copy()->subHours(4));
                        });
                });
                // error: retry every 10 minutes, up to a budget elsewhere
                $q->orWhere(function ($sub) use ($now) {
                    $sub->where('status', 'error')
                        ->where(function ($w) use ($now) {
                            $w->whereNull('last_check_at')
                              ->orWhere('last_check_at', '<=', $now->copy()->subMinutes(10));
                        });
                });
            });
        }

        $domains = $query->orderBy('last_check_at')->limit($limit)->get();

        if ($domains->isEmpty()) {
            $this->info('No domains due for polling.');
            return self::SUCCESS;
        }

        $checked  = 0;
        $changed  = 0;
        $errored  = 0;

        foreach ($domains as $domain) {
            try {
                $didChange = $provisioning->syncFromCloudflare($domain);
                $checked++;
                if ($didChange) {
                    $changed++;
                    $this->line("  → {$domain->hostname}: now {$domain->fresh()->status}");
                }
            } catch (\Throwable $e) {
                $errored++;
                Log::error('[domains:poll] unhandled error per-domain', [
                    'hostname' => $domain->hostname,
                    'error'    => $e->getMessage(),
                ]);
                // Continue to next domain.
            }
        }

        $this->info("Checked {$checked}, {$changed} changed, {$errored} errored.");
        return self::SUCCESS;
    }
}
