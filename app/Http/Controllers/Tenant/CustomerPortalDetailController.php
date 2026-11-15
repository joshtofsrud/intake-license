<?php

namespace App\Http\Controllers\Tenant;

// MARKER-PORTAL-DETAIL: the portal's lists could not be opened. This adds the
// detail pages behind them — a work order (appointment) and a purchase (sale) —
// each with print and a message box that tags the message with the record, so
// it lands in the shop inbox saying what it is about.
//
// Every lookup is scoped to tenant AND the signed-in customer. Staff-only data
// never reaches these views: internal notes, cost, staff-only work-order fields.

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantAppointment;
use App\Models\Tenant\TenantAppointmentAsset;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantMessage;
use App\Models\Tenant\TenantSale;
use App\Models\Tenant\TenantSpecialOrder;
use App\Models\Tenant\TenantThread;
use App\Services\DocumentBuilder;
use App\Services\InvoiceBuilderService;
use App\Services\PrintIdentityService;
use App\Services\Tenant\InboxService;
use App\Support\AppointmentStatus;
use App\Support\DocumentOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class CustomerPortalDetailController extends Controller
{
    private function customer(): ?TenantCustomer
    {
        return Auth::guard('customer')->user();
    }

    private function findAppointment(TenantCustomer $customer, string $id): TenantAppointment
    {
        return TenantAppointment::query()
            ->where('tenant_id', tenant()->id)
            ->where('customer_id', $customer->id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Sales a customer may open — the same set the Orders list shows (theirs,
     * completed, never a quote), so every row there opens and nothing else does.
     */
    private function salesFor(TenantCustomer $customer)
    {
        return TenantSale::query()
            ->where('tenant_id', tenant()->id)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->where('was_quote', false);
    }

    // ---------------------------------------------------------- work order
    public function workOrder(InvoiceBuilderService $invoice, string $id)
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();
        $appt   = $this->findAppointment($customer, $id);
        $appt->loadMissing(['items', 'notes.user', 'location']);

        $base = $invoice->forAppointment($appt, ['style' => 'print']);

        // identifiers live on the asset rows, not in the invoice groups
        $assets = TenantAppointmentAsset::where('tenant_id', $tenant->id)
            ->where('appointment_id', $appt->id)
            ->get(['id', 'asset_name_snapshot', 'identifier_snapshot'])
            ->keyBy('id');

        // intake answers: only fields the shop marked customer-visible. A
        // response whose field was deleted has no visibility flag, so it stays hidden.
        $responses = $appt->workOrderResponses()->with('field')->get()
            ->filter(fn ($r) => $r->field && $r->field->is_customer_visible && trim((string) $r->response_value) !== '')
            ->groupBy(fn ($r) => (string) ($r->appointment_asset_id ?? ''));

        $notes = $appt->notes->filter(fn ($n) => (bool) $n->is_customer_visible)->values();

        $payments = $appt->payments()->get();

        $receipts = $this->salesFor($customer)
            ->where('appointment_id', $appt->id)
            ->orderBy('created_at')->get();

        $specialOrders = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('appointment_id', $appt->id)
            ->where('status', '!=', TenantSpecialOrder::STATUS_CANCELLED)
            ->orderBy('created_at')->get();

        $role = AppointmentStatus::role((string) $appt->status);

        $conversation = $this->conversationAbout($customer, 'appointment', $appt->id);

        return view('public.account.portal.work-order', compact(
            'customer', 'appt', 'base', 'assets', 'responses', 'notes', 'payments',
            'receipts', 'specialOrders', 'role', 'conversation'
        ));
    }

    public function workOrderPrint(DocumentBuilder $builder, string $id)
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();
        $appt   = $this->findAppointment($customer, $id);

        // Built here, never from the request: a customer can't ask for staff notes.
        $opt = new DocumentOptions(
            type: $appt->isPaid() ? 'receipt' : 'invoice',
            format: 'full',
            includeCustomerNotes: true,
            includeStaffNotes: false,
            includePrices: true,
            includeLedger: true,
        );

        return view('tenant.print.full', [
            'tenant'     => $tenant,
            'doc'        => $builder->forAppointment($appt, $opt),
            'identity'   => PrintIdentityService::forTenant($tenant),
            'embed'      => false,
            'showHeader' => true,
        ]);
    }

    public function workOrderMessage(Request $request, InboxService $inbox, string $id)
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $appt = $this->findAppointment($customer, $id);
        $request->validate(['body' => ['required', 'string', 'max:1200']]);

        $label = 'Work order ' . ($appt->ra_number ?: '');
        $this->postAbout($inbox, $customer, $request->input('body'), 'appointment', $appt->id, trim($label));

        return redirect()->route('tenant.customer.portal.work_order', $appt->id)
            ->with('success', 'Message sent — we\'ll reply here and in Messages.')
            ->withFragment('conversation');
    }

    // ---------------------------------------------------------------- sale
    public function sale(string $id)
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();
        $sale = $this->salesFor($customer)->where('id', $id)->firstOrFail();
        $sale->loadMissing(['items', 'payments', 'location']);

        // refunds against this sale (shown as links), or the original this refunds
        $refunds  = $this->salesFor($customer)->where('refund_of_sale_id', $sale->id)->orderBy('created_at')->get();
        $original = $sale->refund_of_sale_id
            ? $this->salesFor($customer)->where('id', $sale->refund_of_sale_id)->first()
            : null;

        // the work order this sale settled, only if it is also theirs
        $workOrder = $sale->appointment_id
            ? TenantAppointment::where('tenant_id', $tenant->id)
                ->where('customer_id', $customer->id)
                ->where('id', $sale->appointment_id)->first()
            : null;

        $specialOrders = TenantSpecialOrder::where('tenant_id', $tenant->id)
            ->where('sale_id', $sale->id)
            ->where('status', '!=', TenantSpecialOrder::STATUS_CANCELLED)
            ->orderBy('created_at')->get();

        $conversation = $this->conversationAbout($customer, 'sale', $sale->id);

        return view('public.account.portal.sale', compact(
            'customer', 'sale', 'refunds', 'original', 'workOrder', 'specialOrders', 'conversation'
        ));
    }

    public function salePrint(DocumentBuilder $builder, string $id)
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $tenant = tenant();
        $sale = $this->salesFor($customer)->where('id', $id)->firstOrFail();

        $opt = new DocumentOptions(type: 'receipt', format: 'full', includePrices: true, includeLedger: true);

        return view('tenant.print.full', [
            'tenant'     => $tenant,
            'doc'        => $builder->forSale($sale, $opt),
            'identity'   => PrintIdentityService::forTenant($tenant),
            'embed'      => false,
            'showHeader' => true,
        ]);
    }

    public function saleMessage(Request $request, InboxService $inbox, string $id)
    {
        if (! $customer = $this->customer()) {
            return redirect()->route('tenant.customer.login');
        }
        $sale = $this->salesFor($customer)->where('id', $id)->firstOrFail();
        $request->validate(['body' => ['required', 'string', 'max:1200']]);

        $label = ($sale->isRefund() ? 'Refund' : 'Receipt') . ($sale->sale_number ? ' #' . $sale->sale_number : '');
        $this->postAbout($inbox, $customer, $request->input('body'), 'sale', $sale->id, $label);

        return redirect()->route('tenant.customer.portal.sale', $sale->id)
            ->with('success', 'Message sent — we\'ll reply here and in Messages.')
            ->withFragment('conversation');
    }

    // ------------------------------------------------------------- helpers

    /** Same inbound path as the Messages page, plus what it is about. */
    private function postAbout(InboxService $inbox, TenantCustomer $customer, string $body, string $type, string $id, string $label): void
    {
        $thread = $inbox->threadFor(tenant(), $customer, 'web');
        $inbox->postInbound($thread, $body, null, [
            'source' => 'portal',
            'about'  => ['type' => $type, 'id' => (string) $id, 'label' => $label],
        ], 'web');
    }

    /**
     * The slice of the customer's one thread that concerns a record: their
     * messages tagged with it, plus the shop's replies that follow, up to the
     * customer's next message about something else. Staff replies carry no tag
     * of their own, so they belong to whatever the customer last wrote about.
     */
    private function conversationAbout(TenantCustomer $customer, string $type, string $id): Collection
    {
        $thread = TenantThread::query()
            ->where('tenant_id', tenant()->id)->where('customer_id', $customer->id)
            ->orderBy('created_at')->first();
        if (! $thread) {
            return collect();
        }

        $messages = TenantMessage::where('thread_id', $thread->id)
            ->where('kind', 'message')
            ->orderBy('created_at')->get();

        $out = collect();
        $current = null;
        foreach ($messages as $m) {
            if ($m->direction === 'in') {
                $about = $m->meta['about'] ?? null;
                $current = $about ? ($about['type'] ?? '') . ':' . ($about['id'] ?? '') : null;
            }
            if ($current === $type . ':' . $id) {
                $out->push($m);
            }
        }
        return $out;
    }
}
