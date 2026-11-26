<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantPost;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * MARKER-POSTS: staff side of Posts — write dated updates (trail
 * conditions, shop news, events) grouped into feeds. The Posts section
 * shows a feed on any page; every post also has its own page at /posts/{slug}.
 */
class PostController extends Controller
{
    public function index(Request $request)
    {
        $tenant = tenant();
        $feed   = (string) $request->query('feed', '');
        $feeds  = TenantPost::feedsFor($tenant->id);

        $posts = TenantPost::where('tenant_id', $tenant->id)
            ->when($feed !== '', fn ($q) => $q->where('feed', $feed))
            ->orderByRaw('published_at IS NULL DESC')
            ->orderByDesc('published_at')->orderByDesc('created_at')
            ->paginate(30)->withQueryString();

        return view('tenant.posts.index', compact('posts', 'feeds', 'feed'));
    }

    public function store(Request $request)
    {
        $tenant = tenant();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'feed'  => ['nullable', 'string', 'max:60'],
        ]);
        $post = TenantPost::create([
            'tenant_id'  => $tenant->id,
            'feed'       => trim((string) ($data['feed'] ?? '')) ?: 'News',
            'title'      => $data['title'],
            'slug'       => TenantPost::uniqueSlug($tenant->id, $data['title']),
            'created_by' => auth('tenant')->id(),
        ]);
        return redirect()->route('tenant.posts.edit', $post->id)->with('flash', 'Draft started — write it, then Publish.');
    }

    public function edit(string $id)
    {
        $tenant = tenant();
        $post  = TenantPost::where('tenant_id', $tenant->id)->findOrFail($id);
        $feeds = TenantPost::feedsFor($tenant->id);
        return view('tenant.posts.edit', compact('post', 'feeds'));
    }

    public function update(Request $request, string $id)
    {
        $tenant = tenant();
        $post = TenantPost::where('tenant_id', $tenant->id)->findOrFail($id);
        $data = $request->validate([
            'title'     => ['required', 'string', 'max:200'],
            'feed'      => ['nullable', 'string', 'max:60'],
            'body'      => ['nullable', 'string', 'max:50000'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'date'      => ['nullable', 'date_format:Y-m-d\TH:i'],
            'status'    => ['required', 'in:draft,published'],
        ]);

        $when = null;
        if ($data['status'] === 'published') {
            $when = ! empty($data['date'])
                ? Carbon::createFromFormat('Y-m-d\TH:i', $data['date'], $tenant->timezone())->utc()
                : ($post->published_at ?: now());
        }

        $post->update([
            'title'        => $data['title'],
            'feed'         => trim((string) ($data['feed'] ?? '')) ?: 'News',
            'body'         => $data['body'] ?? null,
            'image_url'    => ($data['image_url'] ?? '') !== '' ? $data['image_url'] : null,
            'published_at' => $when,
            'slug'         => $post->title !== $data['title'] && ! $post->published_at
                ? TenantPost::uniqueSlug($tenant->id, $data['title'], $post->id) : $post->slug,
        ]);

        $msg = $when
            ? ($when->isFuture() ? 'Saved — goes live ' . tlocal($when, 'M j, g:i A') . '.' : 'Saved and live.')
            : 'Saved as a draft.';
        return redirect()->route('tenant.posts.edit', $post->id)->with('flash', $msg);
    }

    public function destroy(string $id)
    {
        $post = TenantPost::where('tenant_id', tenant()->id)->findOrFail($id);
        $title = $post->title;
        $post->delete();
        return redirect()->route('tenant.posts.index')->with('flash', '“' . $title . '” deleted.');
    }
}
