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
                      ->orWhere('sku', 'like', "%{$q}%");
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
                'created_from'       => 'register_oversell',
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
    public function showDraft(Request $request, string $subdomain, string $id): JsonResponse
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
    public function discardDraft(Request $request, string $subdomain, string $id): JsonResponse
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
    public function commitDraft(Request $request, string $subdomain, string $id): JsonResponse
    {
        $tenant = tenant();

        $validated = $request->validate([
            'payment_method'    => 'required|string|in:cash,card,check,store_credit,mark_paid,split',
            'payment_reference' => 'nullable|string',
            'tip_cents'         => 'nullable|integer|min:0',
            'customer_id'       => 'nullable|uuid',
            'notes'             => 'nullable|string',
        ]);

        try {
            $sale = $this->sales->commitDraft($tenant->id, $id, [
                'payment_status'    => 'paid',
                'payment_method'    => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'] ?? null,
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
    public function lookupSaleForRefund(Request $request, string $subdomain): JsonResponse
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
     * Commit a multi-row transaction (mixed sale + refund, or pure refund).
     * Pure sales still use storeSale or commitDraft.
     */
    public function storeTransaction(Request $request, string $subdomain): JsonResponse
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
                'items'              => $validated['items'] ?? [],
                'refund'             => $validated['refund'],
            ]);

            // Build a unified receipt response.
            $sale = $result['sale'];
            $refund = $result['refund'];

            return response()->json([
                'ok'             => true,
                'transaction_id' => $result['transaction_id'],
                'sale_id'        => $sale?->id,
                'sale_number'    => $sale?->sale_number ?? $refund?->sale_number,
                'total_cents'    => ($sale?->total_cents ?? 0) - ($refund?->total_cents ?? 0),
                'sale_total'     => $sale?->total_cents ?? 0,
                'refund_total'   => $refund?->total_cents ?? 0,
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
    public function showSaleJson(Request $request, string $subdomain, string $id): JsonResponse
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

            return response()->json([
                'ok'          => true,
                'refund_id'   => $refund->id,
                'sale_number' => $refund->sale_number,
                'total_cents' => $refund->total_cents,
            ]);
        } catch (SaleValidationException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (InventoryStockException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
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
}
