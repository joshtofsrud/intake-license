<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use App\Models\Tenant\TenantNavItem;
use App\Models\Tenant\TenantServiceCategory; // MARKER-PATCH-267
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PageBuilderController extends Controller
{
    /**
     * Section content fields that are expected to be arrays. When the editor
     * sends these as JSON strings (from hidden inputs alongside JSON-textarea
     * UIs), we decode them before storing so renderers get real arrays back.
     */
    private const ARRAY_FIELDS = [
        'features', 'steps', 'plans', 'items', 'testimonials',
        'shop_names', 'logos', 'competitors', 'rows', 'stats', 'images',
        // MARKER-PATCH-158-G19 — Hero buttons list (Phase 2 field)
        'buttons',
        // MARKER-PATCH-158-G22 — services category filter (multi-select)
        'category_ids',
        // MARKER-PATCH-158-G26 — footer link columns + social links
        'link_columns', 'social_links',
    ];

    private const DEFAULTS = [
        // MARKER-PATCH-158-G25 — nav v2 fields (Phase 2)
        'nav'           => [
            // Logo
            'show_logo'       => true,
            'logo_variant'    => 'auto',     // auto (contrast) | light | dark — MARKER-PATCH-274
            'logo_alignment'  => 'left',     // left | center
            'logo_size'       => 'medium',   // small | medium | large | xl
            // CTA
            'show_cta'        => true,
            'cta_label'       => 'Book Now',
            'cta_url'         => '/book',
            'cta_style'       => 'primary',  // primary | outline | ghost
            // Layout
            'layout'          => 'standard', // standard | centered | split
            'sticky'          => true,
            'height'          => 'normal',   // compact | normal | spacious
            // Background
            'bg_mode'         => 'solid',    // solid | transparent | blur
            'bg_color'        => '#ffffff',
            'border_bottom'   => 'hairline', // none | hairline | shadow
            // Colors
            'text_color'      => '',
            'link_color'      => '',
            'active_link_style' => 'underline', // none | underline | dot | pill
            // Advanced
            'anchor_id'       => '',
            'custom_classes'  => '',
            'hide_on_mobile'  => false,
            'hide_on_desktop' => false,

            // Legacy compat
            'bg_style'        => 'solid',
        ],
        // MARKER-PATCH-158-G19 — Phase 2 Hero fields (v2)
        // Legacy fields (eyebrow/headline/accent_words/subheading/bg_color/text_color/
        // text_align/cta_primary_*/cta_secondary_*/note/height) are preserved for
        // backward compat. New fields below extend the editor: background image
        // with overlay, multi-line headlines, vertical alignment, padding, max-width,
        // button list (replaces cta_primary/secondary when set), advanced fields.
        'hero'          => [
            'eyebrow'             => '',
            'headline'            => 'Your headline here',
            'accent_words'        => '',
            'subheading'          => 'A short description.',
            'note'                => '',
            // Buttons: array of {label, url, style: primary|outline|ghost|link}.
            // If empty, renderer falls back to cta_primary/secondary for legacy.
            'buttons'             => [],
            // Legacy CTAs (kept for v1 content; new edits use buttons[])
            'cta_primary_label'   => 'Book Now',
            'cta_primary_url'     => '/book',
            // MARKER-PATCH-249 — Design series: motion + blur (image mode).
            'bg_parallax'         => '0',
            'bg_parallax_depth'   => 35,
            'bg_blur'             => 0,
            'cta_secondary_label' => '',
            'cta_secondary_url'   => '',
            // Layout
            'height'              => 'large',
            'text_align'          => 'left',
            'vertical_align'      => 'center',
            'content_max_width'   => 680,
            'padding_top'         => 'normal',
            'padding_bottom'      => 'normal',
            // MARKER-PATCH-158-G21 — typography
            'headline_size'       => 'auto',
            'subheading_size'     => 'medium',
            // Style
            'bg_mode'             => 'color', // color | image | gradient
            'bg_color'            => '#1a1a1a',
            'bg_gradient_from'    => '#1a1a1a',
            'bg_gradient_to'      => '#0a0a0a',
            'bg_gradient_angle'   => 135,
            'bg_image_url'        => '',
            'bg_image_position'   => 'center',
            'bg_image_size'       => 'cover',
            'bg_overlay_opacity'  => 45,
            'bg_overlay_color'    => '#000000',
            'text_color'          => '#ffffff',
            'text_color_body'     => '',
            'accent_color'        => '',
            // Advanced
            'anchor_id'           => '',
            'custom_classes'      => '',
            'hide_on_mobile'      => false,
            'hide_on_desktop'     => false,
        ],
        // MARKER-PATCH-158-G22 — services v2 fields (Phase 2)
        'services'      => [
            // Content
            'eyebrow'         => '',
            'heading'         => 'Our services',
            'accent_words'    => '',
            'subheading'      => '',
            'empty_state_text'=> 'No services available yet.',
            // Category filter
            'category_ids'    => [],     // [] = show all categories; populated array = show only these
            'max_per_category'=> 0,      // 0 = no limit
            // Layout
            'columns'         => 3,
            'card_style'      => 'card', // card | list | minimal
            'show_category_headers' => true,
            'show_prices'     => true,
            'show_descriptions' => true,
            'show_addons'     => false,
            'text_align'      => 'left',
            'padding_top'     => 'normal',
            'padding_bottom'  => 'normal',
            // Style
            'bg_mode'         => 'none', // none | color | gradient
            'bg_color'        => '#ffffff',
            'bg_gradient_from'=> '#ffffff',
            'bg_gradient_to'  => '#fafafa',
            'text_color'      => '',
            'text_color_body' => '',
            'accent_color'    => '',
            'card_bg'         => '',
            'card_border'     => '',
            'card_hover_effect' => 'lift', // none | lift | accent-border
            // Advanced
            'anchor_id'       => '',
            'custom_classes'  => '',
            'hide_on_mobile'  => false,
            'hide_on_desktop' => false,
        ],
        // MARKER-PATCH-158-G20 — text_image v2 fields (Phase 2)
        'text_image'    => [
            'eyebrow'         => '',
            'heading'         => 'About us',
            'accent_words'    => '',
            'body'            => 'Tell visitors who you are and why they should choose you. Replace this with your story.',
            'image_url'       => '',
            'image_alt'       => '',
            'image_position'  => 'right',
            'image_ratio'     => 'equal',
            'image_aspect'    => '4/3',
            'image_radius'    => 'medium',
            'buttons'         => [],
            // Legacy
            'cta_label'       => '',
            'cta_url'         => '',
            // Layout
            'text_align'      => 'left',
            'padding_top'     => 'normal',
            'padding_bottom'  => 'normal',
            // Style
            'bg_mode'         => 'none',
            'bg_color'        => '',
            'bg_gradient_from'=> '',
            'bg_gradient_to'  => '',
            'text_color'      => '',
            'text_color_body' => '',
            'accent_color'    => '',
            // Advanced
            'anchor_id'       => '',
            'custom_classes'  => '',
            'hide_on_mobile'  => false,
            'hide_on_desktop' => false,
        ],

        // MARKER-PATCH-158-G20 — cta_banner v2 fields (Phase 2)
        'cta_banner'    => [
            'eyebrow'         => '',
            'headline'        => 'Ready to book?',
            'accent_words'    => '',
            'subheading'      => '',
            'note'            => '',
            'buttons'         => [],
            // Legacy
            'cta_label'       => 'Book Now',
            'cta_url'         => '/book',
            // MARKER-PATCH-250 — Design series: motion + blur (image mode).
            'bg_parallax'     => '0',
            'bg_parallax_depth' => 35,
            'bg_blur'         => 0,
            // Layout
            'text_align'      => 'center',
            'content_max_width' => 640,
            'padding_top'     => 'normal',
            'padding_bottom'  => 'normal',
            // Style
            'bg_mode'         => 'color',
            'bg_color'        => '#0a0a0a',
            'bg_gradient_from'=> '#0a0a0a',
            'bg_gradient_to'  => '#1a1a1a',
            'bg_image_url'    => '',
            'bg_overlay_opacity' => 50,
            'bg_overlay_color' => '#000000',
            'text_color'      => '#ffffff',
            'text_color_body' => '',
            'accent_color'    => '',
            // Advanced
            'anchor_id'       => '',
            'custom_classes'  => '',
            'hide_on_mobile'  => false,
            'hide_on_desktop' => false,
        ],
        'image_gallery' => ['images'=>[],'columns'=>3],
        // MARKER-PATCH-158-G24 — contact_form v2 fields (Phase 2)
        'contact_form'  => [
            // Content
            'eyebrow'         => '',
            'heading'         => 'Get in touch',
            'accent_words'    => '',
            'subheading'      => '',
            'note'            => '',
            'submit_label'    => 'Send message',
            'success_text'    => "Thanks! We'll be in touch soon.",
            'privacy_text'    => '',
            // Fields
            'show_phone'      => true,
            'show_message'    => true,
            'label_name'      => 'Name',
            'label_email'     => 'Email',
            'label_phone'     => 'Phone',
            'label_message'   => 'Message',
            'placeholder_message' => 'How can we help you?',
            'message_rows'    => 5,
            // Layout
            'form_width'      => 'medium',
            'text_align'      => 'center',
            'padding_top'     => 'normal',
            'padding_bottom'  => 'normal',
            // Style
            'bg_mode'         => 'none',
            'bg_color'        => '#ffffff',
            'bg_gradient_from'=> '#ffffff',
            'bg_gradient_to'  => '#fafafa',
            'input_style'     => 'default',
            'input_radius'    => 'medium',
            'text_color'      => '',
            'text_color_body' => '',
            'accent_color'    => '',
            // Advanced
            'anchor_id'       => '',
            'custom_classes'  => '',
            'hide_on_mobile'  => false,
            'hide_on_desktop' => false,
        ],
        'booking_embed'  => ['heading'=>'Book online'],
        // MARKER-PATCH-239 — live fleet showcase with rates + browse CTA.
        'rentals_showcase' => ['eyebrow'=>'','heading'=>'Rent the good stuff','body'=>'','category_id'=>'','max_models'=>6,'show_rates'=>'1','show_deposit'=>'0','cta_label'=>'Check availability','cta_url'=>'/rentals','bg_color'=>''],
        // MARKER-PATCH-576 — online store product showcase
        'products_showcase' => ['eyebrow'=>'','heading'=>'From the shop','body'=>'','category_id'=>'','max_items'=>8,'in_stock_only'=>'0','show_prices'=>'1','show_search'=>'0','search_placeholder'=>'','cta_label'=>'Browse the shop','cta_url'=>'/shop','bg_color'=>''],
        'classes_embed'  => ['heading'=>'Upcoming classes','show_filters'=>true,'weeks_ahead'=>2],
        'roadmap_grid'  => ['intro_text'=>'An honest look at where Intake is heading. Plans change as we learn from shops using the product.'],
        'changelog_list'=> ['intro_text'=>'Everything we shipped lately, reverse-chronological.'],
        // MARKER-PATCH-158-G26 — footer v2 fields (Phase 2)
        'footer'        => [
            // Brand
            'show_logo'       => true,
            'logo_size'       => 'medium',  // small | medium | large | xl
            'tagline_override'=> '',
            // Repeatable lists
            'link_columns'    => [],
            'social_links'    => [],
            // Contact info toggles
            'show_phone'      => false,
            'show_email'      => true,
            'show_address'    => false,
            'show_hours'      => false,
            // MARKER-PATCH-158-G29 — inline contact form
            'show_form'       => false,
            'form_heading'    => 'Get in touch',
            'form_description'=> '',
            'form_button_label' => 'Send',
            'form_success_text' => "Thanks! We'll be in touch soon.",
            // MARKER-PATCH-394 — contact-form phone field control
            'form_show_phone'   => true,
            'form_require_phone'=> false,
            // Copyright
            'copyright_text'  => '',
            'show_powered_by' => true,
            // Layout
            'layout'          => 'columns',      // columns | centered | minimal
            'text_align'      => 'left',
            'padding_top'     => 'normal',
            'padding_bottom'  => 'normal',
            // Style
            'bg_mode'         => 'color',
            'bg_color'        => '#0a0a0a',
            'bg_gradient_from'=> '#0a0a0a',
            'bg_gradient_to'  => '#1a1a1a',
            'border_top'      => 'none',
            'text_color'      => '',
            'link_color'      => '',
            'muted_color'     => '',
            // Advanced
            'anchor_id'       => '',
            'custom_classes'  => '',
            'hide_on_mobile'  => false,
            'hide_on_desktop' => false,

            // Legacy compat
            'show_copyright'  => true,
        ],

        // MARKER-PATCH-158-G30 — pricing_table v2 fields (Phase 2)
        'pricing_table' => [
            // Content
            'eyebrow'          => '',
            'heading'          => 'Simple, honest pricing',
            'accent_words'     => '',
            'subheading'       => '',
            'footnote'         => '',
            'plans'            => [
                ['eyebrow'=>'01 · BASIC','title'=>'Standard','price'=>'$90','price_suffix'=>'& up','badge_label'=>'','featured'=>false,'features'=>['Feature one','Feature two','Feature three'],'cta_label'=>'','cta_url'=>''],
                ['eyebrow'=>'02 · POPULAR','title'=>'Advanced','price'=>'$140','price_suffix'=>'& up','badge_label'=>'MOST BOOKED','featured'=>true,'features'=>['Everything in Standard','Feature four','Feature five'],'cta_label'=>'','cta_url'=>''],
                ['eyebrow'=>'03 · PREMIUM','title'=>'Premium','price'=>'$295','price_suffix'=>'& up','badge_label'=>'','featured'=>false,'features'=>['Everything in Advanced','Feature six','Feature seven'],'cta_label'=>'','cta_url'=>''],
            ],
            // Layout
            'columns'          => 'auto',     // auto | 2 | 3 | 4
            'featured_style'   => 'border',   // border | elevated | scale
            'feature_divider'  => 'dashed',   // none | solid | dashed
            'text_align'       => 'center',
            'padding_top'      => 'normal',
            'padding_bottom'   => 'normal',
            // Style
            'bg_mode'          => 'none',
            'bg_color'         => '#0a0f1a',
            'bg_gradient_from' => '#0a0f1a',
            'bg_gradient_to'   => '#0f1828',
            'card_bg'          => '',
            'card_border'      => '',
            'text_color'       => '',
            'text_color_body'  => '',
            'accent_color'     => '',
            // Advanced
            'anchor_id'        => '',
            'custom_classes'   => '',
            'hide_on_mobile'   => false,
            'hide_on_desktop'  => false,
        ],
        // MARKER-PATCH-158-G31 — feature_grid v2 fields (Phase 2)
        'feature_grid' => [
            // Content
            'eyebrow'          => '',
            'heading'          => 'Why choose us',
            'accent_words'     => '',
            'subheading'       => '',
            'features'         => [
                ['icon'=>'','title'=>'Lower service','price'=>'$90 & up','body'=>'Legs/sleeve off, cleaned, new wipers & oil.','cta_label'=>'','cta_url'=>''],
                ['icon'=>'','title'=>'Full rebuild','price'=>'$180 & up','body'=>'Teardown, damper rebuild, air spring service.','cta_label'=>'','cta_url'=>''],
                ['icon'=>'','title'=>'Dropper post','price'=>'$120 & up','body'=>'Teardown, all new o-rings, seals, & oil.','cta_label'=>'','cta_url'=>''],
            ],
            // Layout
            'layout'           => 'grid',     // grid | intro_split
            'columns'          => 3,
            'card_style'       => 'card',     // card | minimal
            'show_icons'       => true,
            'text_align'       => 'center',
            'padding_top'      => 'normal',
            'padding_bottom'   => 'normal',
            // Style
            'bg_mode'          => 'none',
            'bg_color'         => '#0a0f1a',
            'bg_gradient_from' => '#0a0f1a',
            'bg_gradient_to'   => '#0f1828',
            'card_bg'          => '',
            'card_border'      => '',
            'text_color'       => '',
            'text_color_body'  => '',
            'accent_color'     => '',
            // Advanced
            'anchor_id'        => '',
            'custom_classes'   => '',
            'hide_on_mobile'   => false,
            'hide_on_desktop'  => false,
        ],
        // MARKER-PATCH-158-G34 — step_timeline v2 fields (Phase 2)
        'step_timeline' => [
            // Content
            'eyebrow'          => '',
            'heading'          => 'How it works',
            'accent_words'     => '',
            'subheading'       => '',
            'steps' => [
                ['title' => 'Drop off', 'desc' => 'Bring your bike in or schedule a pickup.',  'icon' => ''],
                ['title' => 'Diagnose', 'desc' => 'We assess what your bike needs and quote.', 'icon' => ''],
                ['title' => 'Service',  'desc' => 'Repairs and tuning done by our techs.',     'icon' => ''],
                ['title' => 'Ride',     'desc' => 'Pick up and roll out. Better than new.',    'icon' => ''],
            ],
            // Layout
            'layout'           => 'horizontal',  // horizontal | vertical | cards
            'connector'        => 'line',        // line | dots | arrow | none
            'show_numbers'     => true,
            'number_style'     => 'circle',      // circle | square | underline
            'text_align'       => 'center',
            'padding_top'      => 'normal',
            'padding_bottom'   => 'normal',
            // Style
            'bg_mode'          => 'none',
            'bg_color'         => '#ffffff',
            'bg_gradient_from' => '#ffffff',
            'bg_gradient_to'   => '#fafafa',
            'text_color'       => '',
            'text_color_body'  => '',
            'accent_color'     => '',
            // Advanced
            'anchor_id'        => '',
            'custom_classes'   => '',
            'hide_on_mobile'   => false,
            'hide_on_desktop'  => false,
        ],
        'testimonial_carousel' => [
            'eyebrow' => '', 'heading' => 'What customers say', 'subheading' => '',
            'testimonials' => [['quote' => 'This changed how we run the shop.', 'author' => 'Name', 'role' => 'Owner']],
        ],
        // MARKER-PATCH-158-G32 — logo_bar v2 fields (Phase 2)
        'logo_bar'         => [
            // Content
            'eyebrow'          => '',
            'heading'          => 'Trusted by',
            'accent_words'     => '',
            'subheading'       => '',
            'logos'            => [],
            // Layout
            'layout'           => 'grid',          // grid | marquee
            'cols'             => 'auto',          // auto | 3 | 4 | 5 | 6
            'logo_size'        => 'medium',        // small | medium | large
            'marquee_speed'    => 'normal',        // slow | normal | fast
            'text_align'       => 'center',
            'padding_top'      => 'compact',
            'padding_bottom'   => 'compact',
            // Style
            'bg_mode'          => 'none',
            'bg_color'         => '#ffffff',
            'bg_gradient_from' => '#ffffff',
            'bg_gradient_to'   => '#fafafa',
            'logo_treatment'   => 'grayscale_hover', // color | grayscale | grayscale_hover | muted
            'text_color'       => '',
            'text_color_body'  => '',
            'accent_color'     => '',
            // Advanced
            'anchor_id'        => '',
            'custom_classes'   => '',
            'hide_on_mobile'   => false,
            'hide_on_desktop'  => false,

            // Legacy compat (v1 parallel array)
            'shop_names'       => [],
        ],
        // MARKER-PATCH-158-G33 — faq_accordion v2 fields (Phase 2)
        'faq_accordion'    => [
            // Content
            'eyebrow'          => '',
            'heading'          => 'Frequently asked',
            'accent_words'     => '',
            'subheading'       => '',
            'items'            => [
                ['question'=>'A common question?','answer'=>'A clear answer.','open_default'=>false],
            ],
            // Layout
            'open_mode'        => 'multiple',     // multiple | single
            'style'            => 'divider',      // bordered | divider | minimal
            'width'            => 'medium',
            'icon_style'       => 'chevron',      // chevron | plus | arrow | none
            'text_align'       => 'center',
            'padding_top'      => 'normal',
            'padding_bottom'   => 'normal',
            // Style
            'bg_mode'          => 'none',
            'bg_color'         => '#ffffff',
            'bg_gradient_from' => '#ffffff',
            'bg_gradient_to'   => '#fafafa',
            'text_color'       => '',
            'text_color_body'  => '',
            'accent_color'     => '',
            // Advanced
            'anchor_id'        => '',
            'custom_classes'   => '',
            'hide_on_mobile'   => false,
            'hide_on_desktop'  => false,
        ],
        'comparison_table' => ['eyebrow'=>'','heading'=>'How we compare','subheading'=>'','competitors'=>['Intake','Other'],'rows'=>[['feature'=>'Feature','values'=>['yes','no']]]],
        'industry_pack_showcase' => ['eyebrow'=>'','heading'=>'Built for your industry','subheading'=>'Pick your industry, get pre-configured services, pricing, and content.','limit'=>12,'show_all_link'=>true],
        // MARKER-PATCH-158-G27 — stats_row v2 fields (Phase 2)
        'stats_row'        => [
            // Content
            'eyebrow'         => '',
            'heading'         => 'By the numbers',
            'accent_words'    => '',
            'subheading'      => '',
            'stats'           => [
                ['number'=>'200+','label'=>'Businesses','description'=>''],
                ['number'=>'50k+','label'=>'Appointments','description'=>''],
                ['number'=>'24','label'=>'Industries','description'=>''],
            ],
            // Layout
            'columns'         => 'auto',
            'number_size'     => 'large',
            'stats_align'     => 'center',
            'text_align'      => 'center',
            'divider'         => 'none',
            'padding_top'     => 'normal',
            'padding_bottom'  => 'normal',
            // Style
            'bg_mode'         => 'none',
            'bg_color'        => '#ffffff',
            'bg_gradient_from'=> '#ffffff',
            'bg_gradient_to'  => '#fafafa',
            'text_color'      => '',
            'text_color_body' => '',
            'accent_color'    => '',
            // Advanced
            'anchor_id'       => '',
            'custom_classes'  => '',
            'hide_on_mobile'  => false,
            'hide_on_desktop' => false,
        ],
        'screen_showcase'  => ['eyebrow'=>'','step_num'=>1,'heading'=>'Step heading','body'=>'Short body for this step.','points'=>[],'desktop_label'=>'Desktop','desktop_lines'=>[],'mobile_label'=>'Mobile','mobile_lines'=>[],'mobile_note'=>'','flip'=>false],
        'legal_doc'        => ['doc_title'=>'Document title','effective_date'=>'','updated_date'=>'','intro_paragraph'=>'','show_toc'=>true,'sections'=>[['heading'=>'Section heading','blocks'=>[['type'=>'paragraph','text'=>'']]]]],
        // MARKER-PATCH-306 — raw HTML block, rendered as-is on the public page.
        'custom_html'      => ['html'=>'', 'bg_color'=>'', 'padding_y'=>'normal'],
        // MARKER-PATCH-306 — raw HTML block, rendered as-is on the public page.
        'custom_html'      => ['html'=>'', 'bg_color'=>'', 'padding_y'=>'normal'],
    ];

    public function index(Request $request)
    {
        $tenant = tenant();

        if ($request->has('edit')) {
            return $this->editPage($tenant, $request->input('edit'));
        }

        $pages = TenantPage::where('tenant_id', $tenant->id)
            ->orderByDesc('is_home')->orderBy('nav_order')->get();

        if ($pages->isEmpty()) {
            $home = TenantPage::create([
                'tenant_id' => $tenant->id, 'title' => 'Home', 'slug' => 'home',
                'is_home' => true, 'is_published' => false, 'is_in_nav' => false, 'nav_order' => 0,
            ]);
            $this->seedDefaultSections($home);
            $pages = TenantPage::where('tenant_id', $tenant->id)->get();
        }

        return view('tenant.pages.index', compact('pages'));
    }

    private function editPage($tenant, string $id)
    {
        $page = TenantPage::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $sections = TenantPageSection::where('page_id', $page->id)->orderBy('sort_order')->get();
        $navItems = TenantNavItem::where('tenant_id', $tenant->id)->orderBy('sort_order')->get();
        $sectionTypes = array_keys(self::DEFAULTS);

        // MARKER-PATCH-275 — published pages for the nav editor's
        // "Add from existing pages" picker on the initial server render.
        $availablePages = TenantPage::where('tenant_id', $tenant->id)
            ->where('is_published', true)
            ->orderBy('nav_order')
            ->get(['id', 'title', 'slug', 'is_home']);

        $brandKit = $this->brandKitFor($tenant);

        // MARKER-PATCH-603 — the Booking page (slug "book") gets a marketing-scoped
        // gallery; its booking_embed pivot is protected in the delete handler.
        $isBookingExtras = ($page->slug === 'book');

        return view('tenant.pages.edit', compact('page', 'sections', 'navItems', 'sectionTypes', 'availablePages', 'brandKit', 'isBookingExtras'));
    }

    /**
     * MARKER-PATCH-267 — Render the page being edited inside the builder's
     * preview iframe. Authenticated + same-origin (lives in the admin group),
     * and it does NOT filter is_published, so DRAFT pages preview correctly.
     * Mirrors PublicController::renderPage()'s data so public.page renders
     * exactly as the live site will.
     */
    public function preview(Request $request, string $id)
    {
        $tenant = tenant();

        $page = TenantPage::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $sections = $page->sections()->where('is_visible', true)->get();
        $sections = TenantPageSection::withInheritedChrome($sections, $page->tenant_id, $page->id);

        $navItems = TenantNavItem::where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get();

        $catalog = TenantServiceCategory::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['items' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order')
                  ->with(['serviceAddons' => function ($sa) { $sa->orderBy('sort_order')->with('addon'); }]);
            }])
            ->get();

        return view('public.page', compact('page', 'sections', 'navItems', 'catalog'));
    }

    public function store(Request $request)
    {
        $tenant = tenant();

        // MARKER-NAVFIX — nav-link save lands here (op=update_nav, no `update`
        // field), so handle it before the new-page validator below 422s on the
        // missing title. Empty-list guard prevents a bad save wiping the nav.
        if ($request->input('op') === 'update_nav') {
            $items = collect($request->input('nav_items', []))
                ->filter(fn ($it) => !empty($it['label']))
                ->values();

            if ($items->isEmpty()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Refusing to save an empty navigation. Add at least one link.',
                ], 422);
            }

            \App\Models\Tenant\TenantNavItem::where('tenant_id', $tenant->id)->delete();
            foreach ($items as $i => $item) {
                \App\Models\Tenant\TenantNavItem::create([
                    'tenant_id'       => $tenant->id,
                    'label'           => $item['label'],
                    'url'             => $item['url'] ?? '/',
                    'is_external'     => filter_var($item['is_external']    ?? false, FILTER_VALIDATE_BOOLEAN),
                    'open_in_new_tab' => filter_var($item['open_in_new_tab'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'sort_order'      => $i,
                ]);
            }

            return response()->json(['ok' => true, 'count' => $items->count()]);
        }

        if ($request->has('section_op')) {
            return $this->handleSectionOp($tenant, $request);
        }

        if ($request->has('update')) {
            return $this->handlePageUpdate($tenant, $request->input('update'), $request);
        }

        if ($request->has('delete')) {
            return $this->handlePageDelete($tenant, $request->input('delete'));
        }

        $request->validate(['title' => ['required', 'string', 'max:191']]);

        $slug = Str::slug($request->input('title'));
        $i = 1;
        $base = $slug;
        while (TenantPage::where('tenant_id', $tenant->id)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        $page = TenantPage::create([
            'tenant_id' => $tenant->id,
            'title' => $request->input('title'),
            'slug' => $slug,
            'is_home' => false,
            'is_published' => false,
            'is_in_nav' => true,
            'nav_order' => TenantPage::where('tenant_id', $tenant->id)->max('nav_order') + 1,
        ]);

        // MARKER-PATCH-268 — open new pages with an editable starter layout.
        $this->seedStarterSections($page);

        return redirect()->route('tenant.pages.index', ['edit' => $page->id])
            ->with('success', 'Page created with a starter layout — edit each section, then publish.');
    }

    // MARKER-PATCH-158-G15 — edit() renders the v2 chrome directly.
    // Also serves the inspector partial when called with ?_inspector={section_id} —
    // the v2 right pane fetches just the rendered _section.blade.php for the
    // selected section via AJAX so we don't reload the whole page on selection.
    //
    // MARKER-PATCH-158-G19 — Phase 2: per-type editor partials at
    //   resources/views/tenant/pages/sections/_{type}.blade.php
    // take precedence when they exist. The legacy _section.blade.php is the
    // fallback for types we haven't migrated yet. This lets us rebuild one
    // section type at a time without breaking the others.
    public function edit(Request $request, string $id)
    {
        $tenant = tenant();

        if ($request->has('_inspector')) {
            $page = TenantPage::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
            $section = TenantPageSection::where('page_id', $page->id)
                ->where('id', $request->input('_inspector'))
                ->firstOrFail();

            $perType = 'tenant.pages.sections._' . $section->section_type;
            if (view()->exists($perType)) {
                // MARKER-PATCH-158-G22 — extra context per section type. The
                // services editor needs a list of available service categories
                // to populate its category filter. Other types pass through
                // with no extras (keeps the contract minimal).
                $extras = [];
                // MARKER-PATCH-602 — booking-extras context enables the slot picker.
                $extras['isBookingExtras'] = ($page->slug === 'book'); // MARKER-PATCH-603
                // MARKER-PATCH-239 — rentals showcase editor needs the
                // rental categories for its filter select.
                // MARKER-PATCH-576 — products showcase editor needs the
                // inventory categories that actually contain published items.
                if ($section->section_type === 'products_showcase') {
                    $extras['productCategories'] = \App\Models\Tenant\TenantInventoryCategory::where('tenant_id', $tenant->id)
                        ->whereIn('id', \App\Models\Tenant\TenantInventoryItem::where('tenant_id', $tenant->id)
                            ->where('is_active', true)->where('show_online', true)
                            ->select('category_id'))
                        ->orderBy('name')
                        ->get(['id', 'name']);
                }
                if ($section->section_type === 'rentals_showcase') {
                    $extras['rentalCategories'] = \App\Models\Tenant\TenantRentalCategory::where('tenant_id', $tenant->id)
                        ->whereNull('archived_at')
                        ->orderBy('sort_order')->orderBy('name')
                        ->get(['id', 'name']);
                }
                if ($section->section_type === 'services') {
                    $extras['categories'] = \App\Models\Tenant\TenantServiceCategory::where('tenant_id', $tenant->id)
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->get(['id', 'name', 'slug']);
                }
                // MARKER-PATCH-293 — feature_grid cards can link to a service.
                if ($section->section_type === 'feature_grid') {
                    $extras['services'] = \App\Models\Tenant\TenantServiceItem::where('tenant_id', $tenant->id)
                        ->where('is_active', true)
                        ->orderBy('sort_order')->orderBy('name')
                        ->get(['id', 'name', 'description', 'price_cents']);
                }
                // MARKER-PATCH-158-G25 — nav editor needs the current tenant
                // nav items (global, not per-page) so the link list editor
                // can render. Saved via the existing update_nav op.
                if ($section->section_type === 'nav') {
                    $extras['navItems'] = \App\Models\Tenant\TenantNavItem::where('tenant_id', $tenant->id)
                        ->orderBy('sort_order')
                        ->get(['id', 'label', 'url', 'is_external', 'open_in_new_tab', 'sort_order']);
                    // List of available pages so the user can pick from existing
                    // page URLs instead of typing them by hand.
                    $extras['availablePages'] = TenantPage::where('tenant_id', $tenant->id)
                        ->where('is_published', true)
                        ->orderBy('nav_order')
                        ->get(['id', 'title', 'slug', 'is_home']);
                }
                return view($perType, array_merge(
                    ['section' => $section, 'c' => $section->content ?? []],
                    $extras
                ))->render();
            }
            return view('tenant.pages._section', ['section' => $section])->render();
        }

        return $this->editPage($tenant, $id);
    }

    public function update(Request $request, string $id)      { return $this->handlePageUpdate(tenant(), $id, $request); }
    public function destroy(Request $request, string $id)     { return $this->handlePageDelete(tenant(), $id); }
    public function addSection(Request $request, string $id)  { $request->merge(['section_op' => 'add', 'page_id' => $id]); return $this->handleSectionOp(tenant(), $request); }
    public function updateSection(Request $request, string $id, string $sid) { $request->merge(['section_op' => 'update', 'page_id' => $id, 'section_id' => $sid]); return $this->handleSectionOp(tenant(), $request); }
    public function deleteSection(Request $request, string $id, string $sid) { $request->merge(['section_op' => 'delete', 'page_id' => $id, 'section_id' => $sid]); return $this->handleSectionOp(tenant(), $request); }
    public function reorderSections(Request $request, string $id)            { $request->merge(['section_op' => 'reorder', 'page_id' => $id]); return $this->handleSectionOp(tenant(), $request); }

    private function handlePageUpdate($tenant, string $id, Request $request)
    {
        $page = TenantPage::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $op = $request->input('op', 'update_page');

        if ($op === 'update_page') {
            $page->update([
                'title'            => $request->input('title', $page->title),
                'meta_title'       => $request->input('meta_title'),
                'meta_description' => $request->input('meta_description'),
                'is_published'     => (bool) $request->input('is_published', 0),
                'is_in_nav'        => (bool) $request->input('is_in_nav', 1),
            ]);

            if ($request->expectsJson()) return response()->json(['ok' => true]);
            return back()->with('success', 'Page settings saved.');
        }

        if ($op === 'update_nav') {
            // MARKER-PATCH-158-G25 — extended to save is_external + open_in_new_tab
            // (model already supports these; v1 update_nav was just dropping them).
            TenantNavItem::where('tenant_id', $tenant->id)->delete();
            foreach ($request->input('nav_items', []) as $i => $item) {
                if (empty($item['label'])) continue;
                TenantNavItem::create([
                    'tenant_id'       => $tenant->id,
                    'label'           => $item['label'],
                    'url'             => $item['url'] ?? '/',
                    'is_external'     => filter_var($item['is_external']    ?? false, FILTER_VALIDATE_BOOLEAN),
                    'open_in_new_tab' => filter_var($item['open_in_new_tab'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'sort_order'      => $i,
                ]);
            }

            if ($request->expectsJson()) return response()->json(['ok' => true]);
            return back()->with('success', 'Navigation saved.');
        }

        return back();
    }

    private function handlePageDelete($tenant, string $id)
    {
        $page = TenantPage::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        if ($page->is_home) return back()->with('error', 'Cannot delete the home page.');
        if ($page->slug === 'book') return back()->with('error', 'The Booking page cannot be deleted.'); // MARKER-PATCH-603
        $page->delete();
        return redirect()->route('tenant.pages.index')->with('success', 'Page deleted.');
    }

    private function handleSectionOp($tenant, Request $request)
    {
        $op = $request->input('section_op');
        $pageId = $request->input('page_id');
        $page = TenantPage::where('tenant_id', $tenant->id)->where('id', $pageId)->firstOrFail();

        if ($op === 'add') {
            $type = $request->input('type', 'hero');
            if (!array_key_exists($type, self::DEFAULTS)) {
                return response()->json(['error' => 'Unknown section type.'], 422);
            }
            $section = TenantPageSection::create([
                'page_id' => $page->id, 'tenant_id' => $tenant->id,
                'section_type' => $type, 'content' => self::DEFAULTS[$type],
                'padding' => 'normal', 'is_visible' => true,
                'sort_order' => TenantPageSection::where('page_id', $page->id)->max('sort_order') + 1,
            ]);
            return response()->json(['success' => true, 'id' => $section->id, 'type' => $type]);
        }

        if ($op === 'update') {
            $sid = $request->input('section_id');
            $section = TenantPageSection::where('page_id', $page->id)->where('id', $sid)->firstOrFail();
            $content = $request->input('content', []);
            if (!is_array($content)) $content = [];

            // Decode JSON strings for known array fields. The editor uses JSON
            // textareas for array-of-objects fields (features, steps, plans,
            // etc.) and posts them as hidden inputs with stringified JSON.
            foreach (self::ARRAY_FIELDS as $f) {
                if (isset($content[$f]) && is_string($content[$f])) {
                    $decoded = json_decode($content[$f], true);
                    $content[$f] = is_array($decoded) ? $decoded : [];
                }
            }

            $section->update([
                'content'   => array_merge($section->content ?? [], $content),
                'bg_color'  => $request->input('bg_color'),
                'padding'   => $request->input('padding', 'normal'),
                'is_visible'=> (bool) $request->input('is_visible', 1),
            ]);
            return response()->json(['success' => true]);
        }

        if ($op === 'delete') {
            $sid = $request->input('section_id');
            // MARKER-PATCH-603 — the booking_embed pivot on the Booking page anchors
            // the before/after split for /book; it cannot be deleted.
            if ($page->slug === 'book') {
                $target = TenantPageSection::where('page_id', $page->id)->where('id', $sid)->first();
                if ($target && $target->section_type === 'booking_embed') {
                    return response()->json(['success' => false, 'error' => 'The booking form section cannot be removed from the Booking page.'], 422);
                }
            }
            TenantPageSection::where('page_id', $page->id)->where('id', $sid)->delete();
            return response()->json(['success' => true]);
        }

        // MARKER-PATCH-158-G18 — section duplicate. Clones the source section's
        // content + meta and inserts it right after the source in sort order.
        // Subsequent sections get bumped down by 1 so the new clone slots in.
        if ($op === 'duplicate') {
            $sid = $request->input('section_id');
            $source = TenantPageSection::where('page_id', $page->id)->where('id', $sid)->firstOrFail();
            $insertAt = $source->sort_order + 1;

            // Bump everything at or after the insert slot down by 1.
            TenantPageSection::where('page_id', $page->id)
                ->where('sort_order', '>=', $insertAt)
                ->increment('sort_order');

            $clone = TenantPageSection::create([
                'page_id'      => $page->id,
                'tenant_id'    => $tenant->id,
                'section_type' => $source->section_type,
                'content'      => $source->content ?? [],
                'bg_color'     => $source->bg_color,
                'padding'      => $source->padding ?? 'normal',
                'is_visible'   => $source->is_visible,
                'sort_order'   => $insertAt,
            ]);
            return response()->json(['success' => true, 'id' => $clone->id, 'type' => $clone->section_type]);
        }

        if ($op === 'reorder') {
            $order = $request->input('order', []);
            foreach ($order as $i => $sectionId) {
                TenantPageSection::where('page_id', $page->id)->where('id', $sectionId)->update(['sort_order' => $i]);
            }
            return response()->json(['success' => true]);
        }

        return response()->json(['error' => 'Unknown section operation.'], 422);
    }

    /**
     * MARKER-PATCH-268 — Seed a universal starter layout for a brand-new page
     * so it opens editable instead of blank. Content comes from DEFAULTS, so
     * every seeded section already shows example copy the tenant can replace.
     */
    private function seedStarterSections(TenantPage $page): void
    {
        $types = ['hero', 'text_image', 'cta_banner']; // PB1: chrome inherited, not seeded
        foreach ($types as $i => $type) {
            TenantPageSection::create([
                'page_id' => $page->id, 'tenant_id' => $page->tenant_id,
                'section_type' => $type, 'content' => self::DEFAULTS[$type] ?? [],
                'padding' => 'normal', 'is_visible' => true, 'sort_order' => $i,
            ]);
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // MARKER-PATCH-302 — Brand Kit (storage-only reference palette).
    // Saved per-tenant in settings['brand_kit']; surfaced as a floating card in
    // the builder to copy from. Does not theme sections. Seeded from the
    // tenant's existing brand colors when nothing has been saved yet.
    // ════════════════════════════════════════════════════════════════════
    public const BRAND_KIT_MAX = 16;

    private function brandKitFor($tenant): array
    {
        $saved = $tenant->settings['brand_kit'] ?? null;
        if (is_array($saved) && count($saved)) {
            return array_values(array_map(function ($c) {
                return [
                    'name'  => (string) ($c['name'] ?? 'Color'),
                    'value' => (string) ($c['value'] ?? '#000000'),
                    'role'  => (string) ($c['role'] ?? ''),
                ];
            }, $saved));
        }
        return [
            ['name' => 'Accent',     'value' => $tenant->accent_color ?: '#BEF264', 'role' => 'accent'],
            ['name' => 'Text',       'value' => $tenant->text_color   ?: '#111111', 'role' => 'text'],
            ['name' => 'Background', 'value' => $tenant->bg_color     ?: '#FFFFFF', 'role' => 'background'],
        ];
    }

    public function saveBrandKit(Request $request)
    {
        $tenant = tenant();
        $colors = $request->input('colors', []);
        if (!is_array($colors)) $colors = [];

        $clean = [];
        foreach (array_slice($colors, 0, self::BRAND_KIT_MAX) as $c) {
            if (!is_array($c)) continue;
            $value = trim((string) ($c['value'] ?? ''));
            if ($value === '') continue;
            $name = trim((string) ($c['name'] ?? ''));
            $clean[] = [
                'name'  => $name !== '' ? mb_substr($name, 0, 40) : 'Color',
                'value' => mb_substr($value, 0, 32),
                'role'  => mb_substr(trim((string) ($c['role'] ?? '')), 0, 40),
            ];
        }

        $settings = $tenant->settings ?? [];
        $settings['brand_kit'] = $clean;
        $tenant->settings = $settings;
        $tenant->save();

        return response()->json(['ok' => true, 'colors' => $clean]);
    }

    private function seedDefaultSections(TenantPage $page): void
    {
        $types = ['nav', 'hero', 'services', 'cta_banner', 'footer'];
        foreach ($types as $i => $type) {
            TenantPageSection::create([
                'page_id' => $page->id, 'tenant_id' => $page->tenant_id,
                'section_type' => $type, 'content' => self::DEFAULTS[$type],
                'padding' => 'normal', 'is_visible' => true, 'sort_order' => $i,
            ]);
        }
    }

    public static function defaults(): array
    {
        return self::DEFAULTS;
    }
}

