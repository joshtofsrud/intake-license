<?php

namespace App\Services\Tenant;

use App\Models\Tenant\TenantCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-CUST-CLEANUP — extracted from CustomerController so the master-admin
 * sweep and the shop's own customer screen remove customers by the SAME rules.
 * Two copies of "is it safe to delete this person" is how one screen deletes
 * what the other would have preserved.
 *
 * Delete outright only when nothing references the customer; otherwise erase
 * the personal data and hide the row, because sales and bookings still need it.
 */
class CustomerRemovalService
{
    /** Rows that would break if the customer row disappeared. */
    public function linkCounts(string $customerId): array
    {
        $tables = [
            'sales'          => 'tenant_sales',
            'appointments'   => 'tenant_appointments',
            'rentals'        => 'tenant_rentals',
            'orders'         => 'tenant_orders',
            'gift cards'     => 'tenant_gift_cards',
            'special orders' => 'tenant_special_orders',
            'assets'         => 'tenant_customer_assets',
        ];

        $out = [];
        foreach ($tables as $label => $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'customer_id')) {
                continue;
            }
            $out[$label] = (int) DB::table($table)->where('customer_id', $customerId)->count();
        }

        return $out;
    }

    /** 'delete' or 'erase' — what removal would actually do. */
    public function modeFor(string $customerId): string
    {
        return array_sum($this->linkCounts($customerId)) === 0 ? 'delete' : 'erase';
    }

    /**
     * @param string|null $byId  who did it, for the log
     * @param string      $via   'shop' or 'master-admin'
     * @return array{mode:string, links:array}
     */
    public function remove(TenantCustomer $customer, ?string $byId = null, string $via = 'shop'): array
    {
        $links = $this->linkCounts($customer->id);
        $total = array_sum($links);
        $id    = $customer->id;

        if ($total === 0) {
            DB::transaction(function () use ($customer) {
                DB::table('tenant_customer_contacts')->where('customer_id', $customer->id)->delete();
                $customer->delete();
            });

            logger()->info('customer deleted', [
                'tenant_id' => $customer->tenant_id, 'customer_id' => $id, 'by' => $byId, 'via' => $via,
            ]);

            return ['mode' => 'delete', 'links' => []];
        }

        DB::transaction(function () use ($customer) {
            // Blank everything that identifies a person. The id, its links and
            // the business records (stripe id, tax terms) stay: removing those
            // orphans refunds and breaks the books.
            $customer->forceFill([
                'first_name'                 => 'Erased',
                'last_name'                  => 'customer',
                'business_name'              => null,
                'email'                      => null,
                'phone'                      => null,
                'address_line1'              => null,
                'address_line2'              => null,
                'city'                       => null,
                'state'                      => null,
                'postcode'                   => null,
                'country'                    => null,
                'notes'                      => null,
                'wp_source_url'              => null,
                'password'                   => null,
                'remember_token'             => null,
                'email_verified_at'          => null,
                'password_reset_token'       => null,
                'password_reset_sent_at'     => null,
                'email_marketing_consent_at' => null,
                'email_marketing_opt_out_at' => now(),
                'erased_at'                  => now(),
            ])->save();

            DB::table('tenant_customer_contacts')->where('customer_id', $customer->id)->delete();
        });

        logger()->info('customer erased', [
            'tenant_id' => $customer->tenant_id, 'customer_id' => $id,
            'by' => $byId, 'via' => $via, 'links' => $links,
        ]);

        return ['mode' => 'erase', 'links' => array_filter($links)];
    }
}
