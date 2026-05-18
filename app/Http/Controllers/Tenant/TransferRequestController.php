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
        $tenant = tenant();
        $view = $request->input('view', 'pending');

        $q = TenantTransferRequest::with(['inventoryItem', 'toLocation', 'fromLocation'])
            ->where('tenant_id', $tenant->id);

        if (in_array($view, ['pending', 'fulfilled', 'cancelled'], true)) {
            $q->where('status', $view);
        }

        $requests = $q->orderByDesc('created_at')->get();

        $counts = [
            'pending'   => TenantTransferRequest::where('tenant_id', $tenant->id)->where('status', 'pending')->count(),
            'fulfilled' => TenantTransferRequest::where('tenant_id', $tenant->id)->where('status', 'fulfilled')->count(),
            'cancelled' => TenantTransferRequest::where('tenant_id', $tenant->id)->where('status', 'cancelled')->count(),
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

    public function fulfill(Request $request, string $subdomain, string $id): RedirectResponse
    {
        $tenant = tenant();
        $tr = TenantTransferRequest::where('tenant_id', $tenant->id)->findOrFail($id);

        try {
            app(TransferRequestService::class)->markFulfilled($id, auth('tenant')->id());
            return redirect()->route('tenant.transfer-requests.show', $id)
                ->with('flash', ['type' => 'success', 'message' => 'Transfer marked fulfilled.']);
        } catch (\Throwable $e) {
            return redirect()->route('tenant.transfer-requests.show', $id)
                ->with('flash', ['type' => 'error', 'message' => $e->getMessage()]);
        }
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
