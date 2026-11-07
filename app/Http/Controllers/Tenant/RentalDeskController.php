<?php
// Rental Desk.

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\RentalDeskData;
use Illuminate\Http\Request;

/**
 * Rental Desk — the live view: what's out, what's due, what's free.
 * MARKER-RENTAL-DESK-V2: the figures are worked out in RentalDeskData (one
 * pass, extension offers read once for every listed rental); the page is drawn
 * in the Studio design. Every number is derived from rentals and the payment
 * ledger; nothing is a stored counter that can go stale.
 */
class RentalDeskController extends Controller
{
    public function index(Request $request)
    {
        return view('tenant.rentals.desk', [
            'desk' => (new RentalDeskData(tenant()))->build(),
        ]);
    }
}
