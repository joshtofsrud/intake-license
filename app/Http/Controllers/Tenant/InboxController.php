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

        $threads = TenantThread::where('tenant_id', $tenant->id)
            ->when($filter === 'unread', fn ($q) => $q->where(fn ($qq) => $qq->where('unread_count', '>', 0)->orWhere('status', 'needs_reply')))
            ->when($filter === 'closed', fn ($q) => $q->where('status', 'closed'))
            ->when($filter === 'all', fn ($q) => $q->where('status', '!=', 'closed'))
            ->with(['customer:id,first_name,last_name,phone,sms_opt_out_at', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

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

        return view('tenant.inbox.index', compact('threads', 'filter', 'selected', 'needsReplyCount'));
    }

    public function send(Request $request, string $id)
    {
        $this->gate();
        $tenant = tenant();

        $request->validate([
            'body'    => ['required', 'string', 'max:1200'],
            'as_note' => ['nullable', 'boolean'],
        ]);

        $thread = TenantThread::where('tenant_id', $tenant->id)->where('id', $id)->firstOrFail();

        try {
            if ($request->boolean('as_note')) {
                $this->inbox->postNote($thread, $request->input('body'), auth('tenant')->id());
            } else {
                $this->inbox->postOutbound($tenant, $thread, $request->input('body'), auth('tenant')->id());
            }
        } catch (\RuntimeException $e) {
            return redirect()->route('tenant.inbox.index', ['thread' => $thread->id])
                ->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('tenant.inbox.index', ['thread' => $thread->id]);
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
        ]);

        $customer = TenantCustomer::where('tenant_id', $tenant->id)
            ->where('id', $request->input('customer_id'))->firstOrFail();

        $thread = $this->inbox->threadFor($tenant, $customer, 'sms');

        try {
            $this->inbox->postOutbound($tenant, $thread, $request->input('body'), auth('tenant')->id());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return redirect()->route('tenant.inbox.index', ['thread' => $thread->id]);
    }
}
