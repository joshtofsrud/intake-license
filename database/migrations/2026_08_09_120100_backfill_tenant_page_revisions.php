<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// MARKER-REWIND — live tenants already have sites built. Give every one of
// them a restore point the moment rewind ships, rather than only protecting
// edits made from here on. Chunked so a large install doesn't load every
// section into memory at once.
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tenant_pages')->orderBy('id')->chunk(100, function ($pages) use ($now) {
            $rows = [];

            foreach ($pages as $page) {
                $sections = DB::table('tenant_page_sections')
                    ->where('page_id', $page->id)
                    ->orderBy('sort_order')->get();

                if ($sections->isEmpty()) {
                    continue; // nothing worth restoring to
                }

                $rows[] = [
                    'id'            => (string) Str::uuid(),
                    'tenant_id'     => $page->tenant_id,
                    'page_id'       => $page->id,
                    'label'         => 'Starting point (saved when history was turned on)',
                    'actor_name'    => null,
                    'section_count' => $sections->count(),
                    'snapshot'      => json_encode([
                        'page' => [
                            'title'            => $page->title,
                            'slug'             => $page->slug,
                            'meta_title'       => $page->meta_title,
                            'meta_description' => $page->meta_description,
                            'is_home'          => (bool) $page->is_home,
                            'is_in_nav'        => (bool) $page->is_in_nav,
                            'nav_order'        => (int) $page->nav_order,
                        ],
                        'sections' => $sections->map(fn ($s) => [
                            'section_type' => $s->section_type,
                            'content'      => json_decode($s->content ?? '[]', true),
                            'bg_color'     => $s->bg_color,
                            'padding'      => $s->padding,
                            'is_visible'   => (bool) $s->is_visible,
                            'sort_order'   => (int) $s->sort_order,
                        ])->all(),
                    ]),
                    'created_at' => $now,
                ];
            }

            if ($rows) {
                DB::table('tenant_page_revisions')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        DB::table('tenant_page_revisions')
            ->where('label', 'Starting point (saved when history was turned on)')
            ->delete();
    }
};
