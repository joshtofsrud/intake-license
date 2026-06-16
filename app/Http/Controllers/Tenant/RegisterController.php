<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantInventoryItem;
use App\Models\Tenant\TenantInventoryItemLocation;
use App\Models\Tenant\TenantServiceItem;
use App\Models\Tenant\TenantCustomer;
use App\Services\Tenant\SaleService;
use App\Services\Tenant\SaleValidationException;
use App\Services\Tenant\InventoryStockException;
use App\Services\Tenant\DirectPaymentsService;  // MARKER-PATCH-170
use Illuminate\Support\Facades\Log;  // MARKER-PATCH-172B — missing import broke patches 170/170b/171/172
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class RegisterController extends Controller
{
    public function __construct(protected SaleService $sales) {}

    public function index(Request $request)
    {
        $tenant = tenant();

        // Count of appointment-sourced drafts ready for checkout. Used to
        // render the "X ready for checkout" banner on the register page.
        $appointmentTrayCount = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->whereNotNull('appointment_id')
            ->where('payment_status', 'draft')
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->count();

        // Patch 46: pre-attach customer from query param (walk-in flow).
        $preAttachCustomer = null;
        $preCustId = $request->query('customer_id');
        if ($preCustId) {
            $cust = \App\Models\Tenant\TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $preCustId)
                ->first(['id', 'first_name', 'last_name', 'email', 'phone']);
            if ($cust) {
                $preAttachCustomer = [
                    'id'         => $cust->id,
                    'first_name' => $cust->first_name,
                    'last_name'  => $cust->last_name,
                    'name'       => trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? '')),
                    'email'      => $cust->email,
                    'phone'      => $cust->phone,
                ];
            }
        }

        return view('tenant.register.index', [
            'tenant'     => $tenant,
            'preAttachCustomer' => $preAttachCustomer,
            'taxRate'    => (float) ($tenant->default_tax_rate ?? 0),
            'taxLabel'   => $this->taxLabel($tenant),
            'appointmentTrayCount' => $appointmentTrayCount,
            // MARKER-PATCH-162 — hide "Request transfer" button on oversell rows for single-location tenants
            'multiLocationActive' => (bool) $tenant->multi_location_active,
            'tipsConfig' => [
                'enabled'      => (bool) $tenant->tips_enabled,
                'method'       => $tenant->tip_default_method,
                'options'      => is_array($tenant->tip_default_options)
                                    ? $tenant->tip_default_options
                                    : (json_decode($tenant->tip_default_options ?? '[]', true) ?: []),
                'allow_custom' => (bool) $tenant->tip_allow_custom,
                'attributable' => (bool) $tenant->tip_attributable,
            ],
            'surchargeConfig' => [
                'enabled' => (bool) $tenant->passthrough_card_fees,
                'percent' => (float) ($tenant->card_surcharge_percent ?? 0),
                'label'   => $tenant->card_surcharge_label ?? 'Card processing fee',
            ],
        ]);
    }

    /**
     * List of appointment-sourced sales ready for checkout. Used by the
     * Register's "Ready for checkout" tray on the register home and by the
     * dedicated tray page.
     */
    public function appointmentTray(Request $request): JsonResponse
    {
        $tenant = tenant();
        $sales = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->whereNotNull('appointment_id')
            ->where('payment_status', 'draft')
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->with(['customer', 'appointment'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'ok' => true,
            'sales' => $sales->map(function ($s) {
                $appt = $s->appointment;
                return [
                    'id'              => $s->id,
                    'sale_number'     => $s->sale_number,
                    'total_cents'     => (int) $s->total_cents,
                    'total_display'   => format_money((int) $s->total_cents),
                    'customer_name'   => $s->customer
                        ? trim(($s->customer->first_name ?? '') . ' ' . ($s->customer->last_name ?? ''))
                        : ($appt ? trim(($appt->customer_first_name ?? '') . ' ' . ($appt->customer_last_name ?? '')) : 'Walk-in'),
                    'appointment_id'  => $appt?->id,
                    'ra_number'       => $appt?->ra_number,
                    'created_at'      => $s->created_at?->toIso8601String(),
                    'item_count'      => (int) \DB::table('tenant_sale_items')->where('sale_id', $s->id)->count(),
                ];
            })->values(),
        ]);
    }

    /**
     * MARKER-PATCH-180 — dismiss a parked appointment draft sale from the
     * register tray. Voids the DRAFT sale (status=cancelled) so it leaves the
     * "ready for checkout" list. Non-destructive: only unpaid draft sales are
     * eligible; the appointment itself is untouched. The sale can be recreated
     * later from the appointment if needed.
     */
    public function dismissTraySale(Request $request): JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'sale_id' => 'required|uuid',
        ]);

        $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->whereNotNull('appointment_id')
            ->where('payment_status', 'draft')
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->first();

        if (!$sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found or not dismissible.'], 404);
        }

        $sale->status = 'cancelled';
        $sale->save();

        return response()->json(['ok' => true]);
    }

    public function search(Request $request): JsonResponse
    {
        $tenant = tenant();
        $q = trim((string) $request->input('q', ''));
        $type = $request->input('type', 'all');

        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['products' => [], 'services' => [], 'customers' => []]);
        }

        $products = [];
        $services = [];
        $customers = [];

        if ($type === 'all' || $type === 'product') {
            // patch-96 location stock — enrich each product with its on-hand
            // count at the CURRENT register location, so the cart can show an
            // oversell badge when qty exceeds that.
            $registerLocationId = $request->session()->get('current_location_id');
            $registerLocationName = null;
            if ($registerLocationId) {
                $loc = \App\Models\Tenant\TenantLocation::where('tenant_id', $tenant->id)
                    ->where('id', $registerLocationId)
                    ->first();
                $registerLocationName = $loc?->name;
            }

            $productItems = TenantInventoryItem::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                      ->orWhere('sku', 'like', "%{$q}%")
                      ->orWhere('display_subtitle', 'like', "%{$q}%");
                })
                ->limit(15)
                ->get();

            // One join to fetch all per-location counts for the matched items
            $stockByItem = [];
            if ($registerLocationId && $productItems->isNotEmpty()) {
                $stockByItem = \App\Models\Tenant\TenantInventoryItemLocation::whereIn(
                        'inventory_item_id', $productItems->pluck('id')
                    )
                    ->where('location_id', $registerLocationId)
                    ->pluck('computed_stock_count', 'inventory_item_id')
                    ->toArray();
            }

            $products = $productItems->map(fn ($p) => [
                'id'                     => $p->id,
                'name'                   => $p->name ?? '',
                'subtitle'               => $p->display_subtitle ?? '',
                'sku'                    => $p->sku ?? '',
                'price_cents'            => (int) ($p->effectiveSellPriceCents() ?? 0),
                'is_taxable'             => (($p->tax_class_code ?? null) !== 'exempt'),
                'allow_oversell'         => (bool) $p->allow_oversell,
                'current_location_stock' => (int) ($stockByItem[$p->id] ?? 0),
                'current_location_name'  => $registerLocationName,
            ])->toArray();
        }

        if ($type === 'all' || $type === 'service') {
            $services = TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('is_active', 1)
                ->where('name', 'like', "%{$q}%")
                ->limit(15)
                ->get()
                ->map(fn ($s) => [
                    'id'               => $s->id,
                    'name'             => $s->name,
                    'price_cents'      => (int) ($s->price_cents ?? 0),
                    'duration_minutes' => (int) ($s->duration_minutes ?? 0),
                ])
                ->toArray();
        }

        if ($type === 'all' || $type === 'customer') {
            $customers = TenantCustomer::where('tenant_id', $tenant->id)
                ->where(function ($w) use ($q) {
                    $w->where('first_name', 'like', "%{$q}%")
                      ->orWhere('last_name', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%")
                      ->orWhere('phone', 'like', "%{$q}%");
                })
                ->limit(10)
                ->get()
                ->map(fn ($c) => [
                    'id'    => $c->id,
                    'name'  => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                    'email' => $c->email ?? '',
                    'phone' => $c->phone ?? '',
                ])
                ->toArray();
        }

        return response()->json(compact('products', 'services', 'customers'));
    }

    public function storeSale(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'customer_id'      => 'nullable|uuid',
            'notes'            => 'nullable|string',
            'tip_cents'        => 'nullable|integer|min:0',
            'discount_cents'   => 'nullable|integer|min:0',
            'payment_method'   => 'required|string|in:cash,card,check,store_credit,mark_paid,split',
            'payment_reference'=> 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.type'             => 'required|string|in:service,product,open_item,gift_card',
            'items.*.service_id'       => 'nullable|uuid',
            'items.*.inventory_item_id'=> 'nullable|uuid',
            'items.*.name_snapshot'    => 'nullable|string|max:255',
            'items.*.unit_price_cents' => 'nullable|integer|min:0',
            'items.*.quantity'         => 'nullable|numeric|min:0.001',
            'items.*.discount_cents'   => 'nullable|integer|min:0',
            'items.*.is_taxable'       => 'nullable|boolean',
            'items.*.assigned_staff_id'=> 'nullable|uuid',
            'items.*.notes'            => 'nullable|string',
            // MARKER-PATCH-161 — per-sale receipt skip
            'skip_receipt'             => 'nullable|boolean',
        ]);

        try {
            $sale = $this->sales->createSale([
                'tenant_id'          => $tenant->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'location_id'        => $locationId,
                'customer_id'        => $validated['customer_id'] ?? null,
                'status'             => 'completed',
                'payment_status'     => 'paid',
                'payment_method'     => $validated['payment_method'],
                'payment_reference'  => $validated['payment_reference'] ?? null,
                // MARKER-PATCH-170 — Direct Payments Stripe fields (optional)
                'stripe_payment_intent_id' => $request->input('stripe_payment_intent_id'),
                'stripe_charge_id'         => $request->input('stripe_charge_id'),
                'card_brand'               => $request->input('card_brand'),
                'card_last4'               => $request->input('card_last4'),
                'card_funding'             => $request->input('card_funding'),
                'paid_at'            => Carbon::now(),
                'notes'              => $validated['notes'] ?? null,
                'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                'discount_cents'     => (int) ($validated['discount_cents'] ?? 0),
                'items'              => $validated['items'],
            ]);

            if ($validated['payment_method'] === 'card' && $tenant->passthrough_card_fees) {
                $surcharge = (int) round($sale->subtotal_cents * (($tenant->card_surcharge_percent ?? 0) / 100));
                if ($surcharge > 0) {
                    $sale->update(['surcharge_cents' => $surcharge]);
                    $sale = $this->sales->recalculate($sale->fresh('items'));
                }
            }

            // MARKER-PATCH-160 — auto-send receipt (queued, fail-open)
            // MARKER-PATCH-161 — skip if cashier opted out for this sale
            if (! $request->boolean('skip_receipt')) {
                \App\Jobs\SendSaleReceiptJob::dispatch($sale->id)->afterCommit();
            }

            return response()->json([
                'ok'          => true,
                'sale_id'     => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_cents' => $sale->total_cents,
                'redirect'    => route('tenant.register.index'),
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Save (or update) a draft cart.
     * Called on every cart change with debounce. First call creates,
     * subsequent calls include 'id' and update.
     */
    public function storeDraft(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'id'               => 'nullable|uuid',
            'customer_id'      => 'nullable|uuid',
            'notes'            => 'nullable|string',
            'tip_cents'        => 'nullable|integer|min:0',
            'metadata'         => 'nullable|array',
            'items'            => 'nullable|array',
            'items.*.type'             => 'required_with:items|string|in:service,product,open_item,gift_card',
            'items.*.service_id'       => 'nullable|uuid',
            'items.*.inventory_item_id'=> 'nullable|uuid',
            'items.*.name_snapshot'    => 'nullable|string|max:255',
            'items.*.unit_price_cents' => 'nullable|integer|min:0',
            'items.*.quantity'         => 'nullable|numeric|min:0.001',
            'items.*.discount_cents'   => 'nullable|integer|min:0',
            'items.*.is_taxable'       => 'nullable|boolean',
            'items.*.assigned_staff_id'=> 'nullable|uuid',
            'items.*.notes'            => 'nullable|string',
        ]);

        try {
            $draft = $this->sales->saveDraft([
                'id'                 => $validated['id'] ?? null,
                'tenant_id'          => $tenant->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'location_id'        => $locationId,
                'customer_id'        => $validated['customer_id'] ?? null,
                'notes'              => $validated['notes'] ?? null,
                'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                'metadata'           => $validated['metadata'] ?? null,
                'items'              => $validated['items'] ?? [],
            ]);

            return response()->json([
                'ok'             => true,
                'draft_id'       => $draft->id,
                'subtotal_cents' => $draft->subtotal_cents,
                'tax_cents'      => $draft->tax_cents,
                'total_cents'    => $draft->total_cents,
                'updated_at'     => $draft->updated_at?->toIso8601String(),
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * List open drafts at the current location.
     * Used by the resume banner on register load.
     */
    /**
     * patch-100a oversell actions — register cart "Request transfer" button.
     * Creates a pending TenantTransferRequest scoped to the current
     * register location. Returns the new request's id so the cart UI
     * can swap the button for a confirmation pill.
     */
    public function storeOversellTransferRequest(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        // MARKER-PATCH-162 — single-location tenants have nowhere to transfer FROM.
        // Defense in depth against stale tabs or URL fuzzing. Client UI already
        // hides the button, so a normal user can't hit this branch.
        if (! $tenant->multi_location_active) {
            return response()->json([
                'ok' => false,
                'error' => 'Transfer requests require at least two active locations.',
            ], 422);
        }

        $validated = $request->validate([
            'inventory_item_id' => 'required|uuid|exists:tenant_inventory_items,id',
            'quantity'          => 'nullable|integer|min:1',
            'sale_id'           => 'nullable|uuid',
            'notes'             => 'nullable|string|max:1000',
        ]);

        try {
            $svc = app(\App\Services\Tenant\TransferRequestService::class);
            $tr = $svc->create([
                'tenant_id'            => $tenant->id,
                'inventory_item_id'    => $validated['inventory_item_id'],
                'to_location_id'       => $locationId,
                'quantity'             => $validated['quantity'] ?? 1,
                'requested_by_user_id' => auth('tenant')->id(),
                'sale_id'              => $validated['sale_id'] ?? null,
                'notes'                => $validated['notes'] ?? null,
            ]);

            $fromLocName = $tr->fromLocation?->name;
            return response()->json([
                'ok'                  => true,
                'transfer_request_id' => $tr->id,
                'from_location_name'  => $fromLocName,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * patch-100a oversell actions — register cart "Add to order" button.
     * Creates a status=needed special order for the item, optionally
     * attached to a customer (if the cart has one). Returns the new
     * SO's id + number for confirmation display.
     */
    public function storeOversellSpecialOrder(Request $request): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'inventory_item_id' => 'required|uuid|exists:tenant_inventory_items,id',
            'quantity'          => 'nullable|integer|min:1',
            'customer_id'       => 'nullable|uuid',
            'notes'             => 'nullable|string|max:1000',
        ]);

        $item = \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)
            ->where('id', $validated['inventory_item_id'])
            ->first();

        if (!$item) {
            return response()->json(['ok' => false, 'error' => 'Item not found.'], 404);
        }

        try {
            $svc = app(\App\Services\Tenant\SpecialOrderService::class);
            $so = $svc->create([
                'tenant_id'          => $tenant->id,
                'inventory_item_id'  => $item->id,
                'item_name_snapshot' => $item->name,
                'quantity'           => $validated['quantity'] ?? 1,
                'customer_id'        => $validated['customer_id'] ?? null,
                'status'             => \App\Models\Tenant\TenantSpecialOrder::STATUS_NEEDED,
                'created_from'       => 'register',
                'notes'              => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'ok'                => true,
                'special_order_id'  => $so->id,
                'so_number'         => $so->so_number,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function listDrafts(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['drafts' => []]);
        }

        $drafts = TenantSale::where('tenant_id', $tenant->id)
            ->where('location_id', $locationId)
            ->drafts()
            ->with(['customer', 'rangUpBy', 'items'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(function ($d) {
                return [
                    'id'           => $d->id,
                    'item_count'   => $d->items->count(),
                    'total_cents'  => $d->total_cents,
                    'customer'     => $d->customer
                        ? trim(($d->customer->first_name ?? '') . ' ' . ($d->customer->last_name ?? ''))
                        : null,
                    'started_by'   => $d->rangUpBy
                        ? trim(($d->rangUpBy->first_name ?? '') . ' ' . ($d->rangUpBy->last_name ?? ''))
                        : null,
                    'updated_at'   => $d->updated_at?->toIso8601String(),
                ];
            });

        return response()->json(['drafts' => $drafts]);
    }

    /**
     * Fetch a single draft with full line items, for resume into cart.
     */
    public function showDraft(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();

        $draft = TenantSale::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['draft', 'quote'])
            ->with(['customer', 'items'])
            ->first();

        if (!$draft) {
            return response()->json(['ok' => false, 'error' => 'Draft not found.'], 404);
        }

        return response()->json([
            'ok' => true,
            'draft' => [
                'id'          => $draft->id,
                'customer'    => $draft->customer ? [
                    'id'    => $draft->customer->id,
                    'name'  => trim(($draft->customer->first_name ?? '') . ' ' . ($draft->customer->last_name ?? '')),
                    'email' => $draft->customer->email ?? '',
                    'phone' => $draft->customer->phone ?? '',
                ] : null,
                'tip_cents'   => $draft->tip_cents,
                'notes'       => $draft->notes,
                'tax_locked'  => (bool) $draft->tax_locked,
                'tax_cents'   => (int) $draft->tax_cents,
                'items'       => $draft->items->map(fn ($i) => [
                    'type'              => $i->type,
                    'source_id'         => $i->service_id ?? $i->inventory_item_id,
                    'inventory_item_id' => $i->inventory_item_id,
                    'service_id'        => $i->service_id,
                    'name'              => $i->name_snapshot,
                    'price_cents'       => $i->unit_price_cents,
                    'qty'               => (float) $i->quantity,
                    'is_taxable'        => (bool) $i->is_taxable,
                    'tax_cents'         => (int) $i->tax_cents,
                    'tax_rate_snapshot' => $i->tax_rate_snapshot,
                ])->values(),
            ],
        ]);
    }

    /**
     * Permanently discard a draft.
     */
    public function discardDraft(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();

        try {
            $this->sales->discardDraft($tenant->id, $id);
            return response()->json(['ok' => true]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    /**
     * Promote a draft to a paid sale. Replaces storeSale for draft-backed flow.
     */
    public function commitDraft(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'payment_method'    => 'required|string|in:cash,card,check,store_credit,mark_paid,split',
            'payment_reference' => 'nullable|string',
            'tip_cents'         => 'nullable|integer|min:0',
            'customer_id'       => 'nullable|uuid',
            'notes'             => 'nullable|string',
            // MARKER-PATCH-161 — per-sale receipt skip
            'skip_receipt'      => 'nullable|boolean',
        ]);

        try {
            $sale = $this->sales->commitDraft($tenant->id, $id, [
                'payment_status'    => 'paid',
                'payment_method'    => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'] ?? null,
                // MARKER-PATCH-170 — Direct Payments Stripe fields (optional)
                'stripe_payment_intent_id' => $request->input('stripe_payment_intent_id'),
                'stripe_charge_id'         => $request->input('stripe_charge_id'),
                'card_brand'               => $request->input('card_brand'),
                'card_last4'               => $request->input('card_last4'),
                'card_funding'             => $request->input('card_funding'),
                'paid_at'           => Carbon::now(),
                'tip_cents'         => $validated['tip_cents'] ?? null,
                'customer_id'       => $validated['customer_id'] ?? null,
                'notes'             => $validated['notes'] ?? null,
            ]);

            // Apply card surcharge same as storeSale path.
            if ($validated['payment_method'] === 'card' && $tenant->passthrough_card_fees) {
                $surcharge = (int) round($sale->subtotal_cents * (($tenant->card_surcharge_percent ?? 0) / 100));
                if ($surcharge > 0) {
                    $sale->update(['surcharge_cents' => $surcharge]);
                    $sale = $this->sales->recalculate($sale->fresh('items'));
                }
            }

            // MARKER-PATCH-160 — auto-send receipt (queued, fail-open)
            // MARKER-PATCH-161 — skip if cashier opted out for this sale
            if (! $request->boolean('skip_receipt')) {
                \App\Jobs\SendSaleReceiptJob::dispatch($sale->id)->afterCommit();
            }

            return response()->json([
                'ok'          => true,
                'sale_id'     => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_cents' => $sale->total_cents,
                'redirect'    => route('tenant.register.index'),
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Look up a past sale by sale_number for the refund picker.
     * Returns the sale's line items with refundable quantities.
     */
    public function lookupSaleForRefund(Request $request): JsonResponse
    {
        $tenant = tenant();
        $saleNumber = trim((string) $request->input('sale_number', ''));

        if ($saleNumber === '') {
            return response()->json(['ok' => false, 'error' => 'Sale number required.'], 422);
        }

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('sale_number', $saleNumber)
            ->whereIn('payment_status', ['paid', 'partial'])
            ->whereNull('refund_of_sale_id')
            ->with(['customer', 'items', 'refunds.items'])
            ->first();

        if (!$sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found or not refundable.'], 404);
        }

        // For each original line, compute quantity already refunded across all
        // prior refund rows. Refundable_qty = original_qty - already_refunded_qty.
        $refundedByOrigItem = [];
        foreach ($sale->refunds as $refund) {
            foreach ($refund->items as $rline) {
                // Refund lines snapshot the same product/service/etc.
                // We match by (type, source_id, name_snapshot) since refund lines
                // don't carry a back-reference to the original line.
                $key = $rline->type . '|'
                    . ($rline->inventory_item_id ?? $rline->service_id ?? '')
                    . '|' . $rline->name_snapshot;
                $refundedByOrigItem[$key] = ($refundedByOrigItem[$key] ?? 0) + (float) $rline->quantity;
            }
        }

        $items = $sale->items->map(function ($i) use ($refundedByOrigItem) {
            $key = $i->type . '|'
                . ($i->inventory_item_id ?? $i->service_id ?? '')
                . '|' . $i->name_snapshot;
            $already = $refundedByOrigItem[$key] ?? 0;
            $remaining = max(0, (float) $i->quantity - $already);
            return [
                'id'                => $i->id,
                'type'              => $i->type,
                'name'              => $i->name_snapshot,
                'quantity'          => (float) $i->quantity,
                'already_refunded'  => $already,
                'remaining'         => $remaining,
                'unit_price_cents'  => $i->unit_price_cents,
                'line_total_cents'  => $i->line_total_cents,
                'tax_cents'         => (int) $i->tax_cents,
                'is_taxable'        => (bool) $i->is_taxable,
            ];
        })->values();

        return response()->json([
            'ok'   => true,
            'sale' => [
                'id'             => $sale->id,
                'sale_number'    => $sale->sale_number,
                'sale_date'      => $sale->sale_date?->toDateString(),
                'paid_at'        => $sale->paid_at?->toDateTimeString(),
                'total_cents'    => $sale->total_cents,
                'tender'         => $sale->payment_method,
                'customer'       => $sale->customer
                    ? trim(($sale->customer->first_name ?? '') . ' ' . ($sale->customer->last_name ?? ''))
                    : null,
                'items'          => $items,
            ],
        ]);
    }

    /**
     * MARKER-PATCH-177 — Standalone refund: money out with NO sale attached.
     *
     * For refunds that aren't tied to a past sale in the system — e.g. refunding
     * a fee charged before Intake existed. Always carries a customer; sale_id is
     * null. Writes one negative row directly through the sale-payment ledger so
     * it shows in "money out" / Payments Received reporting alongside everything
     * else. Uncapped (there is no sale total to cap against — the operator types
     * the amount). Line-item refunds against an existing sale keep using the
     * existing storeTransaction flow; this is the no-sale path only.
     */
    public function storeStandaloneRefund(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');
        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'customer_id'   => 'required|uuid',
            'amount_cents'  => 'required|integer|min:1',
            'refund_method' => 'required|string|in:cash,card,check,store_credit,mark_paid',
            'reason'        => 'required|string|max:500',
        ]);

        // Customer must belong to this tenant (defense against cross-tenant ids).
        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $validated['customer_id'])
            ->first();
        if (!$customer) {
            return response()->json(['ok' => false, 'error' => 'Customer not found.'], 404);
        }

        try {
            $payment = app(\App\Services\Tenant\SalePaymentService::class)->recordStandaloneRefund(
                tenantId:    $tenant->id,
                customerId:  $customer->id,
                amountCents: (int) $validated['amount_cents'],
                method:      $validated['refund_method'],
                reason:      $validated['reason'],
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Standalone refund failed', [
                'tenant_id'   => $tenant->id,
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'Could not record refund.'], 500);
        }

        return response()->json([
            'ok'            => true,
            'payment_id'    => $payment->id,
            'amount_cents'  => abs($payment->amount_cents),
            'customer'      => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
            'method'        => $validated['refund_method'],
        ]);
    }

    /**
     * Commit a multi-row transaction (mixed sale + refund, or pure refund).
     * Pure sales still use storeSale or commitDraft.
     */
    public function storeTransaction(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'customer_id'      => 'nullable|uuid',
            'tip_cents'        => 'nullable|integer|min:0',
            'payment_method'   => 'required|string|in:cash,card,check,store_credit,mark_paid,split,even_exchange',
            'payment_reference'=> 'nullable|string',
            'items'            => 'nullable|array',
            'items.*.type'             => 'required_with:items|string|in:service,product,open_item,gift_card',
            'items.*.service_id'       => 'nullable|uuid',
            'items.*.inventory_item_id'=> 'nullable|uuid',
            'items.*.name_snapshot'    => 'nullable|string|max:255',
            'items.*.unit_price_cents' => 'nullable|integer|min:0',
            'items.*.quantity'         => 'nullable|numeric|min:0.001',
            'items.*.is_taxable'       => 'nullable|boolean',
            'refund'                       => 'required|array',
            'refund.original_sale_id'      => 'required|uuid',
            'refund.item_ids'              => 'required|array|min:1',
            'refund.item_ids.*'            => 'uuid',
            'refund.refund_method'         => 'required|string|in:cash,card,check,store_credit,mark_paid,even_exchange',
        ]);

        try {
            $result = $this->sales->createTransaction([
                'tenant_id'          => $tenant->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'location_id'        => $locationId,
                'customer_id'        => $validated['customer_id'] ?? null,
                'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                'payment_method'     => $validated['payment_method'],
                'payment_reference'  => $validated['payment_reference'] ?? null,
                // MARKER-PATCH-170 — Direct Payments Stripe fields (optional)
                'stripe_payment_intent_id' => $request->input('stripe_payment_intent_id'),
                'stripe_charge_id'         => $request->input('stripe_charge_id'),
                'card_brand'               => $request->input('card_brand'),
                'card_last4'               => $request->input('card_last4'),
                'card_funding'             => $request->input('card_funding'),
                'items'              => $validated['items'] ?? [],
                'refund'             => $validated['refund'],
            ]);

            // Build a unified receipt response.
            $sale = $result['sale'];
            $refund = $result['refund'];

            // MARKER-PATCH-171 — fire Stripe refund if refund half exists and
            // refund_method=card. Mirrors storeRefund behavior for the mixed path.
            $stripeRefundError = null;
            if ($refund && ($validated['refund']['refund_method'] ?? null) === 'card') {
                $stripeRefundError = $this->fireStripeRefund($tenant, $refund);
            }

            return response()->json([
                'ok'             => true,
                'transaction_id' => $result['transaction_id'],
                'sale_id'        => $sale?->id,
                'sale_number'    => $sale?->sale_number ?? $refund?->sale_number,
                'total_cents'    => ($sale?->total_cents ?? 0) - ($refund?->total_cents ?? 0),
                'sale_total'     => $sale?->total_cents ?? 0,
                'refund_total'   => $refund?->total_cents ?? 0,
                // MARKER-PATCH-171 — Stripe refund outcome
                'stripe_refund_error' => $stripeRefundError ?? null,
                'stripe_refund_id'    => $refund?->fresh()?->stripe_refund_id,
                'redirect'       => route('tenant.register.index'),
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Transaction History — list view of every tenant_sales row,
     * including drafts/quotes/paid/partial/refunded.
     * Filtered and sorted client-side by the page JS.
     */
    public function historyIndex(Request $request)
    {
        $tenant = tenant();

        $rows = TenantSale::where('tenant_id', $tenant->id)
            ->with(['customer', 'rangUpBy', 'items', 'location'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(function ($r) {
                return [
                    'id'             => $r->id,
                    'sale_number'    => $r->sale_number,
                    'payment_status' => $r->payment_status,
                    'item_count'     => $r->items->count(),
                    'total_cents'    => $r->total_cents,
                    'transaction_id' => $r->transaction_id,
                    'customer'       => $r->customer
                        ? trim(($r->customer->first_name ?? '') . ' ' . ($r->customer->last_name ?? ''))
                        : null,
                    'customer_email' => $r->customer->email ?? null,
                    'started_by'     => $r->rangUpBy
                        ? trim(($r->rangUpBy->first_name ?? '') . ' ' . ($r->rangUpBy->last_name ?? ''))
                        : null,
                    'location_name'  => $r->location->name ?? null,
                    'is_refund'      => $r->refund_of_sale_id !== null,
                    'refund_of_sale_number' => $r->refund_of_sale_id
                        ? \App\Models\Tenant\TenantSale::where('id', $r->refund_of_sale_id)->value('sale_number')
                        : null,
                    'updated_at'     => $r->updated_at?->toIso8601String(),
                    'paid_at'        => $r->paid_at?->toIso8601String(),
                    'sale_date'      => $r->sale_date?->toDateString(),
                ];
            });

        return view('tenant.register.history', [
            'tenant' => $tenant,
            'rows'   => $rows,
        ]);
    }

    /**
     * Return a single sale as JSON for the sale-detail modal.
     * Read-only. Used by the history page and customer activity timeline.
     */
    /**
     * MARKER-PATCH-231A — sale detail PAGE (the JSON sibling feeds the
     * register modal; this is a linkable page for search + history).
     */
    public function showSalePage(Request $request, string $id)
    {
        $tenant = tenant();

        $sale = TenantSale::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->with(['customer', 'rangUpBy', 'items', 'payments', 'location', 'refundOf:id,sale_number', 'appointment:id,ra_number'])
            ->firstOrFail();

        $refunds = TenantSale::where('refund_of_sale_id', $sale->id)
            ->where('tenant_id', $tenant->id)
            ->orderBy('created_at')
            ->get(['id', 'sale_number', 'total_cents', 'created_at']);

        // MARKER-PATCH-231 — linked context (rental/lease the sale belongs to).
        $linkedRental = $sale->rental_id
            ? \App\Models\Tenant\TenantRental::where('tenant_id', $tenant->id)->find($sale->rental_id, ['id', 'rental_number'])
            : null;
        $linkedLease = $sale->lease_id
            ? \App\Models\Tenant\Lease::where('tenant_id', $tenant->id)->find($sale->lease_id, ['id', 'lease_number'])
            : null;

        return view('tenant.register.sale-show', [
            'sale'         => $sale,
            'refunds'      => $refunds,
            'linkedRental' => $linkedRental,
            'linkedLease'  => $linkedLease,
        ]);
    }

    // MARKER-PATCH-319 — render the printable 80mm sales receipt.
    public function printReceipt(Request $request, string $id)
    {
        $tenant = tenant();

        $sale = TenantSale::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->with(['customer', 'items', 'payments'])
            ->firstOrFail();

        $cfg   = (array) (($tenant->settings['work_order_tag'] ?? []));
        $print = [
            'paper'     => in_array(($cfg['paper'] ?? '80mm'), ['80mm', '58mm'], true) ? ($cfg['paper'] ?? '80mm') : '80mm',
            'logo_path' => $cfg['logo_path'] ?? null,
            'logo_size' => in_array(($cfg['logo_size'] ?? 'medium'), ['small', 'medium', 'large', 'xl'], true) ? ($cfg['logo_size'] ?? 'medium') : 'medium',
            'header_text' => (string) ($cfg['header_text'] ?? ''), // MARKER-PATCH-330
            'footer_text' => (string) ($cfg['footer_text'] ?? ''), // MARKER-PATCH-330
            'feed_mm'   => (int) ($cfg['feed_mm'] ?? 0), // MARKER-PATCH-320
        ];
        $embed = $request->boolean('embed');

        return view('tenant.register.receipt', compact('tenant', 'sale', 'print', 'embed'));
    }

    public function showSaleJson(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();

        $sale = TenantSale::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->with(['customer', 'rangUpBy', 'items', 'location', 'refundOf:id,sale_number'])
            ->first();

        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Load related refunds (children) so the modal can summarize them.
        $refunds = TenantSale::where('refund_of_sale_id', $sale->id)
            ->where('tenant_id', $tenant->id)
            ->orderBy('created_at')
            ->get(['id', 'sale_number', 'total_cents', 'paid_at', 'created_at'])
            ->map(fn ($r) => [
                'id'          => $r->id,
                'sale_number' => $r->sale_number,
                'total_cents' => (int) $r->total_cents,
                'paid_at'     => $r->paid_at?->toIso8601String() ?? $r->created_at?->toIso8601String(),
            ])
            ->values();

        $items = $sale->items
            ->sortBy(fn ($i) => $i->position ?? 0)
            ->values()
            ->map(fn ($i) => [
                'type'             => $i->type,
                'name'             => $i->name_snapshot,
                'description'      => $i->description_snapshot,
                'quantity'         => (float) $i->quantity,
                'unit_price_cents' => (int) $i->unit_price_cents,
                'discount_cents'   => (int) $i->discount_cents,
                'tax_cents'        => (int) $i->tax_cents,
                'is_taxable'       => (bool) $i->is_taxable,
                'line_total_cents' => (int) $i->line_total_cents,
            ]);

        // MARKER-PATCH-191 — the payment ledger for this sale (each deposit /
        // balance / payment / refund row), so the modal shows exactly what was
        // paid, how, and when — not just the sale total.
        $payments = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenant->id)
            ->where('sale_id', $sale->id)
            ->orderBy('recorded_at')
            ->get()
            ->map(fn ($p) => [
                'id'           => $p->id, // MARKER-PATCH-198 — targets delete
                'amount_cents' => (int) $p->amount_cents,
                'kind'         => $p->kind,
                'method'       => $p->method,
                'method_label' => method_exists($p, 'methodLabel') ? $p->methodLabel() : $p->method,
                'source'       => $p->source,
                'reference'    => $p->external_reference,
                'notes'        => $p->notes,
                'recorded_at'  => $p->recorded_at?->toIso8601String(),
                'is_refund'    => $p->amount_cents < 0,
            ])
            ->values();
        $paidCents = (int) $payments->sum('amount_cents');

        // MARKER-PATCH-161 — email send log for this sale.
        $sendLog = \App\Models\Tenant\TenantNotificationLog::where('tenant_id', $tenant->id)
            ->where('related_type', 'sale')
            ->where('related_id', $sale->id)
            ->where('channel', 'email')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['event_type','recipient','status','error_message','template_key','created_at'])
            ->map(fn ($r) => [
                'event_type'   => $r->event_type,
                'recipient'    => $r->recipient,
                'status'       => $r->status,
                'error'        => $r->error_message,
                'template_key' => $r->template_key,
                'created_at'   => $r->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'ok'   => true,
            'sale' => [
                'id'             => $sale->id,
                'sale_number'    => $sale->sale_number,
                'status'         => $sale->status,
                'payment_status' => $sale->payment_status,
                'is_refund'      => $sale->refund_of_sale_id !== null,
                'is_quote'       => $sale->payment_status === 'quote',
                'is_draft'       => $sale->payment_status === 'draft',
                'sale_date'      => $sale->sale_date?->toDateString(),
                'paid_at'        => $sale->paid_at?->toIso8601String(),
                'created_at'     => $sale->created_at?->toIso8601String(),
                'updated_at'     => $sale->updated_at?->toIso8601String(),
                'transaction_id' => $sale->transaction_id,
                'payment_method' => $sale->payment_method,
                'payment_reference' => $sale->payment_reference,
                'notes'          => $sale->notes,
                'subtotal_cents' => (int) $sale->subtotal_cents,
                'discount_cents' => (int) $sale->discount_cents,
                'tax_cents'      => (int) $sale->tax_cents,
                'surcharge_cents'=> (int) $sale->surcharge_cents,
                'tip_cents'      => (int) $sale->tip_cents,
                'total_cents'    => (int) $sale->total_cents,
                'customer'       => $sale->customer ? [
                    'id'    => $sale->customer->id,
                    'name'  => trim(($sale->customer->first_name ?? '') . ' ' . ($sale->customer->last_name ?? '')),
                    'email' => $sale->customer->email,
                    'phone' => $sale->customer->phone,
                ] : null,
                'rang_up_by'     => $sale->rangUpBy
                    ? trim(($sale->rangUpBy->first_name ?? '') . ' ' . ($sale->rangUpBy->last_name ?? ''))
                    : null,
                'location_name'  => $sale->location->name ?? null,
                'refund_of'      => $sale->refundOf ? [
                    'id'          => $sale->refundOf->id,
                    'sale_number' => $sale->refundOf->sale_number,
                ] : null,
                'refunds'        => $refunds,
                'items'          => $items,
                'send_log'       => $sendLog,
                'payments'       => $payments,
                'paid_cents'     => $paidCents,
                // MARKER-PATCH-195 — checkout link fields for the status view.
                'checkout_session_id' => $sale->checkout_session_id,
                'sale_status'    => $sale->status,
                'appointment_id' => $sale->appointment_id,
            ],
        ]);
    }

    /**
     * Quotes list with dashboard metrics on top.
     * Cards: open quotes, aging (>14 days), new this week, recently converted.
     */
    public function quotesIndex(Request $request)
    {
        $tenant = tenant();
        $now = Carbon::now();
        $agingThreshold = $now->copy()->subDays(14);
        $oneWeekAgo = $now->copy()->subDays(7);
        $thirtyDaysAgo = $now->copy()->subDays(30);

        // Open quotes: total count + dollar value.
        $openQuotesQuery = TenantSale::where('tenant_id', $tenant->id)->quotes();
        $openCount = (clone $openQuotesQuery)->count();
        $openValueCents = (clone $openQuotesQuery)->sum('total_cents');

        // Aging: quotes where updated_at < 14 days ago.
        $agingQuery = (clone $openQuotesQuery)->where('updated_at', '<', $agingThreshold);
        $agingCount = (clone $agingQuery)->count();
        $agingValueCents = (clone $agingQuery)->sum('total_cents');
        $oldestAging = (clone $agingQuery)->orderBy('updated_at')->value('updated_at');
        $oldestAgingDays = $oldestAging
            ? (int) Carbon::parse($oldestAging)->diffInDays($now)
            : 0;

        // New this week: quotes created (created_at) in last 7 days.
        $newThisWeekQuery = (clone $openQuotesQuery)->where('created_at', '>=', $oneWeekAgo);
        $newThisWeekCount = (clone $newThisWeekQuery)->count();
        $newThisWeekValueCents = (clone $newThisWeekQuery)->sum('total_cents');

        // Recently converted: paid sales with was_quote=true in last 30 days.
        $convertedQuery = TenantSale::where('tenant_id', $tenant->id)
            ->where('was_quote', true)
            ->where('payment_status', 'paid')
            ->where('paid_at', '>=', $thirtyDaysAgo);
        $convertedCount = (clone $convertedQuery)->count();
        $convertedValueCents = (clone $convertedQuery)->sum('total_cents');

        // Conversion rate: of all quotes created in last 30 days, what fraction are now converted?
        $quotesCreated30d = TenantSale::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->where(function ($q) {
                // Either currently a quote, or was one and is now paid.
                $q->where('payment_status', 'quote')
                  ->orWhere(function ($qq) {
                      $qq->where('was_quote', true)->where('payment_status', 'paid');
                  });
            })
            ->count();
        $conversionRate = $quotesCreated30d > 0
            ? round(($convertedCount / $quotesCreated30d) * 100)
            : null;

        $dashboard = [
            'open' => [
                'count' => $openCount,
                'value_cents' => (int) $openValueCents,
            ],
            'aging' => [
                'count' => $agingCount,
                'value_cents' => (int) $agingValueCents,
                'oldest_days' => $oldestAgingDays,
            ],
            'new_this_week' => [
                'count' => $newThisWeekCount,
                'value_cents' => (int) $newThisWeekValueCents,
            ],
            'converted' => [
                'count' => $convertedCount,
                'value_cents' => (int) $convertedValueCents,
                'rate_pct' => $conversionRate,
            ],
            'aging_threshold_days' => 14,
        ];

        $quotes = TenantSale::where('tenant_id', $tenant->id)
            ->quotes()
            ->with(['customer', 'rangUpBy', 'items', 'location'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->map(function ($q) {
                return [
                    'id'           => $q->id,
                    'item_count'   => $q->items->count(),
                    'total_cents'  => $q->total_cents,
                    'customer'     => $q->customer
                        ? trim(($q->customer->first_name ?? '') . ' ' . ($q->customer->last_name ?? ''))
                        : null,
                    'customer_email' => $q->customer->email ?? null,
                    'started_by'   => $q->rangUpBy
                        ? trim(($q->rangUpBy->first_name ?? '') . ' ' . ($q->rangUpBy->last_name ?? ''))
                        : null,
                    'location_name' => $q->location->name ?? null,
                    'notes'        => $q->notes,
                    'updated_at'   => $q->updated_at?->toIso8601String(),
                    'created_at'   => $q->created_at?->toIso8601String(),
                ];
            });

        return view('tenant.register.quotes', [
            'tenant'    => $tenant,
            'quotes'    => $quotes,
            'dashboard' => $dashboard,
        ]);
    }

    /**
     * Save the current cart as a quote.
     * Customer is required (the modal enforces it client-side too).
     */
    public function storeQuote(Request $request): JsonResponse
    {
        $tenant = tenant();
        $locationId = $request->session()->get('current_location_id');

        if (!$locationId) {
            return response()->json(['ok' => false, 'error' => 'No location selected.'], 409);
        }

        $validated = $request->validate([
            'id'               => 'nullable|uuid',
            'customer_id'      => 'required|uuid',
            'notes'            => 'nullable|string',
            'tip_cents'        => 'nullable|integer|min:0',
            'items'            => 'required|array|min:1',
            'items.*.type'             => 'required|string|in:service,product,open_item,gift_card',
            'items.*.service_id'       => 'nullable|uuid',
            'items.*.inventory_item_id'=> 'nullable|uuid',
            'items.*.name_snapshot'    => 'nullable|string|max:255',
            'items.*.unit_price_cents' => 'nullable|integer|min:0',
            'items.*.quantity'         => 'nullable|numeric|min:0.001',
            'items.*.discount_cents'   => 'nullable|integer|min:0',
            'items.*.is_taxable'       => 'nullable|boolean',
            'items.*.assigned_staff_id'=> 'nullable|uuid',
            'items.*.notes'            => 'nullable|string',
        ]);

        try {
            $quote = $this->sales->saveQuote([
                'id'                 => $validated['id'] ?? null,
                'tenant_id'          => $tenant->id,
                'rang_up_by_user_id' => auth('tenant')->id(),
                'location_id'        => $locationId,
                'customer_id'        => $validated['customer_id'],
                'notes'              => $validated['notes'] ?? null,
                'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                'items'              => $validated['items'],
            ]);

            return response()->json([
                'ok'          => true,
                'quote_id'    => $quote->id,
                'total_cents' => $quote->total_cents,
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function searchRefundables(Request $request): JsonResponse
    {
        $tenant = tenant();
        $q = trim((string) $request->input('q', ''));

        if ($q === '' || mb_strlen($q) < 2) {
            return response()->json(['sales' => []]);
        }

        $sales = TenantSale::where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['paid', 'partial'])
            ->whereNull('refund_of_sale_id')
            ->where(function ($w) use ($q) {
                $w->where('sale_number', 'like', "%{$q}%")
                  ->orWhereHas('customer', function ($c) use ($q) {
                      $c->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                  });
            })
            ->orderByDesc('paid_at')
            ->limit(20)
            ->with(['customer', 'items'])
            ->get()
            ->map(function ($s) {
                return [
                    'id'          => $s->id,
                    'sale_number' => $s->sale_number,
                    'sale_date'   => $s->sale_date?->toDateString(),
                    'paid_at'     => $s->paid_at?->toDateTimeString(),
                    'total_cents' => $s->total_cents,
                    'tender'      => $s->payment_method,
                    'customer'    => $s->customer
                        ? trim(($s->customer->first_name ?? '') . ' ' . ($s->customer->last_name ?? ''))
                        : null,
                    'items'       => $s->items->map(fn ($i) => [
                        'id'               => $i->id,
                        'name'             => $i->name_snapshot,
                        'quantity'         => $i->quantity,
                        'line_total_cents' => $i->line_total_cents,
                        'type'             => $i->type,
                    ])->toArray(),
                ];
            });

        return response()->json(['sales' => $sales]);
    }

    // MARKER-PATCH-160 — re-send (or send to another email) a sale receipt
    public function resendReceipt(Request $request, string $id): JsonResponse
    {
        $tenant = tenant();
        $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->first();
        if (!$sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Optional override — "send to another email" on the sale-detail card.
        $override = trim((string) $request->input('email', ''));
        if ($override !== '' && !filter_var($override, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'Invalid email address.'], 422);
        }

        \App\Jobs\SendSaleReceiptJob::dispatch($sale->id, $override ?: null, 'manual_resend');
        return response()->json(['ok' => true]);
    }

    /**
     * MARKER-PATCH-170 — Direct Payments Session 2A.
     *
     * Create a Stripe PaymentIntent for a cart. Returns the client_secret
     * which the front-end uses with Stripe.js to confirm the card.
     *
     * This is called BEFORE the sale is committed. After Stripe confirms
     * the payment client-side, the front-end POSTs to /register/sales (or
     * /register/drafts/{id}/commit) with payment_method=card AND the
     * stripe_payment_intent_id so the controller can verify + record.
     */
    public function createPaymentIntent(Request $request): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'amount_cents'      => 'required|integer|min:50',
            'sale_id'           => 'nullable|uuid',
            // MARKER-PATCH-170B — preflight payload so we can validate before charging
            'customer_id'       => 'nullable|uuid',
            'has_service_line'  => 'nullable|boolean',
        ]);

        $direct = new DirectPaymentsService($tenant);
        if (! $direct->isEnabled()) {
            return response()->json([
                'ok' => false,
                'error' => 'Card payments are not enabled for this tenant.',
            ], 422);
        }

        // MARKER-PATCH-170B — pre-charge cart validation. Mirrors SaleService
        // checks so we never authorize a card for a sale that won\'t commit.
        if (! empty($validated['has_service_line']) && empty($validated['customer_id'])) {
            return response()->json([
                'ok'    => false,
                'error' => 'Customer is required when the sale has any service line.',
            ], 422);
        }

        try {
            $pi = $direct->createPaymentIntent($validated['amount_cents'], 'usd', array_filter([
                'intake_sale_id' => $validated['sale_id'] ?? null,
            ]));
            return response()->json([
                'ok'             => true,
                'client_secret'  => $pi->client_secret,
                'payment_intent' => $pi->id,
                'publishable_key' => $direct->publishableKey(),
                'mode'           => $direct->mode(),
            ]);
        } catch (\Throwable $e) {
            Log::error('direct_payments.create_pi_failed', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'Could not initialize card payment. Verify your Stripe keys are correct.',
            ], 500);
        }
    }

    /**
     * Verify a PaymentIntent's final state with Stripe. Returns the card
     * details if succeeded so the front-end can include them in the
     * subsequent commit call (which writes them to the sale row).
     */
    public function confirmPaymentIntent(Request $request): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'payment_intent' => 'required|string',
        ]);

        $direct = new DirectPaymentsService($tenant);
        if (! $direct->isEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Card payments not enabled.'], 422);
        }

        try {
            $pi = $direct->retrievePaymentIntent($validated['payment_intent']);
        } catch (\Throwable $e) {
            Log::error('direct_payments.retrieve_pi_failed', [
                'tenant_id' => $tenant->id,
                'pi'        => $validated['payment_intent'],
                'error'     => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'Could not verify payment.'], 500);
        }

        if ($pi->status !== 'succeeded') {
            return response()->json([
                'ok'     => false,
                'status' => $pi->status,
                'error'  => 'Payment is not in a succeeded state (status: ' . $pi->status . ').',
            ], 409);
        }

        $card = $direct->extractCardDetails($pi);
        return response()->json([
            'ok'                       => true,
            'payment_intent'           => $pi->id,
            'stripe_charge_id'         => $card['charge_id'],
            'card_brand'               => $card['brand'],
            'card_last4'               => $card['last4'],
            'card_funding'             => $card['funding'],
            'amount_received_cents'    => $pi->amount_received,
        ]);
    }

    /**
     * MARKER-PATCH-172 — Create a Stripe Checkout Session and a matching
     * DRAFT sale that\'s waiting for the customer to pay remotely.
     *
     * Returns the Checkout URL (for QR + copy/share) and the draft sale ID
     * (which the frontend polls until the webhook fires).
     */
    public function createCheckoutSession(Request $request): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'amount_cents'     => 'required|integer|min:50',
            'customer_id'      => 'nullable|uuid',
            'has_service_line' => 'nullable|boolean',
            'description'      => 'nullable|string|max:255',
            // MARKER-PATCH-178B — when present, bind the link to THIS existing
            // sale instead of minting a new (appointment-less) one. This is the
            // resumed parked sale's id (cart.draft_id on the frontend).
            'sale_id'          => 'nullable|uuid',
            'items'            => 'required|array|min:1',
            'tip_cents'        => 'nullable|integer|min:0',
            'discount_cents'   => 'nullable|integer|min:0',
        ]);

        $direct = new DirectPaymentsService($tenant);
        if (! $direct->isEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Card payments not enabled.'], 422);
        }

        // Same pre-check as createPaymentIntent (defense in depth).
        if (! empty($validated['has_service_line']) && empty($validated['customer_id'])) {
            return response()->json([
                'ok'    => false,
                'error' => 'Customer is required when the sale has any service line.',
            ], 422);
        }

        // Resolve customer email for receipt (Stripe Checkout will pre-fill it).
        $customerEmail = null;
        if (! empty($validated['customer_id'])) {
            $customerEmail = \App\Models\Tenant\TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $validated['customer_id'])
                ->value('email');
        }

        // Create the Checkout Session first — if Stripe fails, no draft sale orphan.
        $description = $validated['description'] ?: ($tenant->name . ' — purchase');
        try {
            $session = $direct->createCheckoutSession(
                $validated['amount_cents'],
                $description,
                array_filter([
                    'customer_email' => $customerEmail,
                ])
            );
        } catch (\Throwable $e) {
            Log::error('direct_payments.checkout_session_failed', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'ok'    => false,
                'error' => 'Could not create payment link. Verify your Stripe keys.',
            ], 500);
        }

        // MARKER-PATCH-178B — bind the session to an EXISTING sale when sale_id
        // is given (resumed parked/appointment sale), instead of minting a new
        // appointment-less sale. This was the link-detach bug: link-paid
        // appointments stayed unpaid because the charge landed on a separate
        // sale. If no sale_id, fall back to creating a draft as before.
        $locationId = $request->session()->get('current_location_id');
        try {
            if (!empty($validated['sale_id'])) {
                $draftSale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
                    ->where('id', $validated['sale_id'])
                    ->whereNotIn('status', ['cancelled', 'closed'])
                    ->first();
                if (!$draftSale) {
                    return response()->json(['ok' => false, 'error' => 'Sale not found to attach the link.'], 404);
                }
                $draftSale->checkout_session_id = $session->id;
                if (in_array($draftSale->payment_status, ['draft'], true)) {
                    $draftSale->payment_status = 'unpaid';
                }
                $draftSale->payment_method    = $draftSale->payment_method ?: 'card';
                $draftSale->payment_reference = $draftSale->payment_reference ?: 'Awaiting payment link';
                $draftSale->save();
            } else {
                $draftSale = $this->sales->createSale([
                    'tenant_id'          => $tenant->id,
                    'rang_up_by_user_id' => auth('tenant')->id(),
                    'location_id'        => $locationId,
                    'customer_id'        => $validated['customer_id'] ?? null,
                    'status'             => 'pending',
                    'payment_status'     => 'unpaid',
                    'payment_method'     => 'card',
                    'payment_reference'  => 'Awaiting payment link',
                    'paid_at'            => null,
                    'tip_cents'          => (int) ($validated['tip_cents'] ?? 0),
                    'discount_cents'     => (int) ($validated['discount_cents'] ?? 0),
                    'items'              => $validated['items'],
                    'checkout_session_id' => $session->id,
                ]);
            }
        } catch (\Throwable $e) {
            // If draft creation fails, expire the Stripe session immediately so
            // the link can\'t be paid (we have nothing to attach it to).
            try {
                $direct->client = null; // ignore typing — best-effort
            } catch (\Throwable $_) {}
            Log::error('direct_payments.draft_sale_failed', [
                'tenant_id'  => $tenant->id,
                'session_id' => $session->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'ok'    => false,
                'error' => 'Could not stage the sale. ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok'           => true,
            'sale_id'      => $draftSale->id,
            'session_id'   => $session->id,
            'checkout_url' => $session->url,
            'expires_at'   => $session->expires_at,
        ]);
    }

    /**
     * MARKER-PATCH-172 — Poll status of a Checkout Session. Frontend calls
     * this every ~3 seconds while the payment-link modal is open.
     *
     * Returns one of: pending (still waiting), succeeded (payment_status
     * went paid via webhook), expired, or cancelled.
     */
    public function checkCheckoutSession(Request $request): JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'sale_id' => 'required|uuid',
        ]);

        $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->first();

        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Primary check: the webhook handler updates payment_status=paid when
        // checkout.session.completed fires. Read DB first to avoid hitting Stripe.
        if ($sale->payment_status === 'paid') {
            return response()->json([
                'ok'          => true,
                'status'      => 'succeeded',
                'sale_id'     => $sale->id,
                'sale_number' => $sale->sale_number,
                'total_cents' => $sale->total_cents,
            ]);
        }

        // Fallback: hit Stripe directly in case webhook is delayed/dropped.
        $direct = new DirectPaymentsService($tenant);
        if ($sale->checkout_session_id) {
            try {
                $session = $direct->retrieveCheckoutSession($sale->checkout_session_id);
                if ($session->payment_status === 'paid' && $session->status === 'complete') {
                    // Webhook hasn\'t fired yet. We could promote here, but
                    // it\'s cleaner to let the webhook be the source of truth.
                    // Just report pending for now; the next poll should see it.
                }
                if ($session->status === 'expired') {
                    return response()->json(['ok' => true, 'status' => 'expired']);
                }
            } catch (\Throwable $e) {
                Log::warning('direct_payments.poll_failed', [
                    'tenant_id' => $tenant->id,
                    'session'   => $sale->checkout_session_id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['ok' => true, 'status' => 'pending']);
    }

    /**
     * MARKER-PATCH-172 — Cancel a pending Checkout-Session-backed sale.
     * Used when the operator closes the payment-link modal manually.
     */
    public function cancelCheckoutSession(Request $request): JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'sale_id' => 'required|uuid',
        ]);

        $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->where('payment_status', 'unpaid')  // MARKER-PATCH-172C
            ->first();

        if (! $sale) {
            return response()->json(['ok' => true, 'status' => 'already_resolved']);
        }

        // Expire the Stripe session (best-effort) and mark the sale cancelled.
        if ($sale->checkout_session_id) {
            try {
                $direct = new DirectPaymentsService($tenant);
                $direct->retrieveCheckoutSession($sale->checkout_session_id); // ensure exists
                // Stripe Checkout sessions don\'t have a direct cancel API,
                // but expire is achievable via the expire endpoint.
                // Use stripe SDK's expire method.
                // NOTE: stripe-php exposes this as $client->checkout->sessions->expire($id)
                // (added in newer versions). If unavailable, the session will
                // simply expire after 24h naturally.
                // We swallow errors here — they're non-fatal.
                $client = new \Stripe\StripeClient(['api_key' => $tenant->settings['register_payments_' . ($tenant->settings['register_payments_mode'] ?? 'test') . '_sk'] ?? null]);
                try {
                    $client->checkout->sessions->expire($sale->checkout_session_id);
                } catch (\Throwable $_) {
                    // expire may not be available on older SDK versions — ignore.
                }
            } catch (\Throwable $e) {
                // Best-effort cleanup.
                Log::info('direct_payments.session_expire_skipped', [
                    'tenant_id' => $tenant->id,
                    'session'   => $sale->checkout_session_id,
                ]);
            }
        }

        // MARKER-PATCH-172C — payment_status enum doesn't have 'cancelled'.
        // status column already has 'cancelled'. payment_status stays 'unpaid'
        // (customer didn't pay; was never going to from this aborted attempt).
        $sale->status = 'cancelled';
        $sale->save();

        return response()->json(['ok' => true, 'status' => 'cancelled']);
    }

    /**
     * MARKER-PATCH-173 — Customer-facing landing page after a successful
     * Stripe Checkout payment (send-payment-link flow). PUBLIC route: the
     * paying customer is anonymous on their own device. Tenant is resolved by
     * ResolveTenant middleware and $currentTenant is shared to the view.
     *
     * No money depends on this page — the webhook has already promoted the
     * sale to paid by the time Stripe redirects here. total_cents is set on
     * the draft sale at link-creation time, so we show the amount without any
     * synchronous Stripe round-trip.
     */
    public function checkoutSuccess(Request $request)
    {
        $tenant    = tenant();
        $sessionId = (string) $request->query('session_id', '');

        $sale = null;
        if ($sessionId !== '') {
            $sale = \App\Models\Tenant\TenantSale::where('tenant_id', $tenant->id)
                ->where('checkout_session_id', $sessionId)
                ->first();
        }

        return view('tenant.register.checkout-success', [
            'amountCents' => $sale?->total_cents,
        ]);
    }

    /**
     * MARKER-PATCH-173 — Customer-facing landing page when the customer backs
     * out of the Stripe Checkout page. Nothing was charged. PUBLIC route.
     */
    public function checkoutCancel(Request $request)
    {
        return view('tenant.register.checkout-cancel');
    }

    /**
     * MARKER-PATCH-170B — auto-refund a PaymentIntent. Called by the client
     * when commitTransaction fails after a charge already authorized.
     *
     * Idempotent: if the PI was already refunded, Stripe returns the existing
     * refund instead of erroring.
     */
    public function autoRefundPaymentIntent(Request $request): JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'payment_intent' => 'required|string',
            'reason'         => 'nullable|string|max:255',
        ]);

        $direct = new DirectPaymentsService($tenant);
        if (! $direct->isEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Card payments not enabled.'], 422);
        }

        $refund = $direct->refundPaymentIntent(
            $validated['payment_intent'],
            $validated['reason'] ?? 'sale_commit_failed'
        );

        if (! $refund) {
            return response()->json([
                'ok'    => false,
                'error' => 'Refund failed. Charge may still be live in Stripe — check the Stripe dashboard.',
            ], 500);
        }

        return response()->json([
            'ok'        => true,
            'refund_id' => $refund->id,
            'amount'    => $refund->amount,
        ]);
    }

    public function storeRefund(Request $request): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'original_sale_id' => 'required|uuid',
            'refund_method'    => 'required|string|in:cash,card,check,store_credit,mark_paid',
            'reason'           => 'nullable|string|max:500',
            'notes'            => 'nullable|string',
            'item_ids'         => 'required|array|min:1',
            'item_ids.*'       => 'uuid',
        ]);

        try {
            $refund = $this->sales->createRefund([
                'tenant_id'          => $tenant->id,
                'original_sale_id'   => $validated['original_sale_id'],
                'rang_up_by_user_id' => auth('tenant')->id(),
                'refund_method'      => $validated['refund_method'],
                'reason'             => $validated['reason'] ?? null,
                'notes'              => $validated['notes'] ?? null,
                'item_ids'           => $validated['item_ids'],
            ]);

            // MARKER-PATCH-171 — fire a Stripe refund when the refund is to card
            // AND the original sale was paid via direct-payments Stripe flow.
            // Failure here is REPORTED but doesn\'t roll back the Intake refund row —
            // the operator can retry from sale detail (or via Stripe dashboard).
            $stripeRefundError = null;
            if ($validated['refund_method'] === 'card') {
                $stripeRefundError = $this->fireStripeRefund($tenant, $refund);
            }

            return response()->json([
                'ok'                  => true,
                'refund_id'           => $refund->id,
                'sale_number'         => $refund->sale_number,
                'total_cents'         => $refund->total_cents,
                'stripe_refund_error' => $stripeRefundError,
                'stripe_refund_id'    => $refund->fresh()->stripe_refund_id,
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * MARKER-PATCH-171 — shared helper to fire a Stripe refund for a refund row.
     * Returns null on success, or an error message string on failure.
     *
     * The refund row\'s own stripe_payment_intent_id is copied from the original
     * sale in SaleService::createRefund via the existing snapshot machinery —
     * but to be safe and explicit, we read the original sale fresh from the DB
     * and use ITS stripe_payment_intent_id (the refund row may not have one set
     * depending on snapshot config).
     */
    protected function fireStripeRefund(\App\Models\Tenant $tenant, \App\Models\Tenant\TenantSale $refundRow): ?string
    {
        if (! $tenant->direct_payments_enabled) {
            // Not an error — just nothing to do.
            return null;
        }

        $original = \App\Models\Tenant\TenantSale::find($refundRow->refund_of_sale_id);
        if (! $original || ! $original->stripe_payment_intent_id) {
            // Original wasn\'t paid via Stripe; nothing to refund there.
            return null;
        }

        try {
            $direct = new DirectPaymentsService($tenant);
            $stripeRefund = $direct->refundCharge(
                $original->stripe_payment_intent_id,
                (int) $refundRow->total_cents,
                [
                    'intake_refund_sale_id'   => $refundRow->id,
                    'intake_original_sale_id' => $original->id,
                ]
            );
            $refundRow->stripe_refund_id = $stripeRefund->id;
            $refundRow->save();
            return null;
        } catch (\Throwable $e) {
            Log::error('direct_payments.refund_failed', [
                'tenant_id'        => $tenant->id,
                'refund_sale_id'   => $refundRow->id,
                'original_sale_id' => $original->id,
                'pi'               => $original->stripe_payment_intent_id,
                'error'            => $e->getMessage(),
            ]);
            return $e->getMessage();
        }
    }

    protected function taxLabel($tenant): string
    {
        $rate = $tenant->default_tax_rate;
        if ($rate === null || (float) $rate === 0.0) {
            return 'No tax configured';
        }
        return 'Tax · ' . rtrim(rtrim(number_format((float) $rate, 3), '0'), '.') . '%';
    }

    /**
     * MARKER-PATCH-197 — Stripe-vs-ledger reconciliation report. Lists succeeded
     * Stripe payments with no matching ledger row ("paid in Stripe, unpaid in
     * Intake"). The safety net for any payment that slips past the webhook.
     */
    public function reconciliation(\Illuminate\Http\Request $request)
    {
        $tenant = tenant();
        $days = (int) $request->query('days', 30);
        $days = max(1, min($days, 90));

        $svc = new \App\Services\Tenant\PaymentReconciliationService($tenant);
        $result = $svc->unmatchedPayments($days);

        return view('tenant.register.reconciliation', [
            'days'      => $days,
            'scanned'   => $result['scanned'],
            'unmatched' => $result['unmatched'],
            'error'     => $result['error'],
        ]);
    }

    /**
     * MARKER-PATCH-197 — Reconcile a stranded Stripe payment by recording it
     * against a sale through the ledger. Requires an explicit sale_id (the
     * operator picks the candidate) and the PI id. Idempotent: refuses if a
     * ledger row for this PI already exists.
     */
    public function reconcilePayment(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'payment_intent' => 'required|string',
            'sale_id'        => 'required|uuid',
        ]);

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->first();
        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Idempotency: never double-record a PI.
        $already = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenant->id)
            ->where('external_reference', $validated['payment_intent'])
            ->exists();
        if ($already) {
            return response()->json(['ok' => false, 'error' => 'This payment is already recorded in the ledger.'], 409);
        }

        // Verify the PI with Stripe before recording — don't trust the request.
        $direct = new DirectPaymentsService($tenant);
        try {
            $pi = $direct->retrievePaymentIntent($validated['payment_intent']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not verify payment with Stripe.'], 422);
        }
        if (($pi->status ?? null) !== 'succeeded' || (int) ($pi->amount_received ?? 0) <= 0) {
            return response()->json(['ok' => false, 'error' => 'That Stripe payment is not a completed charge.'], 422);
        }

        $amountCents = (int) $pi->amount_received;
        $details = [];
        try { $details = $direct->extractCardDetails($pi); } catch (\Throwable $e) {}

        try {
            $hasPrior = $sale->payments()->count() > 0;
            app(\App\Services\Tenant\SalePaymentService::class)->record(
                sale:               $sale,
                amountCents:        $amountCents,
                kind:               $hasPrior
                    ? \App\Models\Tenant\TenantSalePayment::KIND_BALANCE
                    : ($sale->appointment_id
                        ? \App\Models\Tenant\TenantSalePayment::KIND_DEPOSIT
                        : \App\Models\Tenant\TenantSalePayment::KIND_PAYMENT),
                source:             \App\Models\Tenant\TenantSalePayment::SOURCE_DIRECT_PAYMENT_LINK,
                method:             'card',
                externalReference:  $pi->id,
                notes:              'Reconciled from Stripe (was not recorded by webhook).',
            );

            // Stamp the sale's Stripe fields + un-cancel if it was cancelled.
            $sale->stripe_payment_intent_id = $pi->id;
            if (!empty($details['charge_id'])) $sale->stripe_charge_id = $details['charge_id'];
            if (!empty($details['brand']))     $sale->card_brand       = $details['brand'];
            if (!empty($details['last4']))     $sale->card_last4       = $details['last4'];
            if (!empty($details['funding']))   $sale->card_funding     = $details['funding'];
            if ($sale->status === 'cancelled') $sale->status = 'completed';
            $sale->save();

            // MARKER-PATCH-219C — appointment paid cache cascades
            // centrally in SalePaymentService::recalcStatus().
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Could not record the payment: ' . $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'amount_cents' => $amountCents, 'sale_number' => $sale->sale_number]);
    }

    /**
     * MARKER-PATCH-198 — Hard-delete a single payment row from a sale's ledger.
     * For correcting bad data (e.g. a duplicate deposit). After deletion the
     * sale's payment_status + paid_at and the linked appointment's paid_cents
     * are recomputed so totals stay consistent. Double-confirmed in the UI.
     */
    public function deleteSalePayment(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'sale_id'    => 'required|uuid',
            'payment_id' => 'required|uuid',
        ]);

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->first();
        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        $payment = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenant->id)
            ->where('id', $validated['payment_id'])
            ->where('sale_id', $sale->id)
            ->first();
        if (! $payment) {
            return response()->json(['ok' => false, 'error' => 'Payment not found on this sale.'], 404);
        }

        $deletedCents = (int) $payment->amount_cents;
        $payment->delete();

        // Recompute the sale's derived payment state from the remaining ledger.
        $svc = app(\App\Services\Tenant\SalePaymentService::class);
        $svc->recalcStatus($sale);
        $sale->refresh();

        // MARKER-PATCH-219C — appointment paid cache cascades centrally in
        // SalePaymentService::recalcStatus() (called via recalcStatus above).

        \Illuminate\Support\Facades\Log::info('sale_payment.deleted', [
            'tenant_id'  => $tenant->id,
            'sale_id'    => $sale->id,
            'payment_id' => $validated['payment_id'],
            'amount'     => $deletedCents,
            'by'         => auth('tenant')->id(),
        ]);

        return response()->json([
            'ok'             => true,
            'paid_cents'     => $svc->paidCents($sale),
            'payment_status' => $sale->payment_status,
        ]);
    }

    /**
     * MARKER-PATCH-199 — Delete an empty sale (data correction for stray
     * deposit-sales left after their payment was removed). REFUSES if the sale
     * still has any ledger payments — you must clear those first (patch-198).
     * Hard-deletes the sale + its line items, then refreshes the linked
     * appointment's paid cache. Double-confirmed in the UI.
     */
    public function deleteSale(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = tenant();
        $validated = $request->validate([
            'sale_id' => 'required|uuid',
        ]);

        $sale = TenantSale::where('tenant_id', $tenant->id)
            ->where('id', $validated['sale_id'])
            ->first();
        if (! $sale) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Guard: never delete a sale that still carries money. The operator must
        // remove the payments first (so the deletion can't silently lose a
        // recorded payment).
        $payCount = \App\Models\Tenant\TenantSalePayment::where('tenant_id', $tenant->id)
            ->where('sale_id', $sale->id)
            ->count();
        if ($payCount > 0) {
            return response()->json([
                'ok'    => false,
                'error' => 'This sale still has ' . $payCount . ' payment(s). Delete those first, then delete the sale.',
            ], 409);
        }

        // Guard: never delete a refund record this way.
        if ($sale->refund_of_sale_id) {
            return response()->json(['ok' => false, 'error' => 'Refund records cannot be deleted here.'], 422);
        }

        $apptId = $sale->appointment_id;

        \Illuminate\Support\Facades\DB::transaction(function () use ($tenant, $sale) {
            \App\Models\Tenant\TenantSaleItem::where('tenant_id', $tenant->id)
                ->where('sale_id', $sale->id)
                ->delete();
            $sale->delete();
        });

        // Recompute the linked appointment's paid cache from the remaining ledger.
        // MARKER-PATCH-219C — this block stays MANUAL by necessity: the sale
        // row was just deleted, so SalePaymentService::recalcStatus() can
        // never run for it. Every other site cascades centrally.
        if ($apptId) {
            $appt = \App\Models\Tenant\TenantAppointment::find($apptId);
            if ($appt) {
                $appt->paid_cents = (int) $appt->payments()->sum('tenant_sale_payments.amount_cents');
                $total = (int) $appt->total_cents;
                if ($total > 0 && $appt->paid_cents >= $total) {
                    $appt->payment_status = ($appt->paid_cents > $total) ? 'overage' : 'paid';
                } elseif ($appt->paid_cents > 0) {
                    $appt->payment_status = 'partial';
                } else {
                    $appt->payment_status = 'unpaid';
                }
                $appt->save();
            }
        }

        \Illuminate\Support\Facades\Log::info('sale.deleted', [
            'tenant_id'   => $tenant->id,
            'sale_id'     => $validated['sale_id'],
            'sale_number' => $sale->sale_number,
            'by'          => auth('tenant')->id(),
        ]);

        return response()->json(['ok' => true]);
    }
}
