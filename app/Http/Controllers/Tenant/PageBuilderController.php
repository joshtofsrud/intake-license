<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantPageSection;
use App\Models\Tenant\TenantNavItem;
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
    ];

    private const DEFAULTS = [
        'nav'           => ['show_logo'=>true,'cta_label'=>'Book Now','cta_url'=>'/book','bg_style'=>'solid'],
        'hero'          => ['eyebrow'=>'','headline'=>'Your headline here','accent_words'=>'','subheading'=>'A short description.','bg_type'=>'color','bg_color'=>'#1a1a1a','text_color'=>'#ffffff','cta_primary_label'=>'Book Now','cta_primary_url'=>'/book','height'=>'large','text_align'=>'center','note'=>''],
        'services'      => ['heading'=>'Our services','show_prices'=>true,'columns'=>3],
        'text_image'    => ['eyebrow'=>'','heading'=>'','body'=>'Your content here.','image_url'=>'','image_position'=>'right','cta_label'=>'','cta_url'=>''],
        'cta_banner'    => ['headline'=>'Ready to book?','subheading'=>'','cta_label'=>'Book Now','cta_url'=>'/book','bg_color'=>'','text_color'=>''],
        'image_gallery' => ['images'=>[],'columns'=>3],
        'contact_form'  => ['eyebrow'=>'','heading'=>'Get in touch','subheading'=>'','show_phone'=>true,'show_message'=>true],
        'booking_embed' => ['heading'=>'Book online'],
        'footer'        => ['show_logo'=>true,'show_copyright'=>true,'copyright_text'=>''],

        'pricing_table' => [
            'eyebrow'    => '', 'heading' => 'Pricing', 'subheading' => 'Pick the plan that fits.',
            'source'     => 'config', 'featured' => 'branded', 'plans' => [], 'footnote' => '',
        ],
        'feature_grid' => [
            'eyebrow' => '', 'heading' => 'Features', 'subheading' => '', 'columns' => 3,
            'features' => [
                ['icon' => '✓', 'title' => 'Feature 1', 'body' => 'Short description.'],
                ['icon' => '✓', 'title' => 'Feature 2', 'body' => 'Short description.'],
                ['icon' => '✓', 'title' => 'Feature 3', 'body' => 'Short description.'],
            ],
            'cta_label' => '', 'cta_url' => '',
        ],
        'step_timeline' => [
            'eyebrow' => '', 'heading' => 'How it works', 'subheading' => '',
            'steps' => [
                ['title' => 'Sign up', 'desc' => 'Short step description', 'done' => true],
                ['title' => 'Customize', 'desc' => 'Short step description', 'done' => true],
                ['title' => 'Launch', 'desc' => 'Short step description', 'done' => false],
            ],
        ],
        'testimonial_carousel' => [
            'eyebrow' => '', 'heading' => 'What customers say', 'subheading' => '',
            'testimonials' => [['quote' => 'This changed how we run the shop.', 'author' => 'Name', 'role' => 'Owner']],
        ],
        'logo_bar'         => ['heading' => 'Trusted by shops like', 'shop_names' => [], 'logos' => []],
        'faq_accordion'    => ['eyebrow'=>'','heading'=>'Frequently asked','subheading'=>'','items'=>[['q'=>'A common question?','a'=>'A clear answer.']]],
        'comparison_table' => ['eyebrow'=>'','heading'=>'How we compare','subheading'=>'','competitors'=>['Intake','Other'],'rows'=>[['feature'=>'Feature','values'=>['yes','no']]]],
        'industry_pack_showcase' => ['eyebrow'=>'','heading'=>'Built for your industry','subheading'=>'Pick your industry, get pre-configured services, pricing, and content.','limit'=>12,'show_all_link'=>true],
        'stats_row'        => ['eyebrow'=>'','heading'=>'','stats'=>[['number'=>'200+','label'=>'Businesses'],['number'=>'50k+','label'=>'Appointments'],['number'=>'24','label'=>'Industries']]],
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

        return view('tenant.pages.edit', compact('page', 'sections', 'navItems', 'sectionTypes'));
    }

    public function store(Request $request)
    {
        $tenant = tenant();

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

        return redirect()->route('tenant.pages.index', ['edit' => $page->id])
            ->with('success', 'Page created. Start adding sections.');
    }

    public function edit(Request $request, string $id)        { return redirect()->route('tenant.pages.index', ['edit' => $id]); }
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
            TenantNavItem::where('tenant_id', $tenant->id)->delete();
            foreach ($request->input('nav_items', []) as $i => $item) {
                if (empty($item['label'])) continue;
                TenantNavItem::create([
                    'tenant_id' => $tenant->id,
                    'label' => $item['label'],
                    'url' => $item['url'] ?? '/',
                    'sort_order' => $i,
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
            TenantPageSection::where('page_id', $page->id)->where('id', $sid)->delete();
            return response()->json(['success' => true]);
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
