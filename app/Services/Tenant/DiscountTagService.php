<?php
// MARKER-PROMO-TAGS

namespace App\Services\Tenant;

use App\Models\Tenant\TenantCustomerTag;
use App\Models\Tenant\TenantDiscount;
use App\Models\Tenant\TenantDiscountRedemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The bridge from a redeemed code to a tag on the customer. Everything here
 * is idempotent: attaching twice is one row (unique on tag+customer),
 * detaching a tag that isn't there is a no-op.
 */
class DiscountTagService
{
    /** Tag ids configured on a discount. */
    public function tagIdsFor(string $discountId): array
    {
        return DB::table('tenant_discount_tags')->where('discount_id', $discountId)->pluck('tag_id')->all();
    }

    /** Tag names for display, keyed by discount id, one query. */
    public function namesByDiscount(string $tenantId): array
    {
        $out = [];
        DB::table('tenant_discount_tags as dt')
            ->join('tenant_customer_tags as t', 't.id', '=', 'dt.tag_id')
            ->where('dt.tenant_id', $tenantId)
            ->orderBy('t.name')
            ->get(['dt.discount_id', 't.name'])
            ->each(function ($r) use (&$out) { $out[$r->discount_id][] = $r->name; });
        return $out;
    }

    /**
     * Set the discount's tags from a comma-separated list of names, creating
     * any that don't exist. Returns the resolved tag ids. New tags are
     * backfilled onto every customer who already redeemed the code.
     */
    public function setTags(TenantDiscount $discount, string $csv, ?string $userId): array
    {
        $names = collect(explode(',', $csv))
            ->map(fn ($n) => trim(preg_replace('/\s+/', ' ', $n)))
            ->filter(fn ($n) => $n !== '')
            ->map(fn ($n) => Str::limit($n, 60, ''))
            ->unique(fn ($n) => mb_strtolower($n))
            ->values();

        $ids = [];
        foreach ($names as $name) {
            $tag = TenantCustomerTag::where('tenant_id', $discount->tenant_id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();
            if (! $tag) {
                $tag = TenantCustomerTag::create([
                    'tenant_id'  => $discount->tenant_id,
                    'name'       => $name,
                    'created_by' => $userId,
                ]);
            }
            $ids[] = $tag->id;
        }

        $before = $this->tagIdsFor($discount->id);
        $added  = array_values(array_diff($ids, $before));
        $gone   = array_values(array_diff($before, $ids));

        DB::transaction(function () use ($discount, $ids, $added, $gone) {
            if ($gone) {
                DB::table('tenant_discount_tags')->where('discount_id', $discount->id)->whereIn('tag_id', $gone)->delete();
            }
            foreach ($added as $tagId) {
                DB::table('tenant_discount_tags')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'tenant_id' => $discount->tenant_id,
                    'discount_id' => $discount->id, 'tag_id' => $tagId, 'created_at' => now(),
                ]);
            }
        });

        // Backfill: everyone who already used this code gets the new tags.
        if ($added) {
            $customerIds = TenantDiscountRedemption::where('discount_id', $discount->id)
                ->whereNotNull('customer_id')->distinct()->pluck('customer_id')->all();
            $this->attach($discount->tenant_id, $customerIds, $added);
        }

        return $ids;
    }

    /** Called from redeem(): put the discount's tags on this customer. */
    public function onRedeemed(TenantDiscount $discount, ?string $customerId): void
    {
        if (! $customerId) return;
        $tagIds = $this->tagIdsFor($discount->id);
        if (! $tagIds) return;
        $this->attach($discount->tenant_id, [$customerId], $tagIds);
    }

    /**
     * Called after a redemption row is deleted: drop the tags only if the
     * customer has no OTHER surviving redemption of this discount.
     */
    public function onReleased(string $tenantId, string $discountId, ?string $customerId): void
    {
        if (! $customerId) return;
        $still = TenantDiscountRedemption::where('discount_id', $discountId)->where('customer_id', $customerId)->exists();
        if ($still) return;
        $tagIds = $this->tagIdsFor($discountId);
        if (! $tagIds) return;
        DB::table('tenant_customer_tag_pivot')
            ->where('tenant_id', $tenantId)->where('customer_id', $customerId)->whereIn('tag_id', $tagIds)->delete();
    }

    private function attach(string $tenantId, array $customerIds, array $tagIds): void
    {
        if (! $customerIds || ! $tagIds) return;
        $rows = [];
        foreach ($customerIds as $cid) {
            foreach ($tagIds as $tid) {
                $rows[] = ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'tag_id' => $tid, 'customer_id' => $cid, 'created_at' => now()];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tenant_customer_tag_pivot')->insertOrIgnore($chunk);
        }
    }
}
