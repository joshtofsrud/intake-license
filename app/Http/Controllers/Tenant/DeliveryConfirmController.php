<?php
// MARKER-PATCH-528

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantDelivery;
use App\Models\Tenant\TenantDeliveryProposal;
use App\Models\Tenant\TenantRouteWindow;
use App\Services\Tenant\TenantDeliveryNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * DeliveryConfirmController — the public /d/{token} page where a customer
 * picks their delivery window from a texted proposal. No auth: the token
 * is the credential. Confirming creates the TenantDelivery and sends the
 * standard "scheduled" notification.
 */
class DeliveryConfirmController extends Controller
{
    public function show(Request $request, string $token)
    {
        [$tenant, $proposal] = $this->resolve($token);
        if (!$proposal) abort(404);

        return view('public.delivery-confirm', [
            'tenant'   => $tenant,
            'proposal' => $proposal,
            'windows'  => $this->windowsWithAvailability($proposal),
            'error'    => session('dc_error'),
        ]);
    }

    public function confirm(Request $request, string $token)
    {
        [$tenant, $proposal] = $this->resolve($token);
        if (!$proposal) abort(404);

        if (!$proposal->isPending() && $proposal->status !== TenantDeliveryProposal::STATUS_NO_REPLY) { // MARKER-PATCH-534
            return redirect()->route('tenant.delivery_confirm.show', $token);
        }

        $windowId = (string) $request->input('window_id');
        $date     = (string) $request->input('date');
        $chosen   = collect($proposal->windows)->first(
            fn ($w) => $w['window_id'] === $windowId && $w['date'] === $date
        );
        if (!$chosen) {
            return redirect()->route('tenant.delivery_confirm.show', $token)
                ->with('dc_error', 'Please pick one of the offered windows.');
        }

        $window = TenantRouteWindow::query()
            ->where('tenant_id', $tenant->id)->where('id', $windowId)->first();
        $day = Carbon::parse($date, $tenant->timezone());
        if (!$window || !$window->is_active || !$window->runsOn($day) || $window->remainingStops($day) < 1) {
            return redirect()->route('tenant.delivery_confirm.show', $token)
                ->with('dc_error', 'That window just filled up — please pick another.');
        }

        $delivery = $this->materialize($tenant, $proposal, $window, $day);

        $proposal->update([
            'status'              => TenantDeliveryProposal::STATUS_CONFIRMED,
            'confirmed_window_id' => $window->id,
            'confirmed_date'      => $day->toDateString(),
            'delivery_id'         => $delivery->id,
            'confirmed_at'        => now(),
        ]);

        try {
            TenantDeliveryNotificationService::forTenant($tenant)->sendScheduled($delivery);
        } catch (\Throwable $e) {
            Log::error('Delivery confirm notification failed', [
                'delivery_id' => $delivery->id, 'error' => $e->getMessage(),
            ]);
        }

        return redirect()->route('tenant.delivery_confirm.show', $token);
    }

    /**
     * Shared with the assume-first command (patch-529): turn a proposal
     * choice into a scheduled TenantDelivery row.
     */
    public static function materialize($tenant, TenantDeliveryProposal $proposal, TenantRouteWindow $window, Carbon $day): TenantDelivery
    {
        $tz    = $tenant->timezone();
        $start = Carbon::parse($day->toDateString() . ' ' . (string) $window->starts_at, $tz);
        $end   = Carbon::parse($day->toDateString() . ' ' . (string) $window->ends_at, $tz);

        $proposal->loadMissing('customer');
        $c = $proposal->customer;
        $address = $c
            ? trim(implode(', ', array_filter([
                trim(($c->address_line1 ?? '') . ' ' . ($c->address_line2 ?? '')),
                $c->city ?? null,
                trim(($c->state ?? '') . ' ' . ($c->postcode ?? '')),
              ])))
            : '';

        return TenantDelivery::create([
            'tenant_id'      => $tenant->id,
            'type'           => TenantDelivery::TYPE_DROPOFF,
            'status'         => TenantDelivery::STATUS_SCHEDULED,
            'scheduled_at'   => $start->copy()->utc(),
            'window_minutes' => max(15, $start->diffInMinutes($end)),
            'address'        => $address !== '' ? $address : null,
            'customer_id'    => $proposal->customer_id,
            'appointment_id' => $proposal->appointment_id,
        ]);
    }

    private function resolve(string $token): array
    {
        $tenant = tenant();
        if (!$tenant) return [null, null];
        $proposal = TenantDeliveryProposal::query()
            ->where('tenant_id', $tenant->id)
            ->where('token', $token)
            ->first();
        return [$tenant, $proposal];
    }

    private function windowsWithAvailability(TenantDeliveryProposal $proposal): array
    {
        $tenant  = $proposal->tenant;
        $byId    = TenantRouteWindow::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', collect($proposal->windows)->pluck('window_id'))
            ->get()->keyBy('id');

        return collect($proposal->windows)->map(function ($w) use ($byId, $tenant) {
            $win = $byId->get($w['window_id']);
            $day = Carbon::parse($w['date'], $tenant->timezone());
            $remaining = ($win && $win->is_active && $win->runsOn($day)) ? $win->remainingStops($day) : 0;
            $w['remaining'] = $remaining;
            $w['full'] = $remaining < 1;
            $w['human'] = $day->format('l, M j');
            return $w;
        })->all();
    }
}
