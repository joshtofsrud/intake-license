#!/bin/bash
# contact-names-and-inbox-close — two small fixes.
#   1. CONTACT FORM required both names. The public form posted a single
#      "name" field which was split on the first space, so anyone typing just
#      a first name created a half-identified customer in the inbox. Both the
#      footer form and the contact section now post first_name and last_name,
#      both required. The server accepts either shape: split fields when
#      present, and the combined field as a fallback for pages published
#      before this change — but that fallback now requires two words, with a
#      plain message ("Please enter both a first and last name.") rather than
#      a regex error.
#      The regex is escaped for a single-quoted PHP string; verified by
#      evaluating the literal out of the file at its runtime value, which
#      rejects "Josh" and accepts "Josh Tofsrud". An earlier version of this
#      patch would have rejected EVERY name — caught before shipping.
#   2. INBOX BUTTON said "Close", which on a phone reads as "close this
#      message" — the opposite of what it does. Now "Close ticket" and
#      "Reopen ticket".
# No routes, no migration. Server: view:clear.
set -e
cd "$(git rev-parse --show-toplevel)"
if grep -q "MARKER-CONTACT-NAMES" app/Http/Controllers/Tenant/PublicController.php; then
  echo "already applied — aborting."; exit 1
fi

cat > 'app/Http/Controllers/Tenant/PublicController.php' <<'CNAME_0_EOF'
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
        // MARKER-PATCH-399 — honeypot. Real users never fill this hidden field;
        // if it's filled, silently drop with a normal success response so the
        // bot can't tell it was caught.
        if (filled($request->input('company_website'))) {
            return back()->with('contact_success', true);
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
            logger()->error('Contact form inbox post failed: ' . $e->getMessage());
        }

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

        return view('public.page', compact('page', 'sections', 'navItems', 'catalog'));
    }
}
CNAME_0_EOF

