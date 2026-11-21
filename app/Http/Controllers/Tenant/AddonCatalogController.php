<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Tenant;
use App\Services\AddonManagementService;
use App\Services\FeatureAccessService;
use App\Services\StripeBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * AddonCatalogController
 *
 * Tenant-facing catalog of purchasable addons. Staff ("tenant admin") users
 * browse available addons, purchase via modal, cancel existing ones.
 *
 * Response shape: {ok: true, data: ...} - consistent with ServiceController.
 */
class AddonCatalogController extends Controller
{
    public function __construct(
        protected FeatureAccessService $features,
        protected AddonManagementService $manager,
        protected StripeBillingService $stripe,
    ) {}

    public function index(Request $request)
    {
        $tenant = $this->currentTenant($request);
        $breakdown = $this->features->detailedFeatureBreakdown($tenant);

        // visibility decides what is SHOWN;
        // is_self_serve decides only whether the button activates. Filtering on
        // self-serve meant 27 add-ons were invisible to every shop, which is an
        // upsell nobody can discover.
        $tiles = $breakdown->filter(fn ($f) => ($f->visibility ?? 'self_serve') !== 'hidden');
        $grouped = $tiles->groupBy('category')->map->values();

        return view('tenant.addons.index', [
            'tenant' => $tenant,
            'grouped' => $grouped,
            'stripeLive' => $this->stripe->isLive(),
        ]);
    }

    public function activate(Request $request)
    {
        $validated = $request->validate([
            'addon_code' => 'required|string|max:64',
        ]);

        $tenant = $this->currentTenant($request);
        $code = $validated['addon_code'];

        $addon = Addon::where('code', $code)->active()->first();
        if (! $addon) {
            return response()->json(['ok' => false, 'error' => 'Unknown addon'], 404);
        }

        if (! $addon->is_self_serve) {
            return response()->json(['ok' => false, 'error' => 'This addon is not available for self-serve purchase. Contact support.'], 422);
        }

        if ($this->features->hasAddon($tenant, $code)) {
            return response()->json(['ok' => false, 'error' => 'You already have access to this feature.'], 422);
        }

        // MARKER-CONNECTED-POS-SECTION: an add-on with no price has nothing to bill, so it is
        // switched on directly. The plan floor is still enforced (and said plainly).
        if ($addon->currentPriceCents() <= 0) {
            try {
                // the audit log's actor id is a number (a master-admin user); a shop user's id is
                // not, so who did it is recorded by name and email instead
                $me = Auth::guard('tenant')->user();
                $this->manager->activate($tenant, $code, [
                    'source' => 'self_serve',
                    'actor_type' => 'tenant',
                    'actor_id' => null,
                    'actor_label' => $me ? trim($me->name . ' <' . $me->email . '>') : ('tenant ' . $tenant->id),
                    'reason' => 'self-serve, free add-on',
                ]);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'ok' => false,
                    'error' => $addon->min_plan_tier
                        ? $addon->name . ' needs the ' . ucfirst($addon->min_plan_tier) . ' plan or higher.'
                        : 'This add-on could not be turned on. Contact support.',
                ], 422);
            }

            return response()->json([
                'ok' => true,
                'data' => [
                    'addon_code' => $code,
                    'name' => $addon->name,
                    'status' => 'active',
                    'current_period_end' => null,
                    'proration_amount' => 0,
                    'stripe_live' => $this->stripe->isLive(),
                ],
            ]);
        }

        try {
            $stripeResult = $this->stripe->addSubscriptionItem($tenant, (object) $addon->toArray());
        } catch (\Throwable $e) {
            \Log::error('[AddonCatalog] payment failed', [
                'tenant_id' => $tenant->id,
                'addon_code' => $code,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'Payment could not be processed. Please try again or contact support.',
            ], 500);
        }

        $this->manager->activate($tenant, $code, [
            'source' => 'self_serve',
            'actor_type' => 'tenant',
            'actor_id' => Auth::id(),
            'actor_label' => Auth::user()?->name ?? ('tenant ' . $tenant->id),
            'reason' => 'self-serve purchase',
            'stripe_subscription_item_id' => $stripeResult['subscription_item_id'] ?? null,
            'stripe_price_id' => $stripeResult['price_id'] ?? null,
            'current_period_end' => $stripeResult['current_period_end'] ?? null,
            'metadata' => [
                'stripe_stub' => ! $this->stripe->isLive(),
                'proration_amount' => $stripeResult['proration_amount'] ?? 0,
            ],
        ]);

        return response()->json([
            'ok' => true,
            'data' => [
                'addon_code' => $code,
                'name' => $addon->name,
                'status' => 'active',
                'current_period_end' => $stripeResult['current_period_end'] ?? null,
                'proration_amount' => $stripeResult['proration_amount'] ?? 0,
                'stripe_live' => $this->stripe->isLive(),
            ],
        ]);
    }

    public function cancel(Request $request)
    {
        $validated = $request->validate([
            'addon_code' => 'required|string|max:64',
            'reason' => 'nullable|string|max:500',
        ]);

        $tenant = $this->currentTenant($request);
        $code = $validated['addon_code'];

        $row = DB::table('tenant_feature_addons')
            ->where('tenant_id', $tenant->id)
            ->where('addon_code', $code)
            ->whereIn('status', ['active', 'failed_payment'])
            ->first();

        if (! $row) {
            return response()->json(['ok' => false, 'error' => "You don't have an active subscription to this addon."], 404);
        }

        // MARKER-CONNECTED-POS-SECTION: turning this off with a POS still connected would leave
        // it syncing and sending with nowhere to see or stop it.
        if ($code === 'connected_pos' && \App\Models\Tenant\TenantPosConnection::where('tenant_id', $tenant->id)->exists()) {
            return response()->json([
                'ok' => false,
                'error' => 'Your Connected POS is still connected. Open Connected POS and click Disconnect first, then turn this off.',
            ], 422);
        }

        if ($row->source !== 'self_serve') {
            return response()->json([
                'ok' => false,
                'error' => 'This feature is provided by your plan or by Intake support. Contact us to change it.',
            ], 422);
        }

        if ($row->stripe_subscription_item_id) {
            $this->stripe->cancelSubscriptionItemAtPeriodEnd($tenant, $row->stripe_subscription_item_id);
        }

        $updated = $this->manager->cancel($tenant, $code, [
            'actor_type' => 'tenant',
            'actor_id' => is_int(Auth::id()) ? Auth::id() : null, // the audit log's actor id is a number; a shop user's is not
            'actor_label' => Auth::guard('tenant')->user()?->name ?? Auth::user()?->name ?? ('tenant ' . $tenant->id),
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'data' => [
                'addon_code' => $code,
                'status' => $updated->status ?? 'canceling',
                'access_until' => $updated->current_period_end ?? null,
            ],
        ]);
    }

    /**
     * Resolve current tenant via the app's tenant() helper.
     * Matches WaitlistPublicController and other tenant controllers.
     */
    protected function currentTenant(Request $request): Tenant
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            abort(404, 'Tenant not resolved.');
        }

        return $tenant;
    }
}
