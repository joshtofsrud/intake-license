<?php
// MARKER-IMPORT-PHONE

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Bring already-stored customer phone numbers onto one shape.
 *
 * Dry run by default: it reports what would change and shows examples, and
 * only writes when --write is passed. Rewriting a customer table is not
 * something that should happen because someone ran a command to look.
 */
class NormalizeCustomerPhones extends Command
{
    protected $signature = 'customers:normalize-phones
                            {--tenant= : Limit to one tenant id}
                            {--write : Actually save the changes}
                            {--samples=8 : How many examples to show}';

    protected $description = 'Normalize stored customer phone numbers (dry run unless --write)';

    public function handle(): int
    {
        $write   = (bool) $this->option('write');
        $samples = max(0, (int) $this->option('samples'));

        $query = TenantCustomer::query()
            ->whereNotNull('phone')->where('phone', '!=', '');

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', $tenantId);
        }

        $checked = 0;
        $changed = 0;
        $unusable = 0;
        $shown   = [];

        $query->orderBy('id')->chunkById(500, function ($rows) use (
            $write, $samples, &$checked, &$changed, &$unusable, &$shown
        ) {
            foreach ($rows as $c) {
                $checked++;
                $current    = (string) $c->phone;
                $normalized = PhoneNumber::normalize($current);

                if ($normalized === null) {
                    // Can't make sense of it — leave it exactly as it is.
                    $unusable++;
                    continue;
                }

                if ($normalized === $current) {
                    continue;
                }

                $changed++;
                if (count($shown) < $samples) {
                    $shown[] = [$current, $normalized];
                }

                if ($write) {
                    // Update quietly: no touching timestamps, since this is a
                    // format correction and not a change the customer made.
                    TenantCustomer::where('id', $c->id)->update(['phone' => $normalized]);
                }
            }
        });

        $this->newLine();
        $this->line("Checked:  {$checked}");
        $this->line("Would change: {$changed}" . ($write ? '  (WRITTEN)' : '  (dry run)'));
        $this->line("Left alone (unreadable): {$unusable}");

        if ($shown) {
            $this->newLine();
            $this->line('Examples:');
            foreach ($shown as [$from, $to]) {
                $this->line("  {$from}  ->  {$to}");
            }
        }

        if (! $write && $changed > 0) {
            $this->newLine();
            $this->line('Nothing was saved. Re-run with --write to apply.');
        }

        return self::SUCCESS;
    }
}