cat > 'resources/views/public/sections/_footer.blade.php' <<'CNAME_1_EOF'
{{-- MARKER-PATCH-158-G26 — footer public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Layout
  $layout       = $c['layout']        ?? 'columns';
  $bottomLayout = $c['bottom_layout'] ?? 'split';
  $hAlign       = $c['text_align']    ?? 'left';

  $padTokens = ['compact'=>'40px','normal'=>'64px','spacious'=>'96px'];
  $padTop    = $padTokens[$c['padding_top']    ?? 'normal'] ?? '64px';
  $padBot    = $padTokens[$c['padding_bottom'] ?? 'normal'] ?? '40px';

  // Background
  $bgMode  = $c['bg_mode']  ?? 'color';
  $bgColor = $c['bg_color'] ?? '#0a0a0a';
  $gradF   = $c['bg_gradient_from'] ?? '#0a0a0a';
  $gradT   = $c['bg_gradient_to']   ?? '#1a1a1a';

  $borderTop = $c['border_top'] ?? 'none';

  // Colors
  $textColor  = ($c['text_color']  ?? '') ?: '#ffffff';
  $linkColor  = ($c['link_color']  ?? '') ?: 'rgba(255,255,255,0.65)';
  $mutedColor = ($c['muted_color'] ?? '') ?: 'rgba(255,255,255,0.4)';

  // Brand
  $showLogo = (bool)($c['show_logo'] ?? true);
  $tagline  = trim($c['tagline_override'] ?? '');
  if ($tagline === '' && isset($tenant)) {
      $tagline = $tenant->tagline ?? '';
  }

  $footerBg = $bgMode === 'gradient' ? $gradF : $bgColor;
  $logoUrl  = $showLogo && isset($tenant) ? \App\Support\ColorHelper::pickLogo($tenant, $footerBg) : null;

  // MARKER-PATCH-158-G28 — logo size control
  $logoSizeMap = [
      'small'  => '22px',
      'medium' => '28px',
      'large'  => '40px',
      'xl'     => '56px',
  ];
  $logoHeight = $logoSizeMap[$c['logo_size'] ?? 'medium'] ?? '28px';

  // Link columns
  $linkColumns = $c['link_columns'] ?? [];
  if (is_string($linkColumns)) { $d = json_decode($linkColumns, true); $linkColumns = is_array($d) ? $d : []; }
  if (!is_array($linkColumns)) $linkColumns = [];

  // Social links
  $socialLinks = $c['social_links'] ?? [];
  if (is_string($socialLinks)) { $d = json_decode($socialLinks, true); $socialLinks = is_array($d) ? $d : []; }
  if (!is_array($socialLinks)) $socialLinks = [];

  // MARKER-PATCH-305 — contact info is editable in the footer (email falls back
  // to the account email). Previously gated on $tenant->phone/address/hours,
  // which aren't tenant columns, so the toggles never did anything.
  $cPhone   = trim($c['contact_phone']   ?? '');
  $cEmail   = trim($c['contact_email']   ?? '') ?: ($tenant->email_from_address ?? $tenant->notification_email ?? '');
  $cAddress = trim($c['contact_address'] ?? '');
  $cHours   = trim($c['contact_hours']   ?? '');
  $showPhone   = (bool)($c['show_phone']   ?? false) && $cPhone   !== '';
  $showEmail   = (bool)($c['show_email']   ?? true)  && $cEmail   !== '';
  $showAddress = (bool)($c['show_address'] ?? false) && $cAddress !== '';
  $showHours   = (bool)($c['show_hours']   ?? false) && $cHours   !== '';
  $contactEmail = $cEmail;

  $hasContactBlock = $showPhone || $showEmail || $showAddress || $showHours;

  // Copyright with {year} + {name} templating
  $copyTpl = $c['copyright_text'] ?? '';
  if (trim($copyTpl) === '') {
      $copyTpl = '© {year} {name}. All rights reserved.';
  }
  $copyText = str_replace(
      ['{year}', '{name}'],
      [date('Y'), $tenant->name ?? ''],
      $copyTpl
  );

  // MARKER-PATCH-158-G26B — per-section "Powered by Intake" toggle restored.
  // Default true. The layout-level badge is suppressed when a footer section
  // exists (G26a), so this is the only place the badge would render on pages
  // that have a footer section.
  $showPoweredBy = (bool)($c['show_powered_by'] ?? true);

  // MARKER-PATCH-158-G29 — inline contact form
  $showForm        = (bool)($c['show_form'] ?? false);
  $formHeading     = $c['form_heading']      ?? 'Get in touch';
  $formDescription = $c['form_description']  ?? '';
  $formButton      = $c['form_button_label'] ?? 'Send';
  $formSuccess     = $c['form_success_text'] ?? "Thanks! We'll be in touch soon.";
  $formShowPhone    = (bool)($c['form_show_phone']    ?? true);   // MARKER-PATCH-394
  $formRequirePhone = (bool)($c['form_require_phone'] ?? false);

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-ftr-' . ($section->id ?? uniqid());
  // MARKER-PATCH-303B — define CTA accent vars BEFORE the <style> that uses them
  $ctaAccent  = ($tenant->accent_color ?? '') ?: '#3FD16B';
  $ctaBtnText = \App\Support\ColorHelper::accentTextColor($ctaAccent);

  // Social platform icons (simple SVG, single file)
  $iconFor = function($platform) {
      $icons = [
          'instagram' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".5" fill="currentColor"/></svg>',
          'facebook'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.56 9.88V14.9H8v-2.9h2.44v-2.2c0-2.4 1.43-3.74 3.62-3.74 1.05 0 2.14.19 2.14.19v2.36h-1.2c-1.2 0-1.57.74-1.57 1.5V12h2.66l-.42 2.9h-2.24v6.98A10 10 0 0 0 22 12z"/></svg>',
          'twitter'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
          'youtube'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1 31 31 0 0 0 .5-5.8 31 31 0 0 0-.5-5.8zM9.6 15.6V8.4l6.2 3.6z"/></svg>',
          'tiktok'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19.6 7.5a6 6 0 0 1-3.5-1v8a5.5 5.5 0 1 1-5.5-5.5v2.7a2.8 2.8 0 1 0 2.8 2.8V2h2.7a4 4 0 0 0 3.5 4z"/></svg>',
          'linkedin'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zM8.34 17.34H5.67V9.67h2.67zM7 8.34a1.55 1.55 0 1 1 0-3.1 1.55 1.55 0 0 1 0 3.1zm11.34 9H15.67v-3.86c0-.94-.02-2.16-1.31-2.16-1.32 0-1.52 1.03-1.52 2.09v3.93H10.18V9.67h2.56v1.05h.04a2.8 2.8 0 0 1 2.52-1.38c2.7 0 3.2 1.78 3.2 4.08v3.92z"/></svg>',
          'pinterest' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2a10 10 0 0 0-3.65 19.31c-.07-.79-.13-2 .03-2.87.14-.79 1-5 1-5s-.26-.51-.26-1.27c0-1.2.7-2.1 1.56-2.1.74 0 1.1.56 1.1 1.22 0 .74-.47 1.85-.72 2.88-.2.86.43 1.57 1.28 1.57 1.53 0 2.7-1.6 2.7-3.94 0-2.06-1.48-3.5-3.6-3.5a3.73 3.73 0 0 0-3.9 3.74c0 .74.29 1.54.64 1.97a.26.26 0 0 1 .06.25l-.24 1c-.05.13-.13.16-.27.1-1-.47-1.62-1.95-1.62-3.13 0-2.55 1.85-4.9 5.34-4.9 2.8 0 4.99 2 4.99 4.67 0 2.8-1.76 5.04-4.2 5.04-.82 0-1.6-.43-1.86-.93l-.5 1.93c-.18.7-.68 1.58-1.02 2.12A10 10 0 1 0 12 2z"/></svg>',
          'github'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2a10 10 0 0 0-3.16 19.49c.5.09.69-.22.69-.48v-1.7c-2.78.6-3.37-1.34-3.37-1.34-.45-1.16-1.11-1.47-1.11-1.47-.9-.62.07-.6.07-.6 1 .07 1.53 1.04 1.53 1.04.9 1.54 2.34 1.1 2.91.84.09-.65.35-1.09.63-1.34-2.22-.25-4.55-1.11-4.55-4.94 0-1.1.39-1.99 1.03-2.69-.1-.26-.45-1.27.1-2.65 0 0 .84-.27 2.75 1.03a9.6 9.6 0 0 1 5 0c1.91-1.3 2.75-1.03 2.75-1.03.55 1.38.2 2.4.1 2.65.64.7 1.03 1.6 1.03 2.69 0 3.84-2.34 4.68-4.57 4.93.36.31.68.92.68 1.85v2.74c0 .27.18.58.69.48A10 10 0 0 0 12 2z"/></svg>',
          'website'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>',
          'email'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 6 10-6"/></svg>',
      ];
      return $icons[$platform] ?? $icons['website'];
  };
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'gradient')
  background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @else
  background: {{ $bgColor }};
  @endif
  @if($borderTop === 'hairline')
  border-top: 1px solid rgba(255,255,255,0.06);
  @elseif($borderTop === 'divider')
  border-top: 1px solid rgba(255,255,255,0.12);
  @endif
  color: {{ $textColor }};
}
.{{ $instId }} .p-ftr-wrap {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 48px);
}
.{{ $instId }} .p-ftr-top {
  display: grid;
  @if($layout === 'columns')
  grid-template-columns: minmax(0, 1.4fr) repeat({{ max(1, min(4, count($linkColumns) + ($hasContactBlock ? 1 : 0) + ($showForm ? 1 : 0))) }}, minmax(0, 1fr));
  gap: 48px;
  align-items: start;
  @elseif($layout === 'centered')
  grid-template-columns: 1fr;
  text-align: center;
  gap: 32px;
  @else
  grid-template-columns: 1fr;
  gap: 16px;
  text-align: {{ $hAlign }};
  @endif
  margin-bottom: 36px;
}
@media (max-width: 720px) {
  .{{ $instId }} .p-ftr-top { grid-template-columns: 1fr 1fr; gap: 24px; }
}
@media (max-width: 480px) {
  .{{ $instId }} .p-ftr-top { grid-template-columns: 1fr; }
}

.{{ $instId }} .p-ftr-brand {
  @if($layout === 'centered') margin: 0 auto; @endif
  max-width: 340px;
}
.{{ $instId }} .p-ftr-logo {
  font-size: 19px;
  font-weight: 600;
  letter-spacing: -.01em;
  color: {{ $textColor }};
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  @if($layout === 'centered') justify-content: center; @endif
}
.{{ $instId }} .p-ftr-logo img { height: {{ $logoHeight }}; width: auto; }
.{{ $instId }} .p-ftr-tagline {
  font-size: 14px;
  line-height: 1.55;
  color: {{ $mutedColor }};
  margin: 0 0 16px;
}

