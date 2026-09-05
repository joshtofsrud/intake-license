<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantAudience;
use App\Models\Tenant\TenantCustomer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-CAMPAIGN-AUDIENCE — the ONE place an audience is resolved.
 *
 * Before this, send(), the pre-send checks panel and the scheduled-fire path
 * each built their own copy of the query. Three copies is how a scheduled
 * campaign ends up mailing a different list than the panel promised, so every
 * caller now comes through here.
 *
 * Shape of `targeting`:
 *   ['segment' => 'all'|'has_appointment']          legacy, still honoured
 *   ['mode' => 'all']
 *   ['mode' => 'saved', 'audience_id' => uuid]
 *   ['mode' => 'rules', 'rules' => [ ['field'=>…, 'op'=>…, 'value'=>…, 'unit'=>…], … ]]
 *
 * Rules combine with AND. Whatever the rules say, a customer without marketing
 * permission is never mailed — that is enforced in mailable(), not left to the
 * caller to remember.
 */
class AudienceService
{
    /** Fields offered in the builder, and what each one means in plain words. */
    public const FIELDS = [
        'last_visit'     => ['label' => 'Last visit',              'type' => 'age'],
        'visit_count'    => ['label' => 'Visit count',             'type' => 'number'],
        'never_booked'   => ['label' => 'Never booked',            'type' => 'flag'],
        'total_spend'    => ['label' => 'Total spent',             'type' => 'money'],
        'is_vip'         => ['label' => 'VIP',                     'type' => 'flag'],
        'added'          => ['label' => 'Added',                   'type' => 'age'],
        'city'           => ['label' => 'City',                    'type' => 'text'],
        'customer_type'  => ['label' => 'Business or individual',  'type' => 'choice'],
        'consent_source' => ['label' => 'Opted in via',            'type' => 'choice'],
        'special_order'  => ['label' => 'Special order',           'type' => 'flag'],
        // MARKER-CUSTOMER-TAGS — the reason imports stay findable.
        'tag'            => ['label' => 'Tag',                      'type' => 'tag'],
    ];

    public const CHOICES = [
        'customer_type'  => ['individual' => 'Individual', 'business' => 'Business'],
        'consent_source' => [
            'booking'  => 'Booking',
            'checkout' => 'Checkout',
            'portal'   => 'Their account page',
            'staff'    => 'Confirmed by staff',
            'import'   => 'Import',
        ],
    ];

    /** Base query for everyone the rules select — permission NOT yet applied. */
    public function query(Tenant $tenant, ?array $targeting): \Illuminate\Database\Eloquent\Builder
    {
        $q = TenantCustomer::where('tenant_id', $tenant->id);

        // MARKER-AUDIENCE-EMPTY — an unresolved saved audience must select
        // NOBODY. Falling through to "no rules" means everyone, which is how a
        // deleted list turns into a send to the entire customer base.
        if ($this->isUnresolvedSaved($tenant, $targeting)) {
            return $q->whereRaw('1 = 0');
        }

        foreach ($this->rulesFor($tenant, $targeting) as $rule) {
            $this->applyRule($q, $tenant, $rule);
        }

        return $q;
    }

    /** MARKER-AUDIENCE-EMPTY — saved mode pointing at nothing we can find. */
    public function isUnresolvedSaved(Tenant $tenant, ?array $targeting): bool
    {
        if (! is_array($targeting) || ($targeting['mode'] ?? null) !== 'saved') {
            return false;
        }
        $id = $targeting['audience_id'] ?? '';
        if ($id === '' || $id === null) {
            return true;
        }
        return ! TenantAudience::where('tenant_id', $tenant->id)->where('id', $id)->exists();
    }

    /** Those who will actually receive it: the rules, then permission. */
    public function mailable(Tenant $tenant, ?array $targeting): \Illuminate\Database\Eloquent\Builder
    {
        return $this->query($tenant, $targeting)->emailMailable();
    }

    /** Counts for the composer and the pre-send panel. */
    public function counts(Tenant $tenant, ?array $targeting): array
    {
        $matched   = (clone $this->query($tenant, $targeting))->count();
        $withEmail = (clone $this->query($tenant, $targeting))
            ->whereNotNull('email')->where('email', '!=', '')->count();
        $mailable  = (clone $this->mailable($tenant, $targeting))->count();

        return [
            'matched'   => $matched,
            'withEmail' => $withEmail,
            'mailable'  => $mailable,
            'blocked'   => max(0, $withEmail - $mailable),
        ];
    }

