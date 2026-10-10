<?php

namespace App\Services\Platform;

use App\Models\PlatformAudience;
use App\Models\PlatformEmailOptout;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * turn an audience's rules into recipients.
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
            'investors'                => $this->fromInvestors($rules),
            'inv_leads'                => $this->fromInvestLeads(),
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

    /** everyone on the investor record who hasn't declined, narrowed by status and amount. */
    protected function fromInvestors(array $rules = []): Collection
    {
        return \App\Models\Investor::whereNull('declined_at')->whereNotNull('email')->get()
            ->filter(function ($i) use ($rules) {
                foreach ($rules as $r) {
                    $f = $r['field'] ?? null; $v = (string) ($r['value'] ?? ''); $not = ($r['op'] ?? 'is') === 'is_not';
                    if ($v === '') continue;
                    $have = match ($f) {
                        'status' => self::investorStage($i),
                        'amount' => self::amountBand((int) $i->amount),
                        default  => null,
                    };
                    if ($have === null) continue;
                    if (($have === $v) === $not) return false;
                }
                return true;
            })
            ->map(fn ($i) => [
                'email'       => (string) $i->email,
                'name'        => $i->name,
                'source_type' => 'investors',
                'source_id'   => $i->id,
                'vars'        => ['first_name' => trim(explode(' ', (string) $i->name)[0]) ?: 'there', 'shop_name' => ''],
            ]);
    }

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
        // this used to filter on a "status" column that
        // prospects don't have (it errored), read a "name" column that doesn't
        // exist (so {shop_name} came out blank) and matched State against the
        // address text. Rules now use the real columns.
        if (! \Illuminate\Support\Facades\Schema::hasTable('sales_prospects')) {
            return collect();
        }

        $q = DB::table('sales_prospects')->whereNotNull('email')->where('email', '!=', '');

        $byName = function (string $table, $value) {
            return DB::table($table)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])->pluck('id');
        };

        foreach ($rules as $rule) {
            $field = $rule['field'] ?? null;
            $not   = ($rule['op'] ?? 'is') === 'is_not';
            $value = trim((string) ($rule['value'] ?? ''));
            if ($value === '') continue;

            $column = match ($field) {
                'stage', 'status' => ['stage', mb_strtolower(str_replace(' ', '_', $value))],
                'state'           => ['state', mb_strtoupper($value)],
                'priority'        => ['priority', mb_strtoupper($value)],
                'verified'        => ['verified', in_array(mb_strtolower($value), ['1', 'yes', 'true', 'y'], true) ? 1 : 0],
                default           => null,
            };
            if ($column) {
                $q->where($column[0], $not ? '!=' : '=', $column[1]);
                continue;
            }

            // MARKER-AUDIENCE-BUILDER prospect exclusions
            if ($field === 'is_tenant') {
                $wantTenant = in_array(mb_strtolower($value), ['yes', '1', 'true'], true) !== $not;
                if ($wantTenant) {
                    $q->where(fn ($w) => $w->whereNotNull('converted_at')->orWhereNotNull('tenant_id'));
                } else {
                    $q->whereNull('converted_at')->whereNull('tenant_id');
                }
                continue;
            }
            if ($field === 'contacted_within') {
                $since = now()->subDays(max(1, (int) $value));
                if ($not) {
                    $q->where(fn ($w) => $w->whereNull('last_contacted_at')->orWhere('last_contacted_at', '<', $since));
                } else {
                    $q->where('last_contacted_at', '>=', $since);
                }
                continue;
            }

            $ids = match ($field) {
                'industry'  => ['channel_id', $byName('sales_channels', $value)],
                'territory' => ['territory_id', $byName('sales_territories', $value)],
                'rep'       => ['sales_rep_id', $byName('sales_reps', $value)],
                'ids'       => ['id', collect(preg_split('/[\s,]+/', $value))->filter()->values()],
                default     => null,
            };
            if (! $ids) continue;
            if ($not) {
                $q->where(fn ($w) => $w->whereNull($ids[0])->orWhereNotIn($ids[0], $ids[1]));
            } else {
                $q->whereIn($ids[0], $ids[1]);
            }
        }

        $rows = collect($q->get());
        // MARKER-AUDIENCE-BUILDER: campaigns go through Postmark's broadcast
        // stream, which only allows people who asked to hear from us. A prospect
        // counts once they booked a call, wrote in through the site or became a
        // tenant. Cold prospects are never campaign recipients.
        if (! $this->includeCold) {
            $optin = $this->optedInEmails();
            $rows  = $rows->filter(fn ($p) => ! empty($p->converted_at) || isset($optin[mb_strtolower(trim((string) $p->email))]));
        }

        return $rows->map(fn ($p) => [
            'email'       => (string) $p->email,
            'name'        => $p->owner_contact ?: $p->shop,
            'source_type' => 'prospects',
            'source_id'   => (string) $p->id,
            'vars'        => [
                'shop_name'  => (string) ($p->shop ?? ''),
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

    // MARKER-AUDIENCE-BUILDER: what the audience screen offers, and its live preview.

    /** Fields a rule can use, per source. Anything else in a saved rule is kept but shown read-only. */
    public const FIELDS = [
        'prospects'     => ['industry' => 'Industry', 'stage' => 'Stage', 'priority' => 'Priority', 'state' => 'State', 'territory' => 'Territory', 'rep' => 'Rep', 'verified' => 'Verified'],
        'tenants'       => ['plan_tier' => 'Plan', 'subscription' => 'Subscription', 'addon' => 'Add-on', 'is_active' => 'Active'],
        'tenant_owners' => ['plan_tier' => 'Plan', 'subscription' => 'Subscription', 'addon' => 'Add-on', 'is_active' => 'Active'],
        'investors'     => ['status' => 'Status', 'amount' => 'Amount'],
    ];

    /** Prospect exclusions shown as tick boxes; stored as ordinary rules carrying 'x'. */
    public const EXCLUSIONS = [
        'tenant' => ['label' => 'Already a tenant',             'rule' => ['field' => 'is_tenant',        'op' => 'is',     'value' => 'no',  'x' => 'tenant']],
        'lost'   => ['label' => 'Marked lost',                  'rule' => ['field' => 'stage',            'op' => 'is_not', 'value' => 'lost', 'x' => 'lost']],
        'recent' => ['label' => 'Contacted in the last 30 days', 'rule' => ['field' => 'contacted_within', 'op' => 'is_not', 'value' => '30',  'x' => 'recent']],
    ];

    public const INVESTOR_STAGES = ['added' => 'Added', 'invited' => 'Invited', 'opened' => 'Opened', 'committed' => 'Committed', 'signed' => 'Signed', 'funded' => 'Funded'];
    public const AMOUNT_BANDS    = ['under_10k' => 'Under $10k', '10k_25k' => '$10k to $25k', '25k_plus' => '$25k and up'];

    /** Cold prospects are never campaign recipients. Outreach tools may turn this on. */
    public bool $includeCold = false;

    /** @return array<string, array<string,string>> value => label, per field */
    public function options(string $source): array
    {
        $out = [];
        $names = function (string $table) {
            try {
                return Schema::hasTable($table)
                    ? DB::table($table)->whereNotNull('name')->orderBy('name')->pluck('name', 'name')->all() : [];
            } catch (\Throwable $e) { return []; }
        };
        $distinct = function (string $table, string $col) {
            try {
                return DB::table($table)->whereNotNull($col)->where($col, '!=', '')->distinct()->orderBy($col)->pluck($col)
                    ->mapWithKeys(fn ($v) => [(string) $v => ucfirst(str_replace('_', ' ', (string) $v))])->all();
            } catch (\Throwable $e) { return []; }
        };

        if ($source === 'prospects') {
            $out = [
                'industry'  => $names('sales_channels'),
                'stage'     => $distinct('sales_prospects', 'stage'),
                'priority'  => ['A' => 'A', 'B' => 'B', 'C' => 'C'],
                'state'     => $distinct('sales_prospects', 'state'),
                'territory' => $names('sales_territories'),
                'rep'       => $names('sales_reps'),
                'verified'  => ['yes' => 'Yes', 'no' => 'No'],
            ];
        } elseif (in_array($source, ['tenants', 'tenant_owners'], true)) {
            $addons = [];
            try {
                $addons = (new Tenant)->addons()->getRelated()->newQuery()->distinct()->orderBy('addon_code')->pluck('addon_code')
                    ->mapWithKeys(fn ($v) => [(string) $v => ucfirst(str_replace('_', ' ', (string) $v))])->all();
            } catch (\Throwable $e) {}
            $out = [
                'plan_tier'    => $distinct('tenants', 'plan_tier'),
                'subscription' => $distinct('tenants', 'subscription_status'),
                'addon'        => $addons,
                'is_active'    => ['yes' => 'Yes', 'no' => 'No'],
            ];
        } elseif ($source === 'investors') {
            $out = ['status' => self::INVESTOR_STAGES, 'amount' => self::AMOUNT_BANDS];
        }

        return $out;
    }

    /** Everything the screen shows beside the rules, from the same resolver the sender uses. */
    public function preview(string $source, array $rules): array
    {
        $a   = new PlatformAudience(['name' => 'preview', 'source' => $source, 'rules' => $rules]);
        $all = $this->resolve($a);

        $opt   = $all->isEmpty() ? collect() : PlatformEmailOptout::whereIn('email', $all->pluck('email'))->get(['email', 'kind']);
        $gone  = $opt->pluck('email')->flip();
        $bad   = $opt->filter(fn ($r) => in_array($r->kind, ['bounce', 'complaint'], true))->count();
        $ok    = $all->reject(fn ($r) => isset($gone[$r['email']]))->values();
        $optin = $source === 'prospects' ? $this->optedInEmails() : [];

        $sample = $ok->take(8)->map(function ($r) use ($source, $optin) {
            $tag = '';
            if ($source === 'prospects') {
                $tag = $optin[$r['email']] ?? 'became a tenant';
            } elseif (in_array($source, ['tenants', 'tenant_owners'], true)) {
                $tag = (string) ($r['vars']['plan'] ?? '');
            } elseif ($source === 'investors') {
                $inv = \App\Models\Investor::find($r['source_id']);
                $tag = $inv ? (self::INVESTOR_STAGES[self::investorStage($inv)] ?? '') : '';
            } elseif ($source === 'inv_leads') {
                $tag = 'mailing list';
            }
            return ['name' => $r['name'] ?: $r['email'], 'email' => $r['email'], 'tag' => $tag];
        })->all();

        return [
            'matched'  => $all->count(),
            'optout'   => $opt->count() - $bad,
            'bounced'  => $bad,
            'mailable' => $ok->count(),
            'sample'   => $sample,
            'cold'     => $source === 'prospects' ? $this->coldProspectCount() : 0,
        ];
    }

    /** email => how they asked to hear from us: booked a call or wrote in through the site. */
    public function optedInEmails(): array
    {
        $out = [];
        try {
            if (Schema::hasTable('platform_inbox_messages')) {
                foreach (DB::table('platform_inbox_messages')->where('kind', 'contact')->where('status', '!=', 'spam')
                    ->whereNotNull('email')->pluck('email') as $e) {
                    $out[mb_strtolower(trim((string) $e))] = 'wrote in';
                }
            }
            if (Schema::hasTable('platform_bookings')) {
                foreach (DB::table('platform_bookings')->whereNotNull('email')->pluck('email') as $e) {
                    $out[mb_strtolower(trim((string) $e))] = 'booked a call';
                }
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    public function coldProspectCount(): int
    {
        try {
            $optin = $this->optedInEmails();
            return DB::table('sales_prospects')->whereNotNull('email')->where('email', '!=', '')->whereNull('converted_at')
                ->pluck('email')->reject(fn ($e) => isset($optin[mb_strtolower(trim((string) $e))]))->count();
        } catch (\Throwable $e) { return 0; }
    }

    public static function investorStage($i): string
    {
        return $i->funded_at ? 'funded' : ($i->signed_at ? 'signed' : ($i->committed_at ? 'committed'
            : (! empty($i->opened_at) ? 'opened' : ($i->invited_at ? 'invited' : 'added'))));
    }

    public static function amountBand(int $amount): string
    {
        return $amount >= 25000 ? '25k_plus' : ($amount >= 10000 ? '10k_25k' : 'under_10k');
    }

    /** People who joined the invest page's mailing list and aren't investors (yet). */
    protected function fromInvestLeads(): Collection
    {
        if (! Schema::hasTable('invest_leads')) {
            return collect();
        }
        $inv = \App\Models\Investor::whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower(trim((string) $e)))->flip();

        return collect(DB::table('invest_leads')->whereNotNull('email')->orderByDesc('id')->get(['name', 'email']))
            ->reject(fn ($l) => isset($inv[mb_strtolower(trim((string) $l->email))]))
            ->map(fn ($l) => [
                'email'       => (string) $l->email,
                'name'        => $l->name,
                'source_type' => 'inv_leads',
                'source_id'   => null,
                'vars'        => ['first_name' => $this->firstName($l->name) ?: 'there', 'shop_name' => ''],
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
