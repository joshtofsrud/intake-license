<?php

namespace App\Console\Commands;

use App\Http\Controllers\Tenant\PageBuilderController;
use App\Models\Tenant;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-HOME-DRAFT — builds "Home (new)": an UNPUBLISHED intake.works page made from the current
 * home page plus the new sections, written for three buyers: the one who wants better service
 * and tools, the one who wants a return, and the one afraid of switching.
 *
 * Nothing live changes. Review it in the builder, then make it the home page when it's right.
 */
class MarketingBuildHomeDraft extends Command
{
    protected $signature   = 'marketing:build-home-draft {--slug=home-new : Slug for the draft page}';
    protected $description = 'Build an unpublished "Home (new)" page on intake.works from the current home page plus the new sections';

    public function handle(): int
    {
        $t = Tenant::where('is_platform', true)->first();
        if (! $t) { $this->error('No platform tenant found.'); return self::FAILURE; }
        $home = TenantPage::where('tenant_id', $t->id)->where('is_home', true)->first();
        if (! $home) { $this->error('intake.works has no home page to start from.'); return self::FAILURE; }
        $slug = (string) $this->option('slug');
        if (TenantPage::where('tenant_id', $t->id)->where('slug', $slug)->exists()) {
            $this->error("A page with slug \"$slug\" already exists. Delete it in the builder first, or pass --slug=something-else.");
            return self::FAILURE;
        }

        $src      = $home->sections()->orderBy('sort_order')->get();
        $defaults = (new \ReflectionClassConstant(PageBuilderController::class, 'DEFAULTS'))->getValue();
        $first    = fn (string $type) => $src->firstWhere('section_type', $type);
        $arr      = function ($v) { if (is_string($v)) { $d = json_decode($v, true); return is_array($d) ? $d : []; } return is_array($v) ? $v : []; };

        $fixUrl = function (string $label, string $url): string {
            $l = mb_strtolower($label); $u = trim($url);
            if ($u === '' || $u === '#') {
                if (str_contains($l, 'demo') && preg_match('/enter|live|try|see|walk/', $l)) return '/demo';
                if (preg_match('/schedule|call|book/', $l)) return '#book:demo';
                if (preg_match('/trial|sign ?up|start/', $l)) return 'https://app.intake.works/signup';
            }
            if (preg_match('#^(https?://intake\.works)?/signup(\?.*)?$#', $u, $m)) return 'https://app.intake.works/signup' . ($m[2] ?? '');
            return $u;
        };
        $fixButtons = function (array $c) use ($arr, $fixUrl) {
            $b = $arr($c['buttons'] ?? []);
            foreach ($b as $i => $btn) { $b[$i]['url'] = $fixUrl((string) ($btn['label'] ?? ''), (string) ($btn['url'] ?? '')); }
            $c['buttons'] = $b;
            return $c;
        };

        $plan = [];
        $keep = fn ($s) => $s ? ['type' => $s->section_type, 'content' => (array) $s->content, 'from' => $s] : null;

        // 1. Nav (as is)
        if ($n = $first('nav')) $plan[] = $keep($n);

        // 2. Hero — same words; the demo buttons get real links; the trial link goes to the app
        if ($h = $first('hero')) {
            $p = $keep($h);
            $p['content'] = $fixButtons($p['content']);
            $plan[] = $p;
        }

        // 3. "Your day, in one place" — the accordion from another page if there is one, else a starter
        $acc = TenantPageSection::where('tenant_id', $t->id)->where('section_type', 'text_image')->get()
            ->first(fn ($s) => (($s->content['ti_style'] ?? '') === 'accordion'));
        $accC = $acc ? (array) $acc->content : array_merge($defaults['text_image'] ?? [], [
            'ti_style' => 'accordion',
            'acc_items' => [
                ['title' => 'Dashboard', 'body' => 'What needs you, what is booked today, and how the business is doing, on one screen.', 'image_url' => '', 'image_alt' => '', 'cta_label' => '', 'cta_url' => ''],
                ['title' => 'Register', 'body' => 'Sales, services, deposits and gift cards at the counter, with receipts by text or email.', 'image_url' => '', 'image_alt' => '', 'cta_label' => '', 'cta_url' => ''],
                ['title' => 'Inventory', 'body' => 'Stock that updates as you sell, with reorder points and what is slow to move.', 'image_url' => '', 'image_alt' => '', 'cta_label' => '', 'cta_url' => ''],
                ['title' => 'Marketing', 'body' => 'Email the customers who said yes, and bring back the ones who have gone quiet.', 'image_url' => '', 'image_alt' => '', 'cta_label' => '', 'cta_url' => ''],
            ],
        ]);
        $accC['eyebrow'] = 'One login';
        $accC['heading'] = 'Your whole day, in one place';
        $accC['accent_words'] = 'one place';
        $accC['body'] = 'Open each part to see it.';
        $plan[] = ['type' => 'text_image', 'content' => $accC, 'from' => $acc];

        // 4. ROI — the return, with sources
        $plan[] = ['type' => 'roi', 'content' => $defaults['roi'] ?? [], 'from' => null];

        // 5. Who it's for — links to the industry pages that exist
        $ind = [];
        foreach ([['bike', 'Bike shops', 'Service, rentals and retail, with work orders from drop-off to pickup.'],
                  ['fitness', 'Fitness studios', 'Classes, memberships and bookings, with reminders that fill the room.'],
                  ['solo', 'Solo practitioners', 'Bookings, payments and follow-ups, without hiring a front desk.']] as [$needle, $title, $body]) {
            $pg = TenantPage::where('tenant_id', $t->id)->where(fn ($q) => $q->where('slug', 'like', "%$needle%")->orWhere('title', 'like', "%$needle%"))->first();
            $ind[] = ['icon' => '', 'title' => $title, 'price' => '', 'body' => $body, 'cta_label' => $pg ? 'See how it works' : '', 'cta_url' => $pg ? '/' . ltrim($pg->slug, '/') : ''];
        }
        $plan[] = ['type' => 'feature_grid', 'from' => null, 'content' => array_merge($defaults['feature_grid'] ?? [], [
            'eyebrow' => "Who it's for", 'heading' => 'Built for shops like yours', 'accent_words' => '', 'subheading' => '',
            'features' => $ind, 'columns' => 3, 'card_style' => 'minimal', 'show_icons' => false, 'text_align' => 'left',
        ])];

        // 6. What you can drop — the scrolling words, brand names written properly
        $brands = ['square' => 'Square', 'shopify' => 'Shopify', 'mail chimp' => 'Mailchimp', 'mailchimp' => 'Mailchimp', 'booqable' => 'Booqable', 'quickbooks' => 'QuickBooks', 'wix' => 'Wix', 'squarespace' => 'Squarespace', 'lightspeed' => 'Lightspeed'];
        foreach ($src->where('section_type', 'scroll_words') as $sw) {
            $p = $keep($sw);
            $words = preg_split('/\r?\n/', (string) ($p['content']['words'] ?? ''));
            $p['content']['words'] = implode("\n", array_map(fn ($w) => $brands[mb_strtolower(trim($w))] ?? trim($w), array_filter($words, fn ($w) => trim($w) !== '')));
            $plan[] = $p;
        }

        // 7. Switching is the easy part — for the cautious buyer
        $plan[] = ['type' => 'feature_grid', 'from' => null, 'content' => array_merge($defaults['feature_grid'] ?? [], [
            'eyebrow' => 'Moving over', 'heading' => 'Switching is the easy part', 'accent_words' => 'the easy part', 'subheading' => '',
            'features' => [
                ['icon' => '', 'title' => 'Bring your customers and stock', 'price' => '', 'body' => 'Upload a spreadsheet, match the columns, and check it before anything is added.', 'cta_label' => '', 'cta_url' => ''],
                ['icon' => '', 'title' => 'Live in under 10 minutes', 'price' => '', 'body' => 'Add your services and hours and you can take bookings the same day.', 'cta_label' => '', 'cta_url' => ''],
                ['icon' => '', 'title' => 'Help from a shop owner', 'price' => '', 'body' => 'Book a call and we set your shop up with you.', 'cta_label' => 'Book a call', 'cta_url' => '#book:demo'],
                ['icon' => '', 'title' => 'Try it before you move', 'price' => '', 'body' => 'Walk around a working demo shop first. No account needed.', 'cta_label' => 'Enter the demo', 'cta_url' => '/demo'],
            ],
            'columns' => 4, 'card_style' => 'minimal', 'show_icons' => false, 'text_align' => 'left',
        ])];

        // 8. Pricing — same plans; the footnote no longer promises "no credit card"
        if ($pr = $first('pricing_table')) {
            $p = $keep($pr);
            $p['content']['footnote'] = '14-day free trial · No transaction fees';
            $plan[] = $p;
        }

        // 9. Questions before you switch
        $plan[] = ['type' => 'faq_accordion', 'from' => null, 'content' => array_merge($defaults['faq_accordion'] ?? [], [
            'eyebrow' => 'Before you switch', 'heading' => 'Questions shop owners ask', 'accent_words' => '', 'subheading' => '',
            'items' => [
                ['question' => 'Can I bring my customers and inventory across?', 'answer' => 'Yes. Upload a spreadsheet, match its columns to Intake, and check what will be added before you import it.', 'open_default' => false],
                ['question' => 'Who helps me get set up?', 'answer' => 'We do. Book a call and we will set up your shop with you.', 'open_default' => false],
                ['question' => 'Does Intake take a cut of my payments?', 'answer' => 'No. Payments go to your own Stripe or PayPal account, and Intake never takes a cut.', 'open_default' => false],
                ['question' => 'Can I use my own domain?', 'answer' => 'Yes, on Branded and Scale. Starter uses an intake.works address.', 'open_default' => false],
                ['question' => 'What does the trial include?', 'answer' => 'Everything on the plan you choose, for 14 days.', 'open_default' => false],
            ],
            'icon_style' => 'plus', 'open_mode' => 'single', 'text_align' => 'left',
        ])];

        // 10. Closing call to action — clearer words, same buttons with real links
        $cta = $src->filter(fn ($s) => in_array($s->section_type, ['cta_banner', 'book_call', 'try_demo'], true))->last();
        if ($cta) {
            $p = $keep($cta);
            if ($cta->section_type === 'cta_banner') {
                $p['content'] = $fixButtons($p['content']);
                $p['content']['subheading'] = 'We will walk you through it, help set up your shop, and show you how Intake keeps customers coming back.';
            }
            $plan[] = $p;
        }

        // 11. Footer (as is)
        if ($f = $first('footer')) $plan[] = $keep($f);

        DB::transaction(function () use ($t, $home, $slug, $plan, &$page) {
            $page = TenantPage::create([
                'tenant_id' => $t->id, 'title' => 'Home (new)', 'slug' => $slug,
                'meta_title' => $home->meta_title, 'meta_description' => $home->meta_description, 'og_image_url' => $home->og_image_url,
                'is_home' => false, 'is_published' => false, 'is_in_nav' => false,
            ]);
            foreach (array_values(array_filter($plan)) as $i => $p) {
                TenantPageSection::create([
                    'page_id' => $page->id, 'tenant_id' => $t->id, 'section_type' => $p['type'], 'content' => $p['content'],
                    'bg_color' => $p['from']->bg_color ?? null, 'padding' => ($p['from']->padding ?? null) ?: 'normal', // MARKER-HOME-DRAFT-PADDING
                    'is_visible' => true, 'sort_order' => ($i + 1) * 10,
                ]);
            }
        });

        $this->info('Built "Home (new)" (/' . $slug . '), unpublished, with ' . count(array_filter($plan)) . ' sections:');
        foreach (array_values(array_filter($plan)) as $i => $p) $this->line('  ' . ($i + 1) . '. ' . $p['type'] . ($p['from'] ? ($p['from']->page_id === $home->id ? '  (from your current home page)' : '  (copied from another page)') : '  (new)'));
        if (! $acc) $this->warn('No accordion was found to copy, so the "Your whole day" section has no images yet. Add them in the builder.');
        $this->line('Open it under Marketing pages. Nothing live has changed.');
        return self::SUCCESS;
    }
}
