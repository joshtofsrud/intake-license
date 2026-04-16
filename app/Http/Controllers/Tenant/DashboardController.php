<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Bare-minimum dashboard while we rebuild features one by one.
 * No stats, no tables, no modal, no DB queries. Just renders a view.
 */
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        return view('tenant.dashboard');
    }
}
