<?php
// MARKER-CAMPAIGNS-CORE

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantEmailCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Campaign CRUD. Sending lives in the send pipeline (later patch) and is
 * deliberately absent here — a campaign cannot go out from this controller.
 * Manager+ only, same gate as the suppression list.
 */
class CampaignController extends Controller
{
    private function guard()
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            abort(redirect()->route('tenant.communication.index'));
        }
        return $me;
    }

    private function find(string $id): TenantEmailCampaign
    {
        return TenantEmailCampaign::where('tenant_id', tenant()->id)->findOrFail($id);
    }

    public function store(Request $request)
    {
        $me = $this->guard();

        $campaign = TenantEmailCampaign::create([
            'tenant_id'  => tenant()->id,
            'name'       => 'Untitled campaign',
            'status'     => TenantEmailCampaign::STATUS_DRAFT,
            'created_by' => $me->id,
        ]);

        return redirect()->route('tenant.campaigns.edit', $campaign->id);
    }

    public function edit(string $id)
    {
        $this->guard();
        $campaign = $this->find($id);

        return view('tenant.communication.campaign-edit', [
            'pageTitle' => 'Campaign — ' . $campaign->name,
            'campaign'  => $campaign,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $this->guard();
        $campaign = $this->find($id);

        if (! $campaign->isEditable()) {
            return back()->with('error', 'This campaign has already been sent and can\'t be edited.');
        }

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:160'],
            'subject' => ['nullable', 'string', 'max:200'],
        ]);

        $campaign->update($data);

        return back()->with('success', 'Campaign saved.');
    }

    public function destroy(string $id)
    {
        $this->guard();
        $campaign = $this->find($id);

        if ($campaign->status === TenantEmailCampaign::STATUS_SENT
            || $campaign->status === TenantEmailCampaign::STATUS_SENDING) {
            return back()->with('error', 'A sent campaign is a record — it can\'t be deleted.');
        }

        $campaign->delete();

        return redirect()->route('tenant.communication.index')
            ->with('success', 'Campaign deleted.');
    }
}
