<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantWaitlistOffer;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WaitlistOfferController extends Controller
{
    /**
     * GET /waitlist/offer/{token}  — customer lands here after clicking SMS/email.
     */
    public function show(string $token)
    {
        $tenant = tenant();
        abort_unless($tenant->hasWaitlistFeature(), 404);

        $offer = TenantWaitlistOffer::with(['entry.serviceItem', 'entry.customer'])
            ->where('tenant_id', $tenant->id)
            ->where('offer_token', $token)
            ->first();
        abort_unless($offer, 404);

        // Mark viewed if first time
        if ($offer->status === 'pending') {
            $offer->update(['status' => 'viewed', 'viewed_at' => now()]);
        }

        // Has someone else already taken this slot?
        $competingAccepted = TenantWaitlistOffer::where('tenant_id', $tenant->id)
            ->where('slot_datetime', $offer->slot_datetime)
            ->where('status', 'accepted')
            ->where('id', '!=', $offer->id)
            ->exists();

        if ($competingAccepted && $offer->status !== 'accepted') {
            $offer->update(['status' => 'slot_taken']);
            return view('public.waitlist.offer-taken', [
                'tenant' => $tenant,
                'offer'  => $offer,
                'pageTitle' => 'This opening was taken',
            ]);
        }

        // Is this offer still valid?
        if (!$offer->isOpen() && $offer->status !== 'accepted') {
            return view('public.waitlist.offer-expired', [
                'tenant' => $tenant,
                'offer'  => $offer,
                'pageTitle' => 'This offer has expired',
            ]);
        }

        return view('public.waitlist.offer-accept', [
            'tenant'    => $tenant,
            'offer'     => $offer,
            'entry'     => $offer->entry,
            'customer'  => $offer->entry?->customer,
            'service'   => $offer->entry?->serviceItem,
            'pageTitle' => 'Confirm your booking',
        ]);
    }

    /**
     * POST /waitlist/offer/{token}/accept  — customer confirms.
     */
    public function accept(Request $request, string $token)
    {
        $tenant = tenant();
        abort_unless($tenant->hasWaitlistFeature(), 404);

        $result = DB::transaction(function () use ($tenant, $token) {
            $offer = TenantWaitlistOffer::with(['entry.serviceItem', 'entry.customer'])
                ->where('tenant_id', $tenant->id)
                ->where('offer_token', $token)
                ->lockForUpdate()
                ->first();

            if (!$offer) {
                return ['error' => 'Offer not found.'];
            }

            // Re-check slot availability inside the lock
            $takenByOther = TenantWaitlistOffer::where('tenant_id', $tenant->id)
                ->where('slot_datetime', $offer->slot_datetime)
                ->where('status', 'accepted')
                ->where('id', '!=', $offer->id)
                ->exists();
            if ($takenByOther) {
                $offer->update(['status' => 'slot_taken']);
                return ['error' => 'This slot was just taken by another customer.'];
            }

            if (!$offer->isOpen() && $offer->status !== 'viewed') {
                return ['error' => 'This offer has expired.'];
            }

            $entry    = $offer->entry;
            $customer = $entry?->customer;
            $service  = $entry?->serviceItem;
            if (!$entry || !$customer || !$service) {
                return ['error' => 'Offer data incomplete.'];
            }

            $booking = app(BookingService::class);
            $appointment = $booking->createAppointment(
                tenant: $tenant,
                customer: $customer,
                appointmentDate: $offer->slot_datetime,
                items: [[
                    'service_item_id' => $service->id,
                    'addon_ids'       => (array) ($entry->addon_ids ?? []),
                ]],
                responses: [],
            );

            $offer->update([
                'status'                   => 'accepted',
                'accepted_at'              => now(),
                'resulting_appointment_id' => $appointment->id,
            ]);

            $entry->update(['status' => 'fulfilled']);

            // Mark all OTHER offers for this slot as slot_taken
            TenantWaitlistOffer::where('tenant_id', $tenant->id)
                ->where('slot_datetime', $offer->slot_datetime)
                ->where('id', '!=', $offer->id)
                ->whereIn('status', ['pending', 'viewed'])
                ->update(['status' => 'slot_taken', 'updated_at' => now()]);

            return ['ok' => true, 'offer' => $offer, 'appointment' => $appointment];
        });

        if (!empty($result['error'])) {
            return back()->withErrors(['offer' => $result['error']]);
        }

        return redirect()->route('tenant.waitlist.offer.confirmed', ['token' => $token]);
    }

    /**
     * GET /waitlist/offer/{token}/confirmed  — post-accept thank-you page.
     */
    public function confirmed(string $token)
    {
        $tenant = tenant();
        $offer = TenantWaitlistOffer::with(['entry.serviceItem', 'resultingAppointment'])
            ->where('tenant_id', $tenant->id)
            ->where('offer_token', $token)
            ->first();
        abort_unless($offer, 404);
        abort_unless($offer->status === 'accepted', 404);

        return view('public.waitlist.offer-confirmed', [
            'tenant' => $tenant,
            'offer'  => $offer,
            'pageTitle' => 'Booking confirmed',
        ]);
    }
}