.{{ $instId }} .p-ftr-social {
  display: flex;
  gap: 12px;
  @if($layout === 'centered') justify-content: center; @endif
}
.{{ $instId }} .p-ftr-social a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px; height: 32px;
  border-radius: 6px;
  color: {{ $linkColor }};
  transition: all 0.15s;
}
.{{ $instId }} .p-ftr-social a:hover {
  color: {{ $textColor }};
  background: rgba(255,255,255,0.05);
}

.{{ $instId }} .p-ftr-col-heading {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $textColor }};
  margin: 0 0 14px;
  opacity: .85;
}
.{{ $instId }} .p-ftr-col ul {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 9px;
}
.{{ $instId }} .p-ftr-col a {
  font-size: 13.5px;
  color: {{ $linkColor }};
  text-decoration: none;
  transition: color 0.15s;
}
.{{ $instId }} .p-ftr-col a:hover { color: {{ $textColor }}; }

.{{ $instId }} .p-ftr-contact-line {
  font-size: 13.5px;
  color: {{ $linkColor }};
  margin: 0 0 9px;
  line-height: 1.5;
}
.{{ $instId }} .p-ftr-contact-line a { color: {{ $linkColor }}; text-decoration: none; }
.{{ $instId }} .p-ftr-contact-line a:hover { color: {{ $textColor }}; }
.{{ $instId }} .p-ftr-contact-line strong {
  display: block;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: {{ $mutedColor }};
  margin-bottom: 3px;
}

/* MARKER-PATCH-158-G29 — inline contact form */
.{{ $instId }} .p-ftr-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.{{ $instId }} .p-ftr-form-desc {
  font-size: 13px;
  color: {{ $mutedColor }};
  margin: 0 0 6px;
  line-height: 1.5;
}
.{{ $instId }} .p-ftr-form input,
.{{ $instId }} .p-ftr-form textarea {
  width: 100%;
  font-family: inherit;
  font-size: 13.5px;
  color: {{ $textColor }};
  padding: 9px 12px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 6px;
  outline: none;
  transition: border-color 0.15s;
}
.{{ $instId }} .p-ftr-form input:focus,
.{{ $instId }} .p-ftr-form textarea:focus {
  border-color: {{ $linkColor }};
}
.{{ $instId }} .p-ftr-form input::placeholder,
.{{ $instId }} .p-ftr-form textarea::placeholder {
  color: {{ $mutedColor }};
  opacity: .9;
}
.{{ $instId }} .p-ftr-form textarea { resize: vertical; min-height: 60px; }
.{{ $instId }} .p-ftr-form button {
  padding: 9px 18px;
  font-size: 13.5px;
  font-weight: 500;
  background: {{ $textColor }};
  color: {{ $bgColor }};
  border: 0;
  border-radius: 6px;
  cursor: pointer;
  transition: filter 0.15s;
}
.{{ $instId }} .p-ftr-form button:hover { filter: brightness(0.92); }
.{{ $instId }} .p-ftr-form-success {
  padding: 10px 12px;
  border-radius: 6px;
  background: rgba(190,242,100,0.1);
  border: 1px solid rgba(190,242,100,0.2);
  color: {{ $textColor }};
  font-size: 13px;
}
.{{ $instId }} .p-ftr-form-error {
  padding: 10px 12px;
  border-radius: 6px;
  background: rgba(255,100,100,0.1);
  border: 1px solid rgba(255,100,100,0.2);
  color: #ffaaaa;
  font-size: 13px;
}
.{{ $instId }} .p-ftr-form-heading {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $textColor }};
  margin: 0 0 14px;
  opacity: .85;
}

.{{ $instId }} .p-ftr-bottom {
  border-top: 1px solid rgba(255,255,255,0.06);
  padding-top: 22px;
  font-size: 12.5px;
  color: {{ $mutedColor }};
  text-align: {{ $hAlign }};
}
.{{ $instId }} .p-ftr-bottom.p-ftr-bottom--has-badge {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  text-align: left;
}
@media (max-width: 560px) {
  .{{ $instId }} .p-ftr-bottom.p-ftr-bottom--has-badge {
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
  }
}
.{{ $instId }} .p-ftr-bottom a {
  color: {{ $linkColor }};
  text-decoration: none;
}
.{{ $instId }} .p-ftr-bottom a:hover { color: {{ $textColor }}; }

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
/* MARKER-PATCH-303 — pre-footer call-to-action band */
.{{ $instId }} .p-ftr-cta { border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 36px; margin-bottom: 44px; }
.{{ $instId }} .p-ftr-cta-inner { max-width: 1200px; margin: 0 auto; padding: 0 clamp(20px, 5vw, 48px); display: flex; align-items: center; justify-content: space-between; gap: 28px; flex-wrap: wrap; }
.{{ $instId }} .p-ftr-cta-eyebrow { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: {{ $ctaAccent }}; margin-bottom: 10px; font-weight: 600; }
.{{ $instId }} .p-ftr-cta-h { font-size: clamp(22px, 3vw, 32px); line-height: 1.08; margin: 0; font-weight: 700; letter-spacing: -0.01em; color: {{ $textColor }}; }
.{{ $instId }} .p-ftr-cta-hl { color: {{ $ctaAccent }}; }
.{{ $instId }} .p-ftr-cta-actions { display: flex; align-items: center; gap: 16px; }
.{{ $instId }} .p-ftr-cta-btn { background: {{ $ctaAccent }}; color: {{ $ctaBtnText }}; font-weight: 700; font-size: 15px; padding: 14px 24px; border-radius: 10px; text-decoration: none; transition: filter .15s, transform .15s; white-space: nowrap; }
.{{ $instId }} .p-ftr-cta-btn:hover { filter: brightness(1.07); transform: translateY(-1px); }
.{{ $instId }} .p-ftr-cta-note { font-size: 13px; color: {{ $mutedColor }}; }
@media (max-width: 600px) { .{{ $instId }} .p-ftr-cta-inner { flex-direction: column; align-items: flex-start; gap: 18px; } }
</style>

