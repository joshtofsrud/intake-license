<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantNavItem;
use App\Models\Tenant\TenantServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class PublicController extends Controller
{
    public function home()
    {
        $tenant = tenant();
        if (! $tenant) abort(404);

        $page = TenantPage::where('tenant_id', $tenant->id)
            ->where('is_home', true)
            ->where('is_published', true)
            ->first();

        if (! $page) {
            return view('public.coming-soon');
        }

        return $this->renderPage($page);
    }

    public function page(string $slug)
    {
        if ($slug === 'book') return $this->booking(request());

        $tenant = tenant();
        if (! $tenant) abort(404);

        $page = TenantPage::where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return $this->renderPage($page);
    }

    public function booking(Request $request)
    {
        $tenant = tenant();
        if (! $tenant) abort(404);
        return view('public.booking', compact('tenant'));
    }

    public function confirm(Request $request)
    {
        return view('public.confirm');
    }

    // ----------------------------------------------------------------
    // Contact form POST
    // ----------------------------------------------------------------
    public function contact(Request $request)
    {
        $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:32'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $tenant = tenant();
        $to     = $tenant?->notification_email ?? $tenant?->email_from_address;

        if ($to) {
            try {
                Mail::raw(
                    "New contact form submission from {$tenant->name}\n\n"
                    . "Name: {$request->input('name')}\n"
                    . "Email: {$request->input('email')}\n"
                    . "Phone: {$request->input('phone', '—')}\n\n"
                    . "Message:\n{$request->input('message')}",
                    fn($m) => $m->to($to)->subject("New message from {$request->input('name')}")
                );
            } catch (\Throwable $e) {
                logger()->error('Contact form mail failed: ' . $e->getMessage());
            }
        }

        return back()->with('contact_success', true);
    }

    private function renderPage(TenantPage $page)
    {
        $tenant   = tenant();
        $sections = $page->sections()->where('is_visible', true)->get();
        $navItems = TenantNavItem::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

        $catalog = TenantServiceCategory::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['items' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order')->with(['serviceAddons' => function ($sa) {
                    $sa->orderBy('sort_order')->with('addon');
                }]);
            }])
            ->get();

        return view('public.page', compact('page', 'sections', 'navItems', 'catalog'));
    }
}
