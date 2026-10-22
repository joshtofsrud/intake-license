<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Tenant\TenantClassRegistration;
use App\Models\Tenant\TenantClassSession;
use App\Models\Tenant\TenantCustomerMembership;
use App\Models\Tenant\TenantCustomerPack;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The front desk's view of a class: who is booked, what they pay with, and
 * what staff should know at the door. Flags are worked out from passes,
 * memberships, payments, waivers and customer notes; nobody types them in.
 * MARKER-CLASSES-P9
 */
class ClassCheckin
{
    /** Today's classes in the studio's own day. */
    public static function today(Tenant $tenant): Collection
    {
        $tz = $tenant->timezone();
        return TenantClassSession::where('tenant_id', $tenant->id)
            ->whereIn('status', ['scheduled', 'confirmed', 'completed'])
            ->whereBetween('starts_at', [Carbon::now($tz)->startOfDay()->utc(), Carbon::now($tz)->endOfDay()->utc()])
            ->with('template')
            ->withCount(['activeRegistrations', 'waitlist',
                'registrations as checked_in_count' => fn ($q) => $q->where('status', 'checked_in')])
            ->orderBy('starts_at')->get();
    }

    /** The class to open on: one under way, else the next to start, else the last. */
    public static function pick(Collection $today, ?string $id): ?TenantClassSession
    {
        if ($id && ($s = $today->firstWhere('id', $id))) {
            return $s;
        }
        return $today->first(fn ($s) => $s->ends_at->isFuture()) ?? $today->last();
    }

    /**
     * One row per person booked (registered or checked in).
     * @return Collection<array{reg:TenantClassRegistration, name:string, initials:string, pass:string, flags:array<array{0:string,1:string}>, needs_waiver:bool}>
     */
    public static function rows(Tenant $tenant, TenantClassSession $s): Collection
    {
        $regs = TenantClassRegistration::where('class_session_id', $s->id)
            ->whereIn('status', ['registered', 'checked_in'])
            ->with('customer')->orderBy('registered_at')->get();
        $ids = $regs->pluck('customer_id')->unique()->values()->all();

        $needWaiver = array_flip(ClassWaivers::needing($tenant, $ids));
        $visited = TenantClassRegistration::where('tenant_id', $tenant->id)->whereIn('customer_id', $ids)
            ->where('status', 'checked_in')->where('class_session_id', '!=', $s->id)
            ->distinct()->pluck('customer_id')->flip();
        $pastDue = TenantCustomerMembership::where('tenant_id', $tenant->id)->whereIn('customer_id', $ids)
            ->where('status', 'past_due')->pluck('customer_id')->flip();
        $packs = TenantCustomerPack::whereIn('id', $regs->pluck('pack_id')->filter()->all())->with('product')->get()->keyBy('id');
        $mems  = TenantCustomerMembership::whereIn('id', $regs->pluck('membership_id')->filter()->all())->with('product')->get()->keyBy('id');
        $price = (int) ($s->template?->price_cents ?? 0);

        return $regs->map(function ($r) use ($needWaiver, $visited, $pastDue, $packs, $mems, $price) {
            $c     = $r->customer;
            $flags = [];
            $pass  = match ($r->payment_method) {
                'membership' => $mems[$r->membership_id]?->product?->name ?? 'Membership',
                'pack'       => ($p = $packs[$r->pack_id] ?? null) ? ($p->product?->name ?? 'Class pack') . ' · ' . $p->credits_remaining . ' left' : 'Class pack',
                'online'     => 'Drop-in · paid online',
                'cash'       => 'Drop-in · paid at the studio',
                default      => 'Drop-in · pays at the studio',
            };
            if (isset($needWaiver[$r->customer_id])) {
                $flags[] = ['Waiver not signed', 'red'];
            }
            if (! isset($visited[$r->customer_id])) {
                $flags[] = ['First visit', 'red'];
            }
            if (isset($pastDue[$r->customer_id])) {
                $flags[] = ['Membership payment due', 'red'];
            }
            if ($r->payment_method === 'per_class' && $price > 0) {
                $flags[] = ['Owes ' . format_money($price), 'amber'];
            }
            if ($r->payment_method === 'pack' && ($p = $packs[$r->pack_id] ?? null)) {
                if ((int) $p->credits_remaining === 0) {
                    $flags[] = ['Last credit', 'amber'];
                }
                if ($p->product?->is_intro) {
                    $flags[] = ['Intro offer', 'green'];
                }
            }
            if ($c && filled($c->notes)) {
                $flags[] = ['Note: ' . \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $c->notes)), 60), 'amber'];
            }
            $name = trim(($c?->first_name ?? '') . ' ' . ($c?->last_name ?? '')) ?: ($c?->email ?? 'Customer');
            $initials = collect(preg_split('/\s+/', $name))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');

            return [
                'reg'          => $r,
                'name'         => $name,
                'initials'     => $initials,
                'pass'         => $pass,
                'flags'        => $flags,
                'needs_waiver' => isset($needWaiver[$r->customer_id]),
            ];
        });
    }
}