<footer class="{{ $instId }} p-footer {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  @php
    $ctaOn      = (bool) ($c['cta_band'] ?? false);
    $ctaEyebrow = trim($c['cta_eyebrow'] ?? '');
    $ctaHeading = trim($c['cta_heading'] ?? '');
    $ctaHl      = trim($c['cta_highlight'] ?? '');
    $ctaBtn     = trim($c['cta_button_label'] ?? '');
    $ctaUrl     = trim($c['cta_button_url'] ?? '');
    $ctaNote    = trim($c['cta_note'] ?? '');
    $ctaAccent  = ($tenant->accent_color ?? '') ?: '#3FD16B';
    $ctaBtnText = \App\Support\ColorHelper::accentTextColor($ctaAccent);
    $ctaHeadingHtml = e($ctaHeading);
    if ($ctaHl !== '' && mb_stripos($ctaHeading, $ctaHl) !== false) {
        $ctaHeadingHtml = str_ireplace(e($ctaHl), '<span class="p-ftr-cta-hl">'.e($ctaHl).'</span>', e($ctaHeading));
    }
  @endphp
  @if($ctaOn && ($ctaHeading !== '' || $ctaBtn !== ''))
  <div class="p-ftr-cta">
    <div class="p-ftr-cta-inner">
      <div class="p-ftr-cta-text">
        @if($ctaEyebrow !== '')<div class="p-ftr-cta-eyebrow">{{ $ctaEyebrow }}</div>@endif
        @if($ctaHeading !== '')<h2 class="p-ftr-cta-h">{!! $ctaHeadingHtml !!}</h2>@endif
      </div>
      @if($ctaBtn !== '')
      <div class="p-ftr-cta-actions">
        <a href="{{ $ctaUrl ?: '#' }}" class="p-ftr-cta-btn">{{ $ctaBtn }}</a>
        @if($ctaNote !== '')<span class="p-ftr-cta-note">{{ $ctaNote }}</span>@endif
      </div>
      @endif
    </div>
  </div>
  @endif
  <div class="p-ftr-wrap">

    <div class="p-ftr-top">
      <div class="p-ftr-brand">
        @if($showLogo)
          <a href="/" class="p-ftr-logo">
            @if($logoUrl)
              <img src="{{ $logoUrl }}" alt="{{ $tenant->name ?? 'Logo' }}">
            @else
              {{ $tenant->name ?? 'Logo' }}
            @endif
          </a>
        @endif

        @if($tagline !== '')
          <p class="p-ftr-tagline">{{ $tagline }}</p>
        @endif

        @if(!empty($socialLinks))
          <div class="p-ftr-social">
            @foreach($socialLinks as $s)
              @php
                $platform = $s['platform'] ?? 'website';
                $url      = $s['url'] ?? '';
                if ($platform === 'email' && $url !== '' && !str_starts_with($url, 'mailto:')) {
                    $url = 'mailto:' . $url;
                }
              @endphp
              @if($url !== '')
                <a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ ucfirst($platform) }}">
                  {!! $iconFor($platform) !!}
                </a>
              @endif
            @endforeach
          </div>
        @endif
      </div>

      @if($layout === 'columns' || $layout === 'centered')
        @foreach($linkColumns as $col)
          @php
            $heading = $col['heading'] ?? '';
            $links   = is_array($col['links'] ?? null) ? $col['links'] : [];
          @endphp
          @if($heading !== '' || !empty($links))
            <div class="p-ftr-col">
              @if($heading !== '')
                <h4 class="p-ftr-col-heading">{{ $heading }}</h4>
              @endif
              @if(!empty($links))
                <ul>
                  @foreach($links as $li)
                    @php $label = $li['label'] ?? ''; $url = $li['url'] ?? '#'; @endphp
                    @if($label !== '')
                      <li><a href="{{ $url }}">{{ $label }}</a></li>
                    @endif
                  @endforeach
                </ul>
              @endif
            </div>
          @endif
        @endforeach

        @if($hasContactBlock)
          <div class="p-ftr-col">
            <h4 class="p-ftr-col-heading">Contact</h4>
            @if($showPhone)
              <p class="p-ftr-contact-line">
                <strong>Phone</strong>
                <a href="tel:{{ $cPhone }}">{{ $cPhone }}</a>
              </p>
            @endif
            @if($showEmail)
              <p class="p-ftr-contact-line">
                <strong>Email</strong>
                <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
              </p>
            @endif
            @if($showAddress)
              <p class="p-ftr-contact-line">
                <strong>Address</strong>
                {{ $cAddress }}
              </p>
            @endif
            @if($showHours)
              <p class="p-ftr-contact-line">
                <strong>Hours</strong>
                {{ $cHours }}
              </p>
            @endif
          </div>
        @endif

        {{-- MARKER-PATCH-158-G29 — inline contact form column --}}
        @if($showForm)
          <div class="p-ftr-col">
            @if($formHeading !== '')
              <h4 class="p-ftr-form-heading">{{ $formHeading }}</h4>
            @endif

            @if(session('contact_success'))
              <div class="p-ftr-form-success">{{ $formSuccess }}</div>
            @else
              @if($formDescription !== '')
                <p class="p-ftr-form-desc">{{ $formDescription }}</p>
              @endif

              <form method="POST" action="/contact" class="p-ftr-form">
                @csrf
                {{-- MARKER-PATCH-399 honeypot — bots fill this; real users never see or focus it --}}
                <input type="text" name="company_website" tabindex="-1" autocomplete="off" aria-hidden="true"
                       style="position:absolute !important;left:-9999px !important;top:auto;width:1px;height:1px;opacity:0;pointer-events:none">
                @if($errors->any())
                  <div class="p-ftr-form-error">{{ $errors->first() }}</div>
                @endif
                {{-- MARKER-CONTACT-NAMES — two fields, both required: one
                     combined field let people submit a first name only. --}}
                <input type="text" name="first_name" placeholder="First name" value="{{ old('first_name') }}" required>
                <input type="text" name="last_name" placeholder="Last name" value="{{ old('last_name') }}" required>
                <input type="email" name="email" placeholder="you@example.com" value="{{ old('email') }}" required>
                @if($formShowPhone)
                  <input type="tel" name="phone" placeholder="{{ $formRequirePhone ? 'Phone' : 'Phone (optional)' }}" value="{{ old('phone') }}" {{ $formRequirePhone ? 'required' : '' }}>
                @endif
                <textarea name="message" rows="2" placeholder="How can we help?" required>{{ old('message') }}</textarea>
                <button type="submit">{{ $formButton }}</button>
              </form>
            @endif
          </div>
        @endif
      @endif
    </div>

    <div class="p-ftr-bottom {{ $showPoweredBy ? 'p-ftr-bottom--has-badge' : '' }}">
      <span>{{ $copyText }}</span>
      @if($showPoweredBy)
        <span>Powered by <a href="https://intake.works" target="_blank" rel="noopener">Intake</a></span>
      @endif
    </div>

  </div>
