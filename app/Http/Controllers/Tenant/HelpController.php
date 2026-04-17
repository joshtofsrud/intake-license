<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;

class HelpController extends Controller
{
    public function index()
    {
        return view('tenant.help.index');
    }
}