    /** A handful of real names, so a wrong rule is caught before the send. */
    public function sample(Tenant $tenant, ?array $targeting, int $limit = 8): array
    {
        return $this->query($tenant, $targeting)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'business_name', 'email',
                   'email_marketing_consent_at', 'email_marketing_opt_out_at'])
            ->map(fn ($c) => [
                'name'    => $c->fullName(),
                'email'   => $c->email,
                'mailable' => (bool) ($c->email && $c->email_marketing_consent_at && ! $c->email_marketing_opt_out_at),
            ])->all();
    }

    /** Human sentence for the panel and the campaign list. */
    public function describe(Tenant $tenant, ?array $targeting): string
    {
        $mode = $targeting['mode'] ?? null;

        if ($mode === 'saved') {
            // MARKER-AUDIENCE-EMPTY — never describe an unresolved list as
            // everyone; nobody is what it now selects.
            $id = $targeting['audience_id'] ?? '';
            if ($id === '' || $id === null) {
                return 'No audience chosen yet — nobody will receive this';
            }
            $a = TenantAudience::where('tenant_id', $tenant->id)->where('id', $id)->first();
            return $a ? $a->name : 'That saved audience was deleted — nobody will receive this';
        }

        $rules = $this->rulesFor($tenant, $targeting);
        if (! $rules) {
            return 'Everyone with marketing permission';
        }

        return collect($rules)->map(fn ($r) => $this->describeRule($r))->implode(', and ');
    }

    // ---- rules ------------------------------------------------------

    /** Resolves any targeting shape — including the legacy one — into rules. */
    private function rulesFor(Tenant $tenant, ?array $targeting): array
    {
        if (! is_array($targeting)) return [];

        // legacy: the old two-option dropdown
        if (! isset($targeting['mode'])) {
            $segment = $targeting['segment'] ?? 'all';
            return $segment === 'has_appointment'
                ? [['field' => 'visit_count', 'op' => 'at_least', 'value' => 1]]
                : [];
        }

        if ($targeting['mode'] === 'saved') {
            $a = TenantAudience::where('tenant_id', $tenant->id)
                ->where('id', $targeting['audience_id'] ?? '')->first();
            // A deleted audience must not silently widen to everyone mid-send;
            // callers see this through describe(), and send() refuses on zero.
            return $a ? $this->sanitize($a->rules) : [];
        }

        if ($targeting['mode'] === 'rules') {
            return $this->sanitize($targeting['rules'] ?? []);
        }

        return [];
    }

    /** Only known fields and operators survive; everything else is dropped. */
    public function sanitize($rules): array
    {
        if (! is_array($rules)) return [];
        $out = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) continue;
            $field = $rule['field'] ?? null;
            if (! isset(self::FIELDS[$field])) continue;

            $out[] = [
                'field' => $field,
                'op'    => (string) ($rule['op'] ?? ''),
                'value' => is_scalar($rule['value'] ?? null) ? (string) $rule['value'] : '',
                'unit'  => in_array($rule['unit'] ?? '', ['days', 'months', 'years'], true) ? $rule['unit'] : 'months',
            ];
        }

        return array_slice($out, 0, 12); // a dozen rules is already past useful
    }

    private function cutoff(array $rule): Carbon
    {
        $n    = max(0, (int) $rule['value']);
        $unit = $rule['unit'] ?? 'months';
        return match ($unit) {
            'days'  => now()->subDays($n),
            'years' => now()->subYears($n),
            default => now()->subMonths($n),
        };
    }

    private function applyRule($q, Tenant $tenant, array $rule): void
    {
        $op = $rule['op'];

        switch ($rule['field']) {

            case 'last_visit':
                $cut = $this->cutoff($rule);
                if ($op === 'within') {
                    $q->whereHas('appointments', fn ($a) => $a->where('appointment_date', '>=', $cut->toDateString()));
                } else { // longer_ago — the lapsed-customer case
                    $q->whereHas('appointments')
                      ->whereDoesntHave('appointments', fn ($a) => $a->where('appointment_date', '>=', $cut->toDateString()));
                }
                break;

            case 'visit_count':
                $n = max(0, (int) $rule['value']);
                $op === 'at_most'
                    ? $q->has('appointments', '<=', $n)
                    : $q->has('appointments', '>=', $n);
                break;

            case 'never_booked':
                $op === 'is_not'
                    ? $q->has('appointments')
                    : $q->doesntHave('appointments');
                break;

            case 'total_spend':
                // tenant_sales carries customer_id (verified); only settled
                // sales count, so an abandoned draft never reads as spend.
                $cents = (int) round(((float) $rule['value']) * 100);
                $sub = DB::table('tenant_sales')
                    ->select('customer_id', DB::raw('SUM(total_cents) as spend'))
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('status', ['completed', 'closed', 'shipped'])
                    ->whereNotNull('customer_id')
                    ->groupBy('customer_id');

                $q->joinSub($sub, 'spend_agg', fn ($j) => $j->on('spend_agg.customer_id', '=', 'tenant_customers.id'))
                  ->select('tenant_customers.*')
                  ->where('spend_agg.spend', $op === 'at_most' ? '<=' : '>=', $cents);
                break;

            case 'is_vip':
                $q->where('is_vip', $op === 'is_not' ? 0 : 1);
                break;

            case 'added':
                $cut = $this->cutoff($rule);
                $q->where('created_at', $op === 'longer_ago' ? '<' : '>=', $cut);
                break;

            case 'city':
                $v = trim((string) $rule['value']);
                if ($v !== '') {
                    $op === 'is_not'
                        ? $q->where(fn ($w) => $w->whereNull('city')->orWhere('city', '!=', $v))
                        : $q->where('city', $v);
                }
                break;

            case 'customer_type':
                $v = $rule['value'] === 'business' ? 'business' : 'individual';
                $op === 'is_not' ? $q->where('customer_type', '!=', $v) : $q->where('customer_type', $v);
                break;

            case 'consent_source':
                $v = (string) $rule['value'];
                $op === 'is_not'
                    ? $q->where(fn ($w) => $w->whereNull('email_marketing_consent_source')->orWhere('email_marketing_consent_source', '!=', $v))
                    : $q->where('email_marketing_consent_source', $v);
                break;

            case 'special_order':
                $op === 'is_not' ? $q->doesntHave('specialOrders') : $q->has('specialOrders');
                break;

            // MARKER-CUSTOMER-TAGS
            case 'tag':
                // MARKER-AUD-TAGPICK — one or many ids, comma-joined. Nothing
                // picked matches NOBODY: an unfinished rule fails closed
                // instead of silently widening to the whole list.
                $tagIds = array_values(array_filter(array_map('trim', explode(',', (string) $rule['value']))));
                if (! $tagIds) { $q->whereRaw('1 = 0'); break; }
                $op === 'is_not'
                    ? $q->whereDoesntHave('tags', fn ($t) => $t->whereIn('tenant_customer_tags.id', $tagIds))
                    : $q->whereHas('tags', fn ($t) => $t->whereIn('tenant_customer_tags.id', $tagIds));
                break;
        }
    }

    private function describeRule(array $rule): string
    {
        $label = self::FIELDS[$rule['field']]['label'] ?? $rule['field'];
        $unit  = $rule['unit'] ?? 'months';
        $v     = $rule['value'];

        return match ($rule['field']) {
            'last_visit'    => $rule['op'] === 'within'
                                ? "visited in the last {$v} {$unit}"
                                : "hasn't visited in {$v} {$unit}",
            'visit_count'   => ($rule['op'] === 'at_most' ? "at most {$v} visits" : "at least {$v} visits"),
            'never_booked'  => $rule['op'] === 'is_not' ? 'has booked before' : 'never booked',
            'total_spend'   => ($rule['op'] === 'at_most' ? "spent under \${$v}" : "spent \${$v} or more"),
            'is_vip'        => $rule['op'] === 'is_not' ? 'not VIP' : 'VIP',
            'added'         => $rule['op'] === 'longer_ago'
                                ? "added more than {$v} {$unit} ago"
                                : "added in the last {$v} {$unit}",
            'city'          => ($rule['op'] === 'is_not' ? "not in {$v}" : "in {$v}"),
            'customer_type' => (self::CHOICES['customer_type'][$v] ?? $v) . ($rule['op'] === 'is_not' ? ' (excluded)' : ''),
            'consent_source'=> 'opted in via ' . (self::CHOICES['consent_source'][$v] ?? $v),
            'special_order' => $rule['op'] === 'is_not' ? 'no special orders' : 'has a special order',
            'tag'           => (function () use ($rule) {   // MARKER-CUSTOMER-TAGS / MARKER-AUD-TAGPICK
                $ids   = array_values(array_filter(array_map('trim', explode(',', (string) $rule['value']))));
                if (! $ids) return 'tagged (nothing picked — matches no one)';
                $names = \App\Models\Tenant\TenantCustomerTag::whereIn('id', $ids)->pluck('name')->all();
                if (count($names) < count($ids)) $names[] = 'a deleted tag';
                return ($rule['op'] === 'is_not' ? 'not tagged ' : 'tagged ') . implode(' or ', $names);
            })(),
            default         => strtolower($label),
        };
    }
}
