<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPost;
use App\Services\Tenant\SiteChromeService;
use Illuminate\Http\Request;

/** MARKER-POSTS: public /posts and /posts/{slug}, inside the shop's own header and footer. */
class PostPublicController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        abort_unless($tenant, 404);
        $feed  = (string) $request->query('feed', '');
        $feeds = TenantPost::where('tenant_id', $tenant->id)->live()->distinct()->orderBy('feed')->pluck('feed')->all();
        $posts = TenantPost::where('tenant_id', $tenant->id)->live()
            ->when($feed !== '', fn ($q) => $q->where('feed', $feed))
            ->orderByDesc('published_at')->paginate(12)->withQueryString();

        return SiteChromeService::render($tenant, 'post_index', [
            'posts' => $posts, 'feeds' => $feeds, 'feed' => $feed, 'tenant' => $tenant,
        ], ['title' => ($feed !== '' ? $feed : 'Posts') . ' — ' . $tenant->name]);
    }

    public function show(string $slug)
    {
        $tenant = tenant();
        abort_unless($tenant, 404);
        $post = TenantPost::where('tenant_id', $tenant->id)->where('slug', $slug)->live()->firstOrFail();
        $more = TenantPost::where('tenant_id', $tenant->id)->live()->where('feed', $post->feed)
            ->where('id', '!=', $post->id)->orderByDesc('published_at')->limit(3)->get();

        return SiteChromeService::render($tenant, 'post_show', [
            'post' => $post, 'more' => $more, 'tenant' => $tenant,
        ], [
            'title'       => $post->title . ' — ' . $tenant->name,
            'description' => $post->excerpt(155),
            'image'       => $post->image_url,
            'og_type'     => 'article',
        ]);
    }
}
