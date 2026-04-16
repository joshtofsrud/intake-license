<?php
namespace App\Http\Controllers\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __call($method, $args) {
        return response()->json(['controller' => 'WebhookController', 'method' => $method, 'status' => 'not_yet_implemented'], 501);
    }
}
