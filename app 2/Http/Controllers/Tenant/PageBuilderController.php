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
    // Section type defaults
    private const DEFAULTS = [
        'nav'           => ['show_logo'=>true,'cta_label'=>'Book Now','cta_url'=>'/book','bg_style'=>'solid'],
        'hero'          => ['headline'=>'Your headline here','subheading'=>'A short description.','bg_type'=>'color','bg_color'=>'#1a1a1a','text_color'=>'#ffffff','cta_primary_label'=>'Book Now','cta_primary_url'=>'/book','height'=>'large'],
        'services'      => ['heading'=>'Our services','show_prices'=>true,'columns'=>3],
        'text_image'    => ['heading'=>'','body'=>'Your content here.','image_url'=>'','image_position'=>'right','cta_label'=>'','cta_url'=>''],
        'cta_banner'    => ['headline'=>'Ready to book?','subheading'=>'','cta_label'=>'Book Now','cta_url'=>'/book','bg_color'=>'','text_color'=>''],
        'image_gallery' => ['images'=>[],'columns'=>3],
        'contact_form'  => ['heading'=>'Get in touch','show_phone'=>true,'show_message'=>true],
        'booking_embed' => ['heading'=>'Book online'],
        'footer'        => ['show_logo'=>true,'show_copyright'=>true,'copyright_text'=>''],
    ];

    public function index(Request $request)
    {
        $tenant = tenant();
        $pages  = TenantPage::where('tenant_id', $tenant->id)
            ->orderByDesc('is_home')->orderBy('nav_order')->get();

        // Seed home page if none exist
        if ($pages->isEmpty()) {
            $home = TenantPage::create([
                'tenant_id'    => $tenant->id,
                'title'        => 'Home',
                'slug'         => 'home',
                'is_home'      => true,
                'is_published' => false,
                'is_in_nav'    => false,
                'nav_order'    => 0,
            ]);
            $this->seedDefaultSections($home);
            $pages = TenantPage::where('tenant_id', $tenant->id)->get();
        }

        return view('tenant.pages.index', compact('pages'));
    }

    public function edit(Request $request, string $id)
    {
        $tenant  = tenant();
        $page    = TenantPage::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $sections = TenantPageSection::where('page_id', $page->id)
            ->orderBy('sort_order')->get();
        $navItems = TenantNavItem::where('tenant_id', $tenant->id)->orderBy('sort_order')->get();
        $sectionTypes = array_keys(self::DEFAULTS);

        return view('tenant.pages.edit', compact('page', 'sections', 'navItems', 'sectionTypes'));
    }

    public function store(Request $request)
    {
        $tenant = tenant();
        $request->validate(['title' => ['required','string','max:191']]);

        $slug = Str::slug($request->input('title'));
        $i = 1;
        $base = $slug;
        while (TenantPage::where('tenant_id',$tenant->id)->where('slug',$slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        $page = TenantPage::create([
            'tenant_id'    => $tenant->id,
            'title'        => $request->input('title'),
            'slug'         => $slug,
            'is_home'      => false,
            'is_published' => false,
            'is_in_nav'    => true,
            'nav_order'    => TenantPage::where('tenant_id',$tenant->id)->max('nav_order') + 1,
        ]);

        return redirect()->route('tenant.pages.edit', $page->id)
            ->with('success', 'Page created. Start adding sections.');
    }

    public function update(Request $request, string $id)
    {
        $tenant = tenant();
        $page   = TenantPage::where('tenant_id',$tenant->id)->where('id',$id)->firstOrFail();

        $op = $request->input('op', 'update_page');

        if ($op === 'update_page') {
            $page->update([
                'title'        => $request->input('title', $page->title),
                'meta_title'   => $request->input('meta_title'),
                'meta_description' => $request->input('meta_description'),
                'is_published' => (bool)$request->input('is_published', 0),
                'is_in_nav'    => (bool)$request->input('is_in_nav', 1),
            ]);
            return back()->with('success', 'Page settings saved.');
        }

        if ($op === 'update_nav') {
            // Rebuild nav items from posted data
            TenantNavItem::where('tenant_id', $tenant->id)->delete();
            foreach ($request->input('nav_items', []) as $i => $item) {
                if (empty($item['label'])) continue;
                TenantNavItem::create([
                    'tenant_id'  => $tenant->id,
                    'label'      => $item['label'],
                    'url'        => $item['url'] ?? '/',
                    'sort_order' => $i,
                ]);
            }
            return back()->with('success', 'Navigation saved.');
        }

        return back();
    }

    public function destroy(Request $request, string $id)
    {
        $tenant = tenant();
        $page   = TenantPage::where('tenant_id',$tenant->id)->where('id',$id)->firstOrFail();
        if ($page->is_home) return back()->with('error', 'Cannot delete the home page.');
        $page->delete();
        return redirect()->route('tenant.pages.index')->with('success', 'Page deleted.');
    }

    // ----------------------------------------------------------------
    // Section CRUD
    // ----------------------------------------------------------------
    public function addSection(Request $request, string $id)
    {
        $tenant = tenant();
        $page   = TenantPage::where('tenant_id',$tenant->id)->where('id',$id)->firstOrFail();
        $type   = $request->input('type', 'hero');

        if (!array_key_exists($type, self::DEFAULTS)) {
            return response()->json(['error' => 'Unknown section type.'], 422);
        }

        $section = TenantPageSection::create([
            'page_id'    => $page->id,
            'tenant_id'  => $tenant->id,
            'section_type' => $type,
            'content'    => self::DEFAULTS[$type],
            'padding'    => 'normal',
            'is_visible' => true,
            'sort_order' => TenantPageSection::where('page_id',$page->id)->max('sort_order') + 1,
        ]);

        return response()->json(['success' => true, 'id' => $section->id, 'type' => $type]);
    }

    public function updateSection(Request $request, string $id, string $sid)
    {
        $tenant  = tenant();
        $page    = TenantPage::where('tenant_id',$tenant->id)->where('id',$id)->firstOrFail();
        $section = TenantPageSection::where('page_id',$page->id)->where('id',$sid)->firstOrFail();

        $content = $request->input('content', []);
        if (!is_array($content)) $content = [];

        $section->update([
            'content'    => array_merge($section->content ?? [], $content),
            'bg_color'   => $request->input('bg_color'),
            'padding'    => $request->input('padding', 'normal'),
            'is_visible' => (bool)$request->input('is_visible', 1),
        ]);

        return response()->json(['success' => true]);
    }

    public function deleteSection(Request $request, string $id, string $sid)
    {
        $tenant  = tenant();
        $page    = TenantPage::where('tenant_id',$tenant->id)->where('id',$id)->firstOrFail();
        TenantPageSection::where('page_id',$page->id)->where('id',$sid)->delete();
        return response()->json(['success' => true]);
    }

    public function reorderSections(Request $request, string $id)
    {
        $tenant  = tenant();
        $page    = TenantPage::where('tenant_id',$tenant->id)->where('id',$id)->firstOrFail();
        $order   = $request->input('order', []);

        foreach ($order as $i => $sectionId) {
            TenantPageSection::where('page_id',$page->id)->where('id',$sectionId)
                ->update(['sort_order' => $i]);
        }

        return response()->json(['success' => true]);
    }

    // ----------------------------------------------------------------
    // Seed default sections for a new home page
    // ----------------------------------------------------------------
    private function seedDefaultSections(TenantPage $page): void
    {
        $types = ['nav', 'hero', 'services', 'cta_banner', 'footer'];
        foreach ($types as $i => $type) {
            TenantPageSection::create([
                'page_id'    => $page->id,
                'tenant_id'  => $page->tenant_id,
                'section_type' => $type,
                'content'    => self::DEFAULTS[$type],
                'padding'    => 'normal',
                'is_visible' => true,
                'sort_order' => $i,
            ]);
        }
    }
}
