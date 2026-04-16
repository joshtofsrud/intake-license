<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MarketingController extends Controller
{
    public function home()
    {
        return view('marketing.home', $this->sharedData());
    }

    public function pricing()
    {
        return view('marketing.pricing', $this->sharedData());
    }

    public function features()
    {
        return view('marketing.features', $this->sharedData());
    }

    public function docs()
    {
        return view('marketing.docs', $this->sharedData());
    }

    public function contact(Request $request)
    {
        if ($request->isMethod('post')) {
            $request->validate([
                'name'    => ['required', 'string', 'max:255'],
                'email'   => ['required', 'email'],
                'message' => ['required', 'string', 'max:3000'],
            ]);
            // Queue email to support in production
            return back()->with('contact_success', true);
        }
        return view('marketing.contact', $this->sharedData());
    }

    private function sharedData(): array
    {
        $plans = config('intake.plan_prices');
        return [
            'plans' => [
                'basic'   => ['price' => $plans['basic']   / 100, 'name' => 'Basic',   'slug' => 'basic'],
                'branded' => ['price' => $plans['branded'] / 100, 'name' => 'Branded', 'slug' => 'branded'],
                'custom'  => ['price' => $plans['custom']  / 100, 'name' => 'Custom',  'slug' => 'custom'],
            ],
        ];
    }
}
