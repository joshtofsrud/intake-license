<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantTransferRequest;
use App\Services\Tenant\TransferRequestService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * patch-100b: admin UI for transfer requests.
 *
 * List + detail + fulfill + cancel. Created via register cart
 * (patch-100a) when staff oversells an item and clicks
 * "Request transfer". Staff at the source location reviews here
 * and marks fulfilled after physically moving the part.
 */
class TransferRequestController extends Controller
{
    public function index(Request $request): View
    {
        // patch-102 location scope — tab determines which side of the
        // flow we're showing:
        //   to_send       — pending at YOUR location's send-out queue
        //   to_receive    — in_transit headed TO your location
        //   all_pending   — every pending across tenant
        //   in_transit    — every in_transit across tenant
        //   fulfilled     — completed
        //   cancelled     — cancelled
        $tenant = tenant();
        $view = $request->input('view', 'to_send');
        $sessionLocId = $request->session()->get('current_location_id');

        $q = TenantTransferRequest::with(['inventoryItem', 'toLocation', 'fromLocation'])
            ->where('tenant_id', $tenant->id);

        switch ($view) {
            case 'to_send':
                $q->where('status', 'pending')
                  ->when($sessionLocId, fn($w) => $w->where('from_location_id', $sessionLocId));
                break;
            case 'to_receive':
                $q->where('status', 'in_transit')
                  ->when($sessionLocId, fn($w) => $w->where('to_location_id', $sessionLocId));
                break;
            case 'all_pending':
                $q->where('status', 'pending');
                break;
            case 'in_transit':
                $q->where('status', 'in_transit');
                break;
            case 'fulfilled':
                $q->where('status', 'fulfilled');
                break;
            case 'cancelled':
                $q->where('status', 'cancelled');
                break;
        }

        $requests = $q->orderByDesc('created_at')->get();

        // Counts only show what's relevant for the CURRENT location
        $base = TenantTransferRequest::where('tenant_id', $tenant->id);
        $counts = [
            'to_send'    => $sessionLocId
                ? (clone $base)->where('status', 'pending')->where('from_location_id', $sessionLocId)->count()
                : 0,
            'to_receive' => $sessionLocId
                ? (clone $base)->where('status', 'in_transit')->where('to_location_id', $sessionLocId)->count()
                : 0,
            'in_transit' => (clone $base)->where('status', 'in_transit')->count(),
            'pending'    => (clone $base)->where('status', 'pending')->count(),
            'fulfilled'  => (clone $base)->where('status', 'fulfilled')->count(),
            'cancelled'  => (clone $base)->where('status', 'cancelled')->count(),
        ];

        return view('tenant.transfer-requests.index', compact('requests', 'counts', 'view'));
    }

    public function show(string $subdomain, string $id): View
    {
        $tenant = tenant();
        $tr = TenantTransferRequest::with(['inventoryItem.locations.location', 'toLocation', 'fromLocation'])
            ->where('tenant_id', $tenant->id)
            ->findOrFail($id);

        return view('tenant.transfer-requests.show', compact('tr'));
    }

    public function send(Request $request, string $subdomain, string $id): RedirectResponse
    {
        $tenant = tenant();
        TenantTransferRequest::where('tenant_id', $tenant->id)->findOrFail($id);

        $validated = $request->validate([
            'quantity_sent' => 'required|integer|min:1',
        ]);

        try {
            app(TransferRequestService::class)->markSent(
                $id,
                $validated['quantity_sent'],
                auth('tenant')->id(),
            );
            return redirect()->route('tenant.transfer-requests.show', $id)
                ->with('flash', ['type' => 'success', 'message' => 'Sent. Items are in transit.']);
        } catch (\Throwable $e) {
            return redirect()->route('tenant.transfer-requests.show', $id)
                ->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function receive(Request $request, string $subdomain, string $id): RedirectResponse
    {
        $tenant = tenant();
        TenantTransferRequest::where('tenant_id', $tenant->id)->findOrFail($id);

        try {
            app(TransferRequestService::class)->markReceived($id, auth('tenant')->id());
            return redirect()->route('tenant.transfer-requests.show', $id)
                ->with('flash', ['type' => 'success', 'message' => 'Transfer received. Stock updated.']);
        } catch (\Throwable $e) {
            return redirect()->route('tenant.transfer-requests.show', $id)
                ->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Legacy fulfill route — kept for backwards compatibility with
     * any external links or old browser tabs. Maps to receive().
     */
    public function fulfill(Request $request, string $subdomain, string $id): RedirectResponse
    {
        return $this->receive($request, $subdomain, $id);
    }

    public function cancel(Request $request, string $subdomain, string $id): RedirectResponse
    {
        $tenant = tenant();
        $tr = TenantTransferRequest::where('tenant_id', $tenant->id)->findOrFail($id);

        try {
            app(TransferRequestService::class)->cancel($id);
            return redirect()->route('tenant.transfer-requests.index')
                ->with('flash', ['type' => 'success', 'message' => 'Transfer request cancelled.']);
        } catch (\Throwable $e) {
            return redirect()->route('tenant.transfer-requests.show', $id)
                ->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
