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

        $tiles = $breakdown->filter(fn ($f) => $f->is_self_serve);
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
            'actor_id' => Auth::id(),
            'actor_label' => Auth::user()?->name ?? ('tenant ' . $tenant->id),
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