</footer>
CNAME_1_EOF

cat > 'resources/views/public/sections/_contact_form.blade.php' <<'CNAME_2_EOF'
{{-- MARKER-PATCH-158-G24 — contact_form public renderer (v2) --}}
@php
  $c = $c ?? [];

  // Layout
  $widthMap = ['narrow'=>'440px','medium'=>'580px','wide'=>'720px','full'=>'100%'];
  $formWidth = $widthMap[$c['form_width'] ?? 'medium'] ?? '580px';
  $hAlign = $c['text_align'] ?? 'center';

  $padTokens = ['none'=>'0','compact'=>'40px','normal'=>'80px','spacious'=>'120px'];
  $padTop    = $padTokens[$c['padding_top']    ?? 'normal'] ?? '80px';
  $padBot    = $padTokens[$c['padding_bottom'] ?? 'normal'] ?? '80px';

  // Background
  $bgMode  = $c['bg_mode'] ?? 'none';
  $bgColor = $c['bg_color'] ?? '#ffffff';
  $gradF   = $c['bg_gradient_from'] ?? '#ffffff';
  $gradT   = $c['bg_gradient_to']   ?? '#fafafa';

  // Colors
  $textColor     = ($c['text_color']      ?? '') ?: '#0a0a0a';
  $textColorBody = ($c['text_color_body'] ?? '') ?: 'rgba(0,0,0,0.65)';
  $accentColor   = ($c['accent_color']    ?? '') ?: null;

  // Fields
  $showPhone   = (bool)($c['show_phone']   ?? true);
  $showMessage = (bool)($c['show_message'] ?? true);
  $labelName    = $c['label_name']    ?? 'Name';
  $labelEmail   = $c['label_email']   ?? 'Email';
  $labelPhone   = $c['label_phone']   ?? 'Phone';
  $labelMessage = $c['label_message'] ?? 'Message';
  $placeholderMessage = $c['placeholder_message'] ?? 'How can we help you?';
  $messageRows = max(2, min(20, (int)($c['message_rows'] ?? 5)));

  // Input style
  $inputStyle  = $c['input_style']  ?? 'default';   // default | minimal | filled
  $inputRadius = $c['input_radius'] ?? 'medium';
  $radiusMap   = ['none'=>'0','small'=>'4px','medium'=>'8px','large'=>'12px','pill'=>'9999px'];
  $inputRadiusVal = $radiusMap[$inputRadius] ?? '8px';

  // Button + text
  $submitLabel  = $c['submit_label']  ?? 'Send message';
  $successText  = $c['success_text']  ?? 'Thanks! We\'ll be in touch soon.';
  $privacyText  = trim($c['privacy_text'] ?? '');

  // Heading with accent
  $headingHtml = e($c['heading'] ?? '');
  $accentWords = trim($c['accent_words'] ?? '');
  if ($accentWords !== '' && stripos($headingHtml, e($accentWords)) !== false) {
      $headingHtml = str_ireplace(
          e($accentWords),
          '<span class="p-cf-accent">' . e($accentWords) . '</span>',
          $headingHtml
      );
  }

  // Advanced
  $anchorId    = trim($c['anchor_id'] ?? '');
  $customClass = trim($c['custom_classes'] ?? '');
  $hideMobile  = !empty($c['hide_on_mobile']);
  $hideDesktop = !empty($c['hide_on_desktop']);

  $instId = 'p-cf-' . ($section->id ?? uniqid());
@endphp

<style>
.{{ $instId }} {
  padding-top: {{ $padTop }};
  padding-bottom: {{ $padBot }};
  @if($bgMode === 'color') background: {{ $bgColor }};
  @elseif($bgMode === 'gradient') background: linear-gradient({{ (int)($c['bg_gradient_angle'] ?? 135) }}deg, {{ $gradF }} 0%, {{ $gradT }} 100%);
  @endif
}
.{{ $instId }} .p-cf-wrap {
  max-width: {{ $formWidth }};
  margin: 0 auto;
  padding: 0 clamp(20px, 5vw, 32px);
}
.{{ $instId }} .p-cf-head {
  text-align: {{ $hAlign }};
  margin-bottom: 32px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-cf-eyebrow {
  font-family: ui-monospace, monospace;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: {{ $accentColor ?? $textColor }};
  margin-bottom: 12px;
  opacity: .9;
}
.{{ $instId }} .p-cf-heading {
  font-size: clamp(22px, 3vw, 36px);
  font-weight: 500;
  letter-spacing: -.02em;
  margin: 0 0 12px;
  line-height: 1.15;
  color: {{ $textColor }};
}
.{{ $instId }} .p-cf-accent { color: {{ $accentColor ?? '#BEF264' }}; }
.{{ $instId }} .p-cf-sub {
  font-size: 16px;
  line-height: 1.55;
  color: {{ $textColorBody }};
  margin: 0;
  max-width: 540px;
  @if($hAlign === 'center') margin-left: auto; margin-right: auto; @endif
}
.{{ $instId }} .p-cf-flash {
  padding: 14px 16px;
  border-radius: {{ $inputRadiusVal }};
  font-size: 14px;
  margin-bottom: 16px;
  text-align: {{ $hAlign }};
}
.{{ $instId }} .p-cf-flash--success {
  background: rgba(48,179,84,0.08);
  color: #1f7a35;
  border: 1px solid rgba(48,179,84,0.25);
}
.{{ $instId }} .p-cf-flash--error {
  background: rgba(220,53,69,0.07);
  color: #b3232f;
  border: 1px solid rgba(220,53,69,0.25);
}

.{{ $instId }} .p-cf-form-group { margin-bottom: 16px; }
.{{ $instId }} .p-cf-form-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
@media (max-width: 560px) {
  .{{ $instId }} .p-cf-form-row { grid-template-columns: 1fr; }
}
.{{ $instId }} .p-cf-label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 6px;
  color: {{ $textColor }};
}
.{{ $instId }} .p-cf-input {
  width: 100%;
  font-family: inherit;
  font-size: 15px;
  color: {{ $textColor }};
  padding: 11px 14px;
  border-radius: {{ $inputRadiusVal }};
  transition: all 0.15s;
  outline: none;
  @if($inputStyle === 'default')
  background: white;
  border: 1px solid rgba(0,0,0,0.15);
  @elseif($inputStyle === 'minimal')
  background: transparent;
  border: none;
  border-bottom: 1px solid rgba(0,0,0,0.2);
  border-radius: 0;
  padding-left: 0; padding-right: 0;
  @elseif($inputStyle === 'filled')
  background: rgba(0,0,0,0.04);
  border: 1px solid transparent;
  @endif
}
.{{ $instId }} .p-cf-input:focus {
  border-color: {{ $accentColor ?? '#BEF264' }};
  @if($inputStyle === 'minimal')
  border-bottom-color: {{ $accentColor ?? '#BEF264' }};
  @endif
}
.{{ $instId }} textarea.p-cf-input { resize: vertical; min-height: 100px; }

