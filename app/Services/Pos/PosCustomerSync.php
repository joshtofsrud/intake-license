<?php

namespace App\Services\Pos;

use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\Adapters\PosAdapterRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MARKER-POS-CUSTOMERS-IN: customers from the Connected POS into Intake.
 *
 * Each one is matched to an Intake customer by its link (made on an earlier run or when an
 * online order created it there), then by email, then by phone. A match only has its BLANK
 * fields filled; nothing a shop typed into Intake is overwritten. No match: a new customer.
 * Nobody is opted in to marketing; "do not email" there becomes opted out here. Deleted
 * customers there are left alone here. Paged and resumable, like the product import.
 */
class PosCustomerSync
{
    private const PAGES_PER_STEP = 5;

    public function __construct(private PosAdapterRegistry $registry, private PosConnectionService $conns) {}

    public function start(TenantPosConnection $conn, bool $full): void
    {
        $st = (array) ($conn->customer_sync ?? []);
        $st = array_merge($st, [
            'running' => true, 'stopped' => false, 'full' => $full, 'error' => null,
            'started_at' => now()->toIso8601String(), 'finished_at' => null,
            'pages' => 0, 'seen' => 0, 'matched' => 0, 'created' => 0, 'filled' => 0, 'skipped' => 0,
        ]);
        if ($full) {
            $st['cursor'] = null;
        }
        $conn->forceFill(['customer_sync' => $st])->save();
    }

    /** One step. True when there is more to do. */
    public function step(TenantPosConnection $conn): bool
    {
        $st = (array) $conn->customer_sync;
        $adapter = $this->registry->make($conn->adapter_code);
        $ctx = (array) ($conn->context ?? []);

        for ($i = 0; $i < self::PAGES_PER_STEP; $i++) {
            $page = $adapter->pullCustomers($this->conns->accessToken($conn), (string) $conn->external_account_id, $ctx, $st['cursor'] ?? null);
            foreach ($page['customers'] as $c) {
                $this->apply($conn, $c, $st);
            }
            $st['seen'] += count($page['customers']);
            $st['cursor'] = $page['cursor'];
            $st['pages']++;
            $fresh = $conn->fresh();
            if (($fresh->customer_sync['stopped'] ?? false)) {
                // stopped from the tab mid-step: keep the place, stop here
                $st['running'] = false; $st['stopped'] = true;
                $conn->forceFill(['customer_sync' => $st])->save();
                return false;
            }
            if (! $page['more']) {
                $st['running'] = false;
                $st['finished_at'] = now()->toIso8601String();
                $st['last_done'] = $st['finished_at'];
                $conn->forceFill(['customer_sync' => $st])->save();
                return false;
            }
            $conn->forceFill(['customer_sync' => $st])->save();
        }

        return true;
    }

    public function fail(TenantPosConnection $conn, string $message): void
    {
        $st = (array) ($conn->customer_sync ?? []);
        $st['running'] = false;
        $st['error'] = Str::limit($message, 400);
        $st['finished_at'] = now()->toIso8601String();
        $conn->forceFill(['customer_sync' => $st])->save();
    }

    /** Phone digits for matching: the last ten, so +1 and formatting don't matter. */
    public static function phoneKey(?string $p): string
    {
        $d = preg_replace('/\D+/', '', (string) $p);
        return strlen($d) >= 7 ? substr($d, -10) : '';
    }

    private function apply(TenantPosConnection $conn, array $c, array &$st): void
    {
        if (! empty($c['deleted'])) {
            $st['skipped']++;
            return;
        }
        $email = mb_strtolower(trim((string) ($c['email'] ?? '')));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }
        $phoneKey = self::phoneKey($c['phone'] ?? '');
        $first = trim((string) ($c['first_name'] ?? ''));
        $last  = trim((string) ($c['last_name'] ?? ''));
        if ($email === '' && $phoneKey === '' && $first === '' && $last === '') {
            $st['skipped']++; // nothing to know them by
            return;
        }
        $tid = $conn->tenant_id;

        $customer = null;
        $linkedId = DB::table('tenant_pos_customers')->where('tenant_id', $tid)->where('external_id', $c['id'])->value('customer_id');
        if ($linkedId) {
            $customer = TenantCustomer::where('tenant_id', $tid)->find($linkedId);
        }
        if (! $customer && $email !== '') {
            $customer = TenantCustomer::where('tenant_id', $tid)->whereRaw('LOWER(email) = ?', [$email])->first();
        }
        if (! $customer && $phoneKey !== '') {
            foreach (TenantCustomer::where('tenant_id', $tid)->where('phone', 'like', '%' . substr($phoneKey, -4))->limit(50)->get() as $cand) {
                if (self::phoneKey($cand->phone) === $phoneKey) { $customer = $cand; break; }
            }
        }

        $fields = [
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => trim((string) ($c['phone'] ?? '')),
            'address_line1' => $c['address1'] ?? '', 'address_line2' => $c['address2'] ?? '', 'city' => $c['city'] ?? '',
            'state' => $c['state'] ?? '', 'postcode' => $c['postcode'] ?? '', 'country' => $c['country'] ?? '',
        ];

        if ($customer) {
            $fill = [];
            foreach ($fields as $k => $v) {
                if (trim((string) $v) !== '' && trim((string) ($customer->{$k} ?? '')) === '') {
                    $fill[$k] = $v;
                }
            }
            if (! empty($c['no_marketing']) && ! $customer->email_marketing_opt_out_at) {
                $fill['email_marketing_opt_out_at'] = now();
            }
            if ($fill) {
                $customer->forceFill($fill)->save();
                $st['filled']++;
            }
            $st['matched']++;
        } else {
            $data = ['tenant_id' => $tid] + array_map(fn ($v) => trim((string) $v) !== '' ? $v : null, $fields);
            if ($data['first_name'] === null && $data['last_name'] === null) {
                $data['first_name'] = ($email !== '' ? strstr($email, '@', true) : '') ?: 'Customer';
            }
            $customer = TenantCustomer::create($data);
            if (! empty($c['no_marketing'])) {
                $customer->forceFill(['email_marketing_opt_out_at' => now()])->save();
            }
            $st['created']++;
        }

        // one link per Intake customer; a second Connected POS record for the same person
        // points at the same customer rather than making a duplicate
        $has = DB::table('tenant_pos_customers')->where('tenant_id', $tid)->where('customer_id', $customer->id)->first();
        if ($has) {
            if (! $has->external_id || $has->external_id === $c['id']) {
                DB::table('tenant_pos_customers')->where('id', $has->id)->update(['external_id' => $c['id'], 'email' => $email ?: $has->email, 'updated_at' => now()]);
            }
        } else {
            DB::table('tenant_pos_customers')->insert(['tenant_id' => $tid, 'customer_id' => $customer->id, 'external_id' => $c['id'], 'email' => $email ?: null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
