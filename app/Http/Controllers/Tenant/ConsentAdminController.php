<?php
// MARKER-CONSENT-SURFACES

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantConsentAttestation;
use App\Models\Tenant\TenantCustomer;
use App\Services\Tenant\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConsentAdminController extends Controller
{
    /**
     * The wording a manager agrees to when attesting. Frozen verbatim onto
     * each attestation row, so changing this copy later never rewrites what
     * anyone actually agreed to.
     */
    public const ATTEST_WORDING =
        'I confirm these contacts are customers of this business who gave us '
        . 'permission to email them, or have an active business relationship '
        . 'with us. I understand marketing email to people who did not agree '
        . 'to it can suspend this shop\'s sending.';

    private function guardManager()
    {
        $me = Auth::guard('tenant')->user();
        if (! $me || ! $me->isManager()) {
            abort(redirect()->route('tenant.campaigns.index'));
        }
        return $me;
    }

    public function index()
    {
        $this->guardManager();
        $tenant = tenant();

        $base = TenantCustomer::where('tenant_id', $tenant->id)
            ->whereNotNull('email')->where('email', '!=', '');

        $counts = [
            'mailable'     => (clone $base)->whereNotNull('email_marketing_consent_at')->whereNull('email_marketing_opt_out_at')->count(),
            'unconfirmed'  => (clone $base)->whereNull('email_marketing_consent_at')->whereNull('email_marketing_opt_out_at')->count(),
            'unsubscribed' => (clone $base)->whereNotNull('email_marketing_opt_out_at')->count(),
        ];

        $attestations = TenantConsentAttestation::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')->limit(20)->get();

        return view('tenant.campaigns.contacts', [
            'pageTitle'      => 'Contacts & consent',
            'counts'         => $counts,
            'attestations'   => $attestations,
            'attestWording'  => self::ATTEST_WORDING,
        ]);
    }

    /** Manager attests permission for ALL currently-unconfirmed contacts. */
    public function attest(Request $request)
    {
        $me     = $this->guardManager();
        $tenant = tenant();

        $request->validate(['confirm' => ['required', 'accepted']]);

        $marked = app(ConsentService::class)->attest(
            $tenant,
            $me,
            self::ATTEST_WORDING,
            $request->ip(),
            ['scope' => 'all_unconfirmed']
        );

        return back()->with('success', $marked > 0
            ? "Permission recorded for {$marked} contact(s). They can now receive campaigns."
            : 'No unconfirmed contacts to confirm — nothing changed.');
    }

    /** Per-customer staff actions from the customer detail page. */
    public function customerConsent(Request $request, string $id)
    {
        $me       = $this->guardManager();
        $tenant   = tenant();
        $customer = TenantCustomer::where('tenant_id', $tenant->id)->findOrFail($id);

        $action  = $request->validate(['action' => ['required', 'in:opt_in,opt_out']])['action'];
        $consent = app(ConsentService::class);

        if ($action === 'opt_in') {
            $consent->recordOptIn($customer, 'staff');
            return back()->with('success', 'Marketing consent recorded (staff-entered — customer asked in person or by message).');
        }

        $consent->optOut($customer);
        return back()->with('success', 'Unsubscribed from marketing. Receipts and confirmations are unaffected.');
    }
}
