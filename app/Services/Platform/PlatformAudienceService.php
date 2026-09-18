<?php

namespace App\Services\Platform;

use App\Models\PlatformAudience;
use App\Models\PlatformEmailOptout;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-PLATFORM-EMAIL — turn an audience's rules into recipients.
 *
 * FOUR SOURCES, AND tenant_customers IS NOT ONE. A shop's customer list belongs
 * to the shop. There is no rule, flag or setting in this class that reaches it,
 * and adding one would be the wrong kind of easy.
 *
 * Every recipient carries merge variables so a campaign can say "Hi Josh" and
 * "your shop, Ground Control" without the campaign knowing where the row came
 * from.
 */
class PlatformAudienceService
{
    /** @return Collection<int, array{email:string,name:?string,source_type:string,source_id:?string,vars:array}> */
    public function resolve(PlatformAudience $audience): Collection
    {
        $rules = $audience->rules ?? [];

        $rows = match ($audience->source) {
            'tenants', 'tenant_owners' => $this->fromTenants($rules, $audience->source),
            'prospects'                => $this->fromProspects($rules),
            'wrote_in'                 => $this->fromInbox($rules),
            'reps'                     => $this->fromReps($rules),
            default                    => collect(),
        };

        // One person, one email: a prospect who became a tenant owner is one
        // recipient, not two.
        return $rows
            ->filter(fn ($r) => filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL))
            ->map(function ($r) {
                $r['email'] = mb_strtolower(trim($r['email']));
                return $r;
            })
            ->unique('email')
            ->values();
    }

    /** Recipients minus anyone who has opted out. */
    public function mailable(PlatformAudience $audience): Collection
    {
        $all = $this->resolve($audience);
        if ($all->isEmpty()) {
            return $all;
        }

        $out = PlatformEmailOptout::whereIn('email', $all->pluck('email'))->pluck('email')->all();

        return $all->reject(fn ($r) => in_array($r['email'], $out, true))->values();
    }

    // ------------------------------------------------------------- sources

    protected function fromTenants(array $rules, string $source): Collection
    {
        $q = Tenant::query()->where('is_platform', false)->where('is_demo', false);

        foreach ($rules as $rule) {
            $field = $rule['field'] ?? null;
            $op    = $rule['op']    ?? 'is';
            $value = $rule['value'] ?? null;

            match ($field) {
                'plan_tier'   => $q->where('plan_tier', $op === 'is_not' ? '!=' : '=', $value),
                'is_active'   => $q->where('is_active', $value === 'no' ? 0 : 1),
                'subscription'=> $q->where('subscription_status', $op === 'is_not' ? '!=' : '=', $value),
                'signed_up'   => $q->where('created_at', $op === 'before' ? '<' : '>=', $value),
                // addons() is hasMany(TenantFeatureAddon), keyed by addon_code,
                // not a pivot carrying a code column.
                'addon'       => $op === 'has_not'
                    ? $q->whereDoesntHave('addons', fn ($a) => $a->where('addon_code', $value))
                    : $q->whereHas('addons', fn ($a) => $a->where('addon_code', $value)),
                default       => null,
            };
        }

        return $q->get()->map(function (Tenant $t) use ($source) {
            $email = $source === 'tenant_owners'
                ? ($t->notification_email ?: $this->ownerEmail($t))
                : ($t->notification_email ?: $this->ownerEmail($t));

            return [
                'email'       => (string) $email,
                'name'        => $t->name,
                'source_type' => $source,
                'source_id'   => $t->id,
                'vars'        => [
                    'shop_name'  => $t->name,
                    'first_name' => $this->firstNameFromEmail($email),
                    'plan'       => ucfirst((string) $t->plan_tier),
                ],
            ];
        });
    }

    protected function ownerEmail(Tenant $t): ?string
    {
        return DB::table('tenant_users')
            ->where('tenant_id', $t->id)
            ->orderBy('created_at')
            ->value('email');
    }

    protected function fromProspects(array $rules): Collection
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('sales_prospects')) {
            return collect();
        }

        $q = DB::table('sales_prospects')->whereNotNull('email');

        foreach ($rules as $rule) {
            $field = $rule['field'] ?? null;
            $op    = $rule['op']    ?? 'is';
            $value = $rule['value'] ?? null;

            match ($field) {
                'status' => $q->where('status', $op === 'is_not' ? '!=' : '=', $value),
                'state'  => $q->where('address', 'like', '%' . $value . '%'),
                default  => null,
            };
        }

        return collect($q->get())->map(fn ($p) => [
            'email'       => (string) $p->email,
            'name'        => $p->name ?? null,
            'source_type' => 'prospects',
            'source_id'   => (string) $p->id,
            'vars'        => [
                'shop_name'  => $p->name ?? '',
                'first_name' => $this->firstName($p->owner_contact ?? null) ?: $this->firstNameFromEmail($p->email),
            ],
        ]);
    }

    protected function fromInbox(array $rules): Collection
    {
        return DB::table('platform_inbox_messages')
            ->where('kind', 'contact')
            ->where('status', '!=', 'spam')
            ->whereNotNull('email')
            ->get(['name', 'email'])
            ->map(fn ($m) => [
                'email'       => (string) $m->email,
                'name'        => $m->name,
                'source_type' => 'wrote_in',
                'source_id'   => null,
                'vars'        => ['first_name' => $this->firstName($m->name) ?: 'there', 'shop_name' => ''],
            ]);
    }

    protected function fromReps(array $rules): Collection
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('sales_reps')) {
            return collect();
        }

        return collect(DB::table('sales_reps')->whereNotNull('email')->get())
            ->map(fn ($r) => [
                'email'       => (string) $r->email,
                'name'        => $r->name ?? null,
                'source_type' => 'reps',
                'source_id'   => (string) $r->id,
                'vars'        => ['first_name' => $this->firstName($r->name ?? null) ?: 'there', 'shop_name' => ''],
            ]);
    }

    // ------------------------------------------------------------- helpers

    protected function firstName(?string $full): ?string
    {
        $full = trim((string) $full);
        return $full === '' ? null : explode(' ', $full)[0];
    }

    protected function firstNameFromEmail(?string $email): string
    {
        $local = explode('@', (string) $email)[0] ?? '';
        $local = preg_replace('/[._-].*$/', '', $local);
        return $local !== '' ? ucfirst($local) : 'there';
    }
}