.{{ $instId }} .p-cf-submit {
  width: 100%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 13px 26px;
  border-radius: {{ $inputRadiusVal }};
  font-size: 15px;
  font-weight: 500;
  cursor: pointer;
  border: 0;
  background: {{ $accentColor ?? '#0a0a0a' }};
  color: {{ $accentColor ? '#0a1a00' : '#ffffff' }};
  transition: filter 0.15s;
}
.{{ $instId }} .p-cf-submit:hover { filter: brightness(1.05); }

.{{ $instId }} .p-cf-privacy {
  font-size: 12px;
  color: {{ $textColorBody }};
  margin-top: 14px;
  text-align: {{ $hAlign }};
  opacity: .8;
}
.{{ $instId }} .p-cf-footnote {
  font-family: ui-monospace, monospace;
  font-size: 12px;
  color: {{ $textColorBody }};
  margin-top: 24px;
  text-align: {{ $hAlign }};
}

@if($hideMobile)
@media (max-width: 768px) { .{{ $instId }} { display: none; } }
@endif
@if($hideDesktop)
@media (min-width: 769px) { .{{ $instId }} { display: none; } }
@endif
</style>

<section class="{{ $instId }} p-contact-form {{ $customClass }}" @if($anchorId) id="{{ $anchorId }}" @endif>
  <div class="p-cf-wrap">

    @if(!empty($c['eyebrow']) || !empty($c['heading']) || !empty($c['subheading']))
      <div class="p-cf-head">
        @if(!empty($c['eyebrow']))
          <div class="p-cf-eyebrow">{{ $c['eyebrow'] }}</div>
        @endif
        @if(!empty($c['heading']))
          <h2 class="p-cf-heading">{!! $headingHtml !!}</h2>
        @endif
        @if(!empty($c['subheading']))
          <p class="p-cf-sub">{{ $c['subheading'] }}</p>
        @endif
      </div>
    @endif

    @if(session('contact_success'))
      <div class="p-cf-flash p-cf-flash--success">{{ $successText }}</div>
    @endif

    <form method="POST" action="/contact">
      @csrf

      @if($errors->any())
        <div class="p-cf-flash p-cf-flash--error">{{ $errors->first() }}</div>
      @endif

      <div class="p-cf-form-group">
        <label class="p-cf-label">{{ $labelName }} *</label>
        {{-- MARKER-CONTACT-NAMES — split into first and last, both required --}}
        <input type="text" name="first_name" class="p-cf-input" value="{{ old('first_name') }}" required placeholder="First name">
        <input type="text" name="last_name" class="p-cf-input" value="{{ old('last_name') }}" required placeholder="Last name" style="margin-top:8px">
      </div>

      @if($showPhone)
        <div class="p-cf-form-row">
          <div class="p-cf-form-group">
            <label class="p-cf-label">{{ $labelEmail }} *</label>
            <input type="email" name="email" class="p-cf-input" value="{{ old('email') }}" required placeholder="">
          </div>
          <div class="p-cf-form-group">
            <label class="p-cf-label">{{ $labelPhone }}</label>
            <input type="tel" name="phone" class="p-cf-input" value="{{ old('phone') }}" placeholder="">
          </div>
        </div>
      @else
        <div class="p-cf-form-group">
          <label class="p-cf-label">{{ $labelEmail }} *</label>
          <input type="email" name="email" class="p-cf-input" value="{{ old('email') }}" required placeholder="">
        </div>
      @endif

      @if($showMessage)
        <div class="p-cf-form-group">
          <label class="p-cf-label">{{ $labelMessage }} *</label>
          <textarea name="message" class="p-cf-input" rows="{{ $messageRows }}" required placeholder="{{ $placeholderMessage }}">{{ old('message') }}</textarea>
        </div>
      @else
        {{-- Hidden minimal placeholder so the backend's "required" validation
             on message still passes when the field is hidden. Sends a single
             space which trims to "" — actually fails validation. Need an
             actual default if hidden. --}}
        <input type="hidden" name="message" value="(no message)">
      @endif

      <button type="submit" class="p-cf-submit">{{ $submitLabel }}</button>

      @if($privacyText !== '')
        <div class="p-cf-privacy">{{ $privacyText }}</div>
      @endif
    </form>

    @if(!empty($c['note']))
      <div class="p-cf-footnote">{{ $c['note'] }}</div>
    @endif

  </div>
</section>
CNAME_2_EOF

cat > 'resources/views/tenant/inbox/index.blade.php' <<'CNAME_3_EOF'
@extends('layouts.tenant.app')
@php $pageTitle = 'Inbox'; @endphp

{{-- MARKER-PATCH-221 — unified inbox: two-pane SMS conversations. --}}

