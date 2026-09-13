<?php

namespace App\Http\Controllers\Tenant\Settings;

use App\Http\Controllers\Controller;
use App\Support\LayawaySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** MARKER-LAYAWAY — Settings › Sales › Layaway. */
class LayawayController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = tenant();

        return view('tenant.settings.layaway', [
            'tenant'   => $tenant,
            'settings' => LayawaySettings::for($tenant),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $tenant = tenant();

        $data = $request->validate([
            'min_first_pct'   => ['required', 'integer', 'min:0', 'max:100'],
            'term_days'       => ['required', 'integer', 'min:7', 'max:365'],
            'frequency'       => ['required', 'in:weekly,biweekly,monthly,none'],
            'grace_days'      => ['required', 'integer', 'min:0', 'max:60'],
            'cancel_refund'   => ['required', 'in:less_fee,full,store_credit'],
            'restock_fee_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'out_of_stock'    => ['required', 'in:special_order,ask'],
            'arrival_notify'  => ['required', 'in:both,email,none'],
        ]);

        LayawaySettings::save($tenant, $data);

        return redirect()->route('tenant.settings.layaway.index')
            ->with('flash', ['type' => 'success', 'message' => 'Layaway policy saved.']);
    }
}
