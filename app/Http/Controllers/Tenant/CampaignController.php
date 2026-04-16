<?php
namespace App\Http\Controllers\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function __call($method, $args) {
        return response()->json(['controller' => 'CampaignController', 'method' => $method, 'status' => 'not_yet_implemented'], 501);
    }
}