@push('styles')
<style>
  .ib-wrap { display:grid; grid-template-columns:340px 1fr; gap:0; border-radius:12px; overflow:hidden;
             box-shadow:inset 0 0 0 .5px var(--ia-border); background:var(--ia-surface); min-height:560px; }
  @media (max-width: 980px) { .ib-wrap { grid-template-columns:1fr; } .ib-conv { display:none; } .ib-conv.has-sel { display:flex; } }
  .ib-list { border-right:.5px solid var(--ia-border); display:flex; flex-direction:column; }
  .ib-filters { display:flex; gap:6px; padding:12px; border-bottom:.5px solid var(--ia-border); }
  .ib-pill { font-size:11.5px; padding:4px 10px; border-radius:999px; box-shadow:inset 0 0 0 .5px var(--ia-border);
             text-decoration:none; color:inherit; opacity:.7; }
  .ib-pill.is-active { background:var(--ia-text); color:var(--ia-bg, #fff); opacity:1; }
  .ib-thread { display:block; padding:12px 14px; border-bottom:.5px solid var(--ia-border); text-decoration:none; color:inherit; }
  .ib-thread:hover, .ib-thread.is-sel { background:rgba(127,127,127,.06); }
  .ib-thread-top { display:flex; justify-content:space-between; gap:8px; align-items:baseline; }
  .ib-thread-name { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ib-thread-time { font-size:10.5px; opacity:.45; white-space:nowrap; }
  .ib-snippet { font-size:12px; opacity:.55; margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .ib-dot { width:8px; height:8px; border-radius:50%; background:#B8801A; display:inline-block; margin-right:6px; }
  .ib-conv { display:flex; flex-direction:column; min-width:0; }
  .ib-conv-head { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:12px 16px; border-bottom:.5px solid var(--ia-border); }
  .ib-msgs { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:10px; }
  .ib-msg { max-width:72%; padding:9px 12px; border-radius:12px; font-size:13px; line-height:1.45; white-space:pre-wrap; word-break:break-word; }
  .ib-msg.in  { align-self:flex-start; background:rgba(127,127,127,.10); border-bottom-left-radius:4px; }
  .ib-msg.out { align-self:flex-end; background:var(--ia-text); color:var(--ia-bg, #fff); border-bottom-right-radius:4px; }
  .ib-msg.note { align-self:stretch; max-width:none; background:#FAEEDA; color:#854F0B; font-size:12.5px; }
  .ib-msg.sys  { align-self:center; max-width:none; background:transparent; box-shadow:inset 0 0 0 .5px var(--ia-border); font-size:11.5px; opacity:.7; }
  .ib-msg-time { font-size:10px; opacity:.45; margin-top:4px; }
  .ib-compose { border-top:.5px solid var(--ia-border); padding:12px 16px; }
  .ib-empty { display:flex; align-items:center; justify-content:center; flex:1; font-size:13px; opacity:.5; padding:40px; text-align:center; }
  /* MARKER-PATCH-433 — mobile: full-screen conversation + back arrow */
  .ib-back { display:none; }
  @media (max-width: 980px) {
    .ib-conv.has-sel { position:fixed; inset:0; z-index:500; background:var(--ia-surface); border-radius:0; }
    .ib-conv.has-sel .ib-conv-head { padding-top:max(12px, env(safe-area-inset-top)); }
    .ib-conv.has-sel .ib-msgs { overscroll-behavior:contain; }
    .ib-conv.has-sel .ib-compose { padding-bottom:max(12px, env(safe-area-inset-bottom)); }
    .ib-conv-head-left { display:flex; align-items:center; gap:10px; min-width:0; }
    .ib-back { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; flex:0 0 auto; margin:-4px 2px -4px -6px; border-radius:8px; text-decoration:none; color:inherit; font-size:21px; line-height:1; opacity:.75; }
    .ib-back:active, .ib-back:hover { background:rgba(127,127,127,.12); opacity:1; }
  }
  /* MARKER-PATCH-434 — mobile inbox styling to match the approved mockup */
  .ib-nr { display:none; }
  .ib-conv-name { font-size:14px; }
  .ib-compose-meta { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:8px; }
  .ib-compose-row { display:flex; gap:10px; align-items:flex-end; }
  .ib-compose-field { flex:1 1 auto; min-width:0; }
  .ib-compose-send { flex:0 0 auto; }
  .ib-send-ar { display:none; }
  @media (max-width: 980px) {
    .ib-sub-more { display:none; }
    /* thread list — airier rows, mockup sizing */
    .ib-thread { padding:15px 16px; }
    .ib-thread-name { font-size:16px; }
    .ib-thread-time { font-size:12px; }
    .ib-snippet { font-size:14px; margin-top:4px; }
    .ib-dot { width:9px; height:9px; background:var(--ia-accent); margin-right:8px; }
    .ib-nr { display:inline-block; font-size:10px; font-weight:700; letter-spacing:.04em; color:#B8801A; border:1px solid rgba(184,128,26,.45); border-radius:6px; padding:1px 6px; margin-left:8px; vertical-align:middle; }
    /* conversation — bigger text, green outbound bubbles */
    .ib-conv-name { font-size:17px; }
    .ib-msgs { padding:14px 16px; }
    .ib-msg { max-width:80%; padding:10px 13px; border-radius:15px; font-size:14px; }
    .ib-msg.in  { background:var(--ia-surface-2); border-bottom-left-radius:5px; }
    .ib-msg.out { background:#2a4a2a; color:#eafce0; border-bottom-right-radius:5px; }
    /* composer — pill field + round send */
    .ib-compose-field { border-radius:20px; min-height:44px; padding:11px 16px; }
    .ib-compose-row { gap:8px; }
    .ib-compose-send { width:44px; height:44px; min-width:44px; border-radius:50%; padding:0; display:flex; align-items:center; justify-content:center; }
    .ib-send-txt { display:none; }
    .ib-send-ar { display:inline; font-size:19px; line-height:1; }
  }
  /* MARKER-PATCH-435 — mobile: hide the empty pane, edge-to-edge list, fix row overflow */
  @media (max-width: 980px) {
    .ib-conv { display:none; }            /* empty "pick a conversation" pane stays hidden on phones */
    .ib-conv.has-sel { display:flex; }    /* a selected conversation still shows (full-screen overlay) */
    .ib-wrap { min-width:0; border-radius:0; box-shadow:none; min-height:0; background:transparent; }
    .ib-list { min-width:0; border-right:0; }
    .ib-thread-name { min-width:0; }
  }
</style>
@endpush

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Inbox</h1>
    <p class="ia-page-subtitle">Every customer text in one place.<span class="ib-sub-more"> Replies, internal notes, and what needs your attention.</span></p>
  </div>
</div>

@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:16px">{{ $errors->first() }}</div>
@endif

<div class="ib-wrap">
  <div class="ib-list">
    <div class="ib-filters">
      <a class="ib-pill {{ $filter === 'all' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index') }}">Open</a>
      <a class="ib-pill {{ $filter === 'unread' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index', ['filter' => 'unread']) }}">Needs reply{{ $needsReplyCount > 0 ? ' (' . $needsReplyCount . ')' : '' }}</a>
      <a class="ib-pill {{ $filter === 'closed' ? 'is-active' : '' }}" href="{{ route('tenant.inbox.index', ['filter' => 'closed']) }}">Closed</a>
    </div>
    <div style="overflow-y:auto;flex:1">
      @forelse($threads as $t)
        <a class="ib-thread {{ $selected && $selected->id === $t->id ? 'is-sel' : '' }}"
           href="{{ route('tenant.inbox.index', array_filter(['filter' => $filter !== 'all' ? $filter : null, 'thread' => $t->id])) }}">
          <div class="ib-thread-top">
            <span class="ib-thread-name">
              @if((int) $t->unread_count > 0 || $t->status === 'needs_reply')<span class="ib-dot"></span>@endif
              {{ $t->customer?->fullName() }}
              @if($t->status === 'needs_reply')<span class="ib-nr">Needs reply</span>@endif
            </span>
            <span class="ib-thread-time">{{ $t->last_message_at ? tlocal_datetime($t->last_message_at, 'M j, g:i A') : '' }}</span>
          </div>
          <div class="ib-snippet">{{ \Illuminate\Support\Str::limit($t->latestMessage?->body ?? '', 70) }}</div>
        </a>
      @empty
        <div style="padding:30px 16px;font-size:12.5px;opacity:.5;text-align:center">
          No conversations here yet. Inbound texts to your business number land in this list automatically.
        </div>
      @endforelse
    </div>
  </div>

  <div class="ib-conv {{ $selected ? 'has-sel' : '' }}">
    @if(!$selected)
      <div class="ib-empty">Pick a conversation — or text your business number to see one arrive.</div>
    @else
      <div class="ib-conv-head">
        <div class="ib-conv-head-left">
        <a class="ib-back" href="{{ route('tenant.inbox.index', array_filter(['filter' => $filter !== 'all' ? $filter : null])) }}" aria-label="Back to conversations">&lsaquo;</a>
        <div style="min-width:0">
          <a href="{{ route('tenant.customers.show', $selected->customer_id) }}" class="ib-conv-name" style="font-weight:700;text-decoration:none;color:inherit">{{ $selected->customer?->fullName() }}</a>
          <div style="font-size:11.5px;opacity:.55">
            {{ $selected->customer?->phone ?? 'no phone' }}
            @if($selected->customer?->email) · {{ $selected->customer?->email }}@endif
            @if($selected->customer?->sms_opt_out_at) · <span style="color:#A32D2D;font-weight:600">opted out (STOP)</span>@endif
          </div>
        </div>
        </div>
        <form method="POST" action="{{ route('tenant.inbox.status', $selected->id) }}">@csrf
          {{-- MARKER-INBOX-CLOSE — on a phone "Close" reads as "close this message",
     which is the opposite of what it does. --}}
<button type="submit" class="ia-btn" style="font-size:11.5px">{{ $selected->status === 'closed' ? 'Reopen ticket' : 'Close ticket' }}</button>
        </form>
      </div>

      <div class="ib-msgs" id="ib-msgs">
        @forelse($selected->messages as $m)
          @php
            $cls = match (true) {
              $m->kind === 'internal_note' => 'note',
              $m->direction === 'system'   => 'sys',
              $m->direction === 'in'       => 'in',
              default                      => 'out',
            };
          @endphp
          <div class="ib-msg {{ $cls }}">
            {{-- MARKER-PATCH-401 — delete a single message --}}
            <form method="POST" action="{{ route('tenant.inbox.message.delete', $m->id) }}" onsubmit="return confirm('Delete this message? It will be hidden from the conversation.')" style="float:right;margin:-2px -2px 0 8px">
              @csrf
              <button type="submit" title="Delete message" style="background:none;border:0;color:inherit;opacity:.3;cursor:pointer;font-size:14px;line-height:1;padding:0">&times;</button>
            </form>
            @if($cls === 'note')<strong>Internal note · </strong>@endif{{ $m->body }}
            <div class="ib-msg-time">@if($cls === 'in' || $cls === 'out'){{ strtoupper($m->channel) }} · @endif{{ tlocal_datetime($m->created_at, 'M j, g:i A') }}</div>
          </div>
        @empty
          <div class="ib-empty">No messages yet.</div>
        @endforelse
      </div>

      @php
        // MARKER-PATCH-397 — default the reply channel to the customer's last inbound.
        $lastIn = $selected->messages->where('direction', 'in')->last();
        $replyDefault = in_array($lastIn?->channel ?? '', ['web', 'email'], true) ? 'email' : 'sms';
      @endphp
      <div class="ib-compose">
        <form method="POST" action="{{ route('tenant.inbox.send', $selected->id) }}">
          @csrf
          <div class="ib-compose-meta">
            <label style="font-size:12px;opacity:.7;display:flex;align-items:center;gap:6px">
              Reply via
              <select name="reply_channel" class="ia-input" style="font-size:12px;padding:3px 6px;width:auto">
                <option value="sms"   {{ $replyDefault === 'sms'   ? 'selected' : '' }}>Text (SMS)</option>
                <option value="email" {{ $replyDefault === 'email' ? 'selected' : '' }}>Email</option>
              </select>
            </label>
            <label style="font-size:12px;opacity:.7;display:flex;align-items:center;gap:6px">
              <input type="checkbox" name="as_note" value="1"> Internal note
            </label>
          </div>
          <div class="ib-compose-row">
            <textarea name="body" rows="2" maxlength="1200" required placeholder="Type your reply…" class="ia-input ib-compose-field" style="resize:vertical"></textarea>
            <button type="submit" class="ia-btn ia-btn--primary ib-compose-send"><span class="ib-send-txt">Send</span><span class="ib-send-ar" aria-hidden="true">&uarr;</span></button>
          </div>
        </form>
      </div>
    @endif
  </div>
</div>

<script>
  (function () { var m = document.getElementById('ib-msgs'); if (m) m.scrollTop = m.scrollHeight; })();
</script>

@endsection
CNAME_3_EOF

echo "contact-names-and-inbox-close applied — server: git pull && php artisan view:clear"
