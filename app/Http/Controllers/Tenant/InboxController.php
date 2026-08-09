<?php
// MARKER-PATCH-221

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantThread;
use App\Services\Tenant\InboxService;
use Illuminate\Http\Request;

/**
 * Unified inbox (phase 1: SMS). Gated on the unified_inbox addon at the
 * top of every action — nav hides it, this 403s direct hits.
 */
class InboxController extends Controller
{
    public function __construct(
        protected InboxService $inbox,
    ) {}

    private function gate(): void
    {
        abort_unless(tenant()?->unified_inbox_enabled, 403, 'The Unified Inbox add-on is not active.');
    }

    public function index(Request $request)
    {
        $this->gate();
        $tenant = tenant();

        $filter = in_array($request->query('filter'), ['all', 'unread', 'closed'], true)
            ? $request->query('filter') : 'all';

        // MARKER-INBOX-SEARCH — a search spans every bucket. Narrowing by the
        // status pill while someone is hunting for a conversation is how you
        // get "it's not there" for a thread that is simply closed.
        $q = trim((string) $request->query('q', ''));
        $searching = $q !== '';

        $threads = TenantThread::where('tenant_id', $tenant->id)
            ->when(! $searching && $filter === 'unread', fn ($qb) => $qb->where(fn ($qq) => $qq->where('unread_count', '>', 0)->orWhere('status', 'needs_reply')))
            ->when(! $searching && $filter === 'closed', fn ($qb) => $qb->where('status', 'closed'))
            ->when(! $searching && $filter === 'all', fn ($qb) => $qb->where('status', '!=', 'closed'))
            ->when($searching, function ($qb) use ($q) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                $qb->where(function ($w) use ($like) {
                    $w->whereHas('customer', function ($c) use ($like) {
                        $c->where('first_name', 'like', $like)
                          ->orWhere('last_name', 'like', $like)
                          // Neither column alone matches a full name.
                          ->orWhereRaw("CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?", [$like])
                          ->orWhere('business_name', 'like', $like)
                          ->orWhere('email', 'like', $like)
                          ->orWhere('phone', 'like', $like);
                    })->orWhereHas('messages', fn ($m) => $m->where('body', 'like', $like));
                });
            })
            ->with(['customer:id,first_name,last_name,business_name,customer_type,phone,email,sms_opt_out_at', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        // MARKER-INBOX-SEARCH — when the hit was in a message, show that
        // message rather than the newest one. One query for the page.
        $searchHits = [];
        if ($searching && $threads->isNotEmpty()) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $searchHits = \App\Models\Tenant\TenantMessage::query()
                ->whereIn('thread_id', $threads->pluck('id'))
                ->where('body', 'like', $like)
                ->orderByDesc('created_at')
                ->get(['thread_id', 'body', 'created_at'])
                ->groupBy('thread_id')
                ->map(fn ($rows) => $rows->first())
                ->all();
        }

        $needsReplyCount = TenantThread::where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('unread_count', '>', 0)->orWhere('status', 'needs_reply'))
            ->count();

        $selected = null;
        if ($request->query('thread')) {
            $selected = TenantThread::where('tenant_id', $tenant->id)
                ->where('id', $request->query('thread'))
                ->with(['customer', 'messages'])
                ->first();
            if ($selected) {
                $this->inbox->markRead($selected);
            }
        }

        return view('tenant.inbox.index', compact('threads', 'filter', 'selected', 'needsReplyCount', 'q', 'searching', 'searchHits'));
    }

    public function send(Request $request, string $id)
    {
        $this->gate();
        $tenant = tenant();

        $request->validate([
            'body'          => ['required', 'string', 'max:1200'],
            'as_note'       => ['nullable', 'boolean'],
            'reply_channel' => ['nullable', 'in:sms,email'],
        ]);

        $thread = TenantThread::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        try {
            if ($request->boolean('as_note')) {
                $this->inbox->postNote($thread, $request->input('body'), auth('tenant')->id());
            } else {
                $this->inbox->postOutbound($tenant, $thread, $request->input('body'), auth('tenant')->id(), $request->input('reply_channel'));
            }
        } catch (\RuntimeException $e) {
            return redirect()->route('tenant.inbox.index', ['thread' => $thread->id])
                ->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('tenant.inbox.index', ['thread' => $thread->id]);
    }

    // MARKER-PATCH-401 — soft-delete a single message, scoped to this tenant.
    public function deleteMessage(string $id)
    {
        $tenant = tenant();
        $message = \App\Models\Tenant\TenantMessage::whereHas('thread', function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id);
        })->findOrFail($id);

        $message->delete();

        return back()->with('success', 'Message deleted.');
    }

    public function toggleStatus(Request $request, string $id)
    {
        $this->gate();
        $tenant = tenant();

        $thread = TenantThread::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();
        $thread->update(['status' => $thread->status === 'closed' ? 'open' : 'closed']);

        return redirect()->route('tenant.inbox.index', ['thread' => $thread->id]);
    }

    /** Start (or resume) a conversation with a customer — used by quick actions. */
    public function start(Request $request)
    {
        $this->gate();
        $tenant = tenant();

        $request->validate([
            'customer_id' => ['required', 'string', 'uuid'],
            'body'        => ['required', 'string', 'max:1200'],
            'channel'     => ['nullable', 'in:sms,email'], // MARKER-INBOX-NEW
        ]);

        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $request->input('customer_id'))->firstOrFail();

        // MARKER-INBOX-NEW — channel comes from the compose panel; it seeds the
        // thread when new and is passed explicitly so postOutbound can't infer.
        $channel = $request->input('channel', 'sms');

        $thread = $this->inbox->threadFor($tenant, $customer, $channel);

        try {
            $this->inbox->postOutbound($tenant, $thread, $request->input('body'), auth('tenant')->id(), $channel);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('tenant.inbox.index', ['thread' => $thread->id]);
    }
}
