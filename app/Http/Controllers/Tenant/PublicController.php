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

        // MARKER-SPLASH-2 -- the homepage asks for its OWN pairing, exactly
        // like every other page now does.
        return $this->renderWithSplash($page);
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

        // MARKER-SPLASH-2 -- any page can carry a splash now, so this route
        // resolves one too. Direct links to a page WITH a pairing are
        // interrupted on purpose: the shop opted that page in.
        return $this->renderWithSplash($page);
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
        // MARKER-PATCH-399 — honeypot. Real users never fill this hidden field;
        // if it's filled, silently drop with a normal success response so the
        // bot can't tell it was caught.
        if (filled($request->input('company_website'))) {
            return back()->with('contact_success', true);
        }

        // MARKER-CONTACT-SPAM — minimum fill time. The value is encrypted at
        // render, so it can't be forged or replayed with a stale timestamp.
        // Same silent success as the honeypot: a bot that learns which check
        // caught it just works around that one.
        $startedRaw = $request->input('form_started_at');
        if (filled($startedRaw)) {
            try {
                $started = (int) decrypt($startedRaw);
                if (time() - $started < 3) {
                    return back()->with('contact_success', true);
                }
                // Older than a day means a stale page or a replayed payload.
                if (time() - $started > 86400) {
                    return back()->with('contact_success', true);
                }
            } catch (\Throwable $e) {
                // Tampered or undecryptable — treat as a bot, quietly.
                return back()->with('contact_success', true);
            }
        }

        // MARKER-CONTACT-NAMES — the form now posts first_name and last_name,
        // both required, because a single "name" field let people through with
        // one word and the inbox filled with half-identified customers.
        // Older published pages may still post a combined "name": those are
        // still accepted, but must contain a surname.
        $usesSplitName = $request->has('first_name') || $request->has('last_name');

        $request->validate($usesSplitName ? [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'max:255'],
            'phone'      => ['nullable', 'string', 'max:32'],
            'message'    => ['required', 'string', 'max:2000'],
        ] : [
            // At least two words: a single first name was how people slipped through.
            'name'    => ['required', 'string', 'max:255', 'regex:/^\\S+\\s+\\S+/'],
            'email'   => ['required', 'email', 'max:255'],
            'phone'   => ['nullable', 'string', 'max:32'],
            'message' => ['required', 'string', 'max:2000'],
        ], [
            'name.regex'        => 'Please enter both a first and last name.',
            'last_name.required'=> 'Please enter a last name.',
        ]);

        $tenant = tenant();

        // MARKER-PATCH-378 — route web contact submissions into the unified inbox
        // so nothing is silently lost (notification_email may be unset). Inbound
        // only: no SMS side-effects, no phone required. Email below stays as a
        // fallback. Wrapped so a public form never 500s on a capture failure.
        try {
            // MARKER-CONTACT-NAMES — split fields win; the combined field is
            // only a fallback for pages published before this change.
            if ($usesSplitName) {
                $first = trim((string) $request->input('first_name'));
                $last  = trim((string) $request->input('last_name'));
                // MARKER-CONTACT-NAME-FIX — $name is read further down for the
                // message metadata. Leaving it unset here threw an
                // ErrorException that the catch below swallowed, so the
                // customer and thread were written and the message was not.
                $name  = trim($first . ' ' . $last);
            } else {
                $name  = trim((string) $request->input('name'));
                $parts = explode(' ', $name, 2);
                $first = ($parts[0] ?? '') !== '' ? $parts[0] : 'Website';
                $last  = isset($parts[1]) ? trim($parts[1]) : '';
            }

            $customer = \App\Models\Tenant\TenantCustomer::firstOrCreate(
                ['tenant_id' => $tenant->id, 'email' => strtolower((string) $request->input('email'))],
                ['first_name' => $first, 'last_name' => $last, 'phone' => \App\Support\PhoneNumber::normalize($request->input('phone'))]
            );

            $inbox  = app(\App\Services\Tenant\InboxService::class);
            $thread = $inbox->threadFor($tenant, $customer, 'web');
            if (! $thread->subject) {
                $thread->update(['subject' => 'Website contact form']);
            }
            $inbox->postInbound($thread, (string) $request->input('message'), null, [
                'source' => 'contact_form',
                'name'   => $name,
                'email'  => $request->input('email'),
                'phone'  => $request->input('phone'),
            ]);
        } catch (\Throwable $e) {
            // MARKER-CONTACT-NAME-FIX — this catch is right (a public form must
            // never 500), but a bare message made a capture failure look like
            // noise for weeks. Log enough to tie the log line to the blank
            // thread it produced.
            logger()->error('Contact form inbox post failed: ' . $e->getMessage(), [
                'tenant_id' => $tenant?->id,
                'email'     => $request->input('email'),
                'file'      => $e->getFile() . ':' . $e->getLine(),
            ]);
        }

        $to     = $tenant?->notification_email ?? $tenant?->email_from_address;

        if ($to) {
            try {
                // MARKER-CONTACT-NAME-FIX — a split-name page posts no 'name'
                // field, so these interpolations were blank. Rebuild from
                // whichever pair of fields the page actually sent.
                $senderName = trim((string) $request->input('name'))
                    ?: trim(trim((string) $request->input('first_name')) . ' ' . trim((string) $request->input('last_name')))
                    ?: 'Website visitor';

                Mail::raw(
                    "New contact form submission from {$tenant->name}\n\n"
                    . "Name: {$senderName}\n"
                    . "Email: {$request->input('email')}\n"
                    . "Phone: {$request->input('phone', '—')}\n\n"
                    . "Message:\n{$request->input('message')}",
                    fn($m) => $m->to($to)->subject("New message from {$senderName}")
                );
            } catch (\Throwable $e) {
                logger()->error('Contact form mail failed: ' . $e->getMessage());
            }
        }

        return back()->with('contact_success', true);
    }

    /**
     * MARKER-SPLASH-2 -- resolve this page's pairing and render accordingly.
     *
     * Viewing a page that IS somebody's splash writes that splash's cookie,
     * so clicking through to the page behind it is not bounced straight back
     * -- the trap v1 hit in page mode.
     */
    private function renderWithSplash(TenantPage $page)
    {
        $tenant = tenant();

        // Seeing a splash counts as seeing it, whichever route served it.
        $servedAsSplash = (bool) $page->is_splash;

        $pairing = \App\Support\SplashSettings::forPage($tenant, $page);
        $show    = $pairing && ! \App\Support\SplashSettings::alreadySeen(request(), $pairing);

        if ($show && $pairing['mode'] === 'page') {
            return redirect('/' . $pairing['page']->slug);
        }

        $response = response($this->renderPage(
            $page,
            $show ? $pairing['page'] : null,
            $show ? $pairing : []
        ));

        if ($servedAsSplash) {
            $ownCookie = \App\Support\SplashSettings::cookieName($page->id);
            // Mirror the frequency of whichever pairing points at this page.
            $owner = TenantPage::where('tenant_id', $tenant->id)
                ->where('splash_page_id', $page->id)
                ->first();
            $freq = $owner?->splash_frequency ?? 'session';

            if ($freq !== 'always') {
                $minutes = \App\Support\SplashSettings::cookieMinutes(['frequency' => (string) $freq]);
                $response = $response->cookie(
                    $ownCookie, '1', $minutes, '/', null, request()->isSecure(), false, false, 'lax'
                );
            }
        }

        return $response;
    }

    private function renderPage(TenantPage $page, ?TenantPage $splashPage = null, array $splashCfg = [])
    {
        $tenant   = tenant();
        $sections = $page->sections()->where('is_visible', true)->get();
        $sections = \App\Models\Tenant\TenantPageSection::withInheritedChrome($sections, $page->tenant_id, $page->id);
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

        // MARKER-SPLASH -- overlay mode: the homepage renders exactly as it
        // always did and the splash draws on top, so the real HTML is still
        // served to crawlers and to anyone with JS off.
        $splashSections = null;
        if ($splashPage) {
            $splashSections = $splashPage->sections()->where('is_visible', true)->get();
            $splashSections = \App\Models\Tenant\TenantPageSection::withInheritedChrome(
                $splashSections, $splashPage->tenant_id, $splashPage->id
            );
        }

        return view('public.page', compact(
            'page', 'sections', 'navItems', 'catalog',
            'splashPage', 'splashSections', 'splashCfg'
        ));
    }
}
