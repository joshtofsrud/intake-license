<?php

// MARKER-OLD-SCHOOL

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\TenantCustomer;
use App\Models\Tenant\TenantNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class NoteController extends Controller
{
    /** The pad, full page. Open and crossed-off. */
    public function index(Request $request): View
    {
        $tenant = tenant();
        $tab = in_array($request->input('tab'), ['done', 'report'], true)
            ? $request->input('tab')
            : 'open';

        // MARKER-OLD-SCHOOL-REPORT — the report needs no note list.
        if ($tab === 'report') {
            return view('tenant.notes.report', $this->reportData() + [
                'tab'       => $tab,
                'openCount' => self::openCount(),
                'doneCount' => TenantNote::where('tenant_id', $tenant->id)
                    ->whereNotNull('completed_at')->count(),
            ]);
        }

        $q = TenantNote::where('tenant_id', $tenant->id)
            ->with(['customer', 'author', 'completer']);

        if ($tab === 'done') {
            $notes = (clone $q)->whereNotNull('completed_at')
                ->orderByDesc('completed_at')->paginate(40);
        } else {
            // Oldest first: a pad is cleared from the bottom of the pile, and
            // the stale ones are the whole reason to look.
            $notes = (clone $q)->whereNull('completed_at')
                ->orderBy('created_at')->paginate(40);
        }

        return view('tenant.notes.index', [
            'notes'     => $notes,
            'tab'       => $tab,
            'openCount' => self::openCount(),
            'doneCount' => TenantNote::where('tenant_id', $tenant->id)
                ->whereNotNull('completed_at')->count(),
            'oldest'    => TenantNote::where('tenant_id', $tenant->id)
                ->whereNull('completed_at')->orderBy('created_at')->first(),
        ]);
    }

    /**
     * MARKER-OLD-SCHOOL-REPORT — everything the report shows.
     *
     * Computed in PHP from open notes and eight weeks of timestamps. A pad is
     * small, and grouping by week in SQL would mean MySQL-only date functions
     * for no benefit.
     */
    private function reportData(): array
    {
        $tenantId = tenant()->id;
        $now      = now();
        $weekAgo  = $now->copy()->startOfWeek();

        $open = TenantNote::where('tenant_id', $tenantId)
            ->whereNull('completed_at')
            ->with(['author', 'customer'])
            ->orderBy('created_at')
            ->get();

        // Age buckets. The shape of these is the health of the pad: weight on
        // the left is a pad being worked, weight on the right is one being
        // ignored.
        $buckets = ['0-2' => 0, '3-7' => 0, '8-30' => 0, '30+' => 0];
        foreach ($open as $n) {
            $d = $n->ageInDays();
            if ($d <= 2)       { $buckets['0-2']++; }
            elseif ($d <= 7)   { $buckets['3-7']++; }
            elseif ($d <= 30)  { $buckets['8-30']++; }
            else               { $buckets['30+']++; }
        }

        // Eight weeks of written-against-cleared.
        $since = $now->copy()->subWeeks(8)->startOfWeek();
        $rows = TenantNote::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('created_at', '>=', $since)
                ->orWhere('completed_at', '>=', $since))
            ->get(['created_at', 'completed_at']);

        $weeks = [];
        for ($i = 7; $i >= 0; $i--) {
            $start = $now->copy()->subWeeks($i)->startOfWeek();
            $weeks[$start->toDateString()] = [
                'label'   => $start->format('M j'),
                'written' => 0,
                'cleared' => 0,
            ];
        }
        foreach ($rows as $r) {
            if ($r->created_at && $r->created_at >= $since) {
                $k = $r->created_at->copy()->startOfWeek()->toDateString();
                if (isset($weeks[$k])) { $weeks[$k]['written']++; }
            }
            if ($r->completed_at && $r->completed_at >= $since) {
                $k = $r->completed_at->copy()->startOfWeek()->toDateString();
                if (isset($weeks[$k])) { $weeks[$k]['cleared']++; }
            }
        }
        $peak = max(1, max(array_merge(
            array_column($weeks, 'written'),
            array_column($weeks, 'cleared')
        )));

        // People. Written is shown and never ranked — see the header comment.
        $people = [];
        $touch = function (?string $id, ?string $name) use (&$people) {
            $id = $id ?: 'unknown';
            $people[$id] ??= [
                'name' => $name ?: 'Someone', 'written' => 0, 'cleared' => 0,
                'still_open' => 0, 'oldest' => 0,
            ];
            return $id;
        };

        $recent = TenantNote::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('created_at', '>=', $weekAgo)
                ->orWhere('completed_at', '>=', $weekAgo))
            ->with(['author', 'completer'])
            ->get();

        foreach ($recent as $n) {
            if ($n->created_at >= $weekAgo) {
                $people[$touch($n->created_by, $n->author?->name)]['written']++;
            }
            if ($n->completed_at && $n->completed_at >= $weekAgo) {
                $people[$touch($n->completed_by, $n->completer?->name)]['cleared']++;
            }
        }
        foreach ($open as $n) {
            $k = $touch($n->created_by, $n->author?->name);
            $people[$k]['still_open']++;
            $people[$k]['oldest'] = max($people[$k]['oldest'], $n->ageInDays());
        }
        uasort($people, fn ($a, $b) => $b['still_open'] <=> $a['still_open']);

        return [
            'openCount'   => $open->count(),
            'oldest'      => $open->first(),
            'clearedWeek' => TenantNote::where('tenant_id', $tenantId)
                ->where('completed_at', '>=', $weekAgo)->count(),
            'writtenWeek' => TenantNote::where('tenant_id', $tenantId)
                ->where('created_at', '>=', $weekAgo)->count(),
            'buckets'     => $buckets,
            'weeks'       => array_values($weeks),
            'peak'        => $peak,
            'people'      => $people,
            // Stuck: over a week old, oldest first. The point of the page.
            'stuck'       => $open->filter(fn ($n) => $n->ageInDays() >= 7)->take(10),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = tenant();

        $data = $request->validate([
            'body'        => ['required', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'string', 'max:64'],
            'back'        => ['nullable', 'string', 'max:2000'],
        ]);

        // A customer from another tenant would be a data leak, so it is
        // verified rather than trusted from the form.
        $customerId = null;
        if (! empty($data['customer_id'])) {
            $customerId = TenantCustomer::where('tenant_id', $tenant->id)
                ->where('id', $data['customer_id'])->value('id');
        }

        TenantNote::create([
            'tenant_id'   => $tenant->id,
            'location_id' => $request->session()->get('current_location_id'),
            'body'        => trim($data['body']),
            'customer_id' => $customerId,
            'created_by'  => Auth::guard('tenant')->id(),
        ]);

        return $this->backTo($data['back'] ?? null)->with('flash', [
            'type' => 'success', 'message' => 'Note added.',
        ]);
    }

    /** Cross off, or put back. One button, both directions. */
    public function toggle(Request $request, string $id): RedirectResponse
    {
        $note = TenantNote::where('tenant_id', tenant()->id)->findOrFail($id);

        if ($note->completed_at === null) {
            $note->update([
                'completed_at' => now(),
                'completed_by' => Auth::guard('tenant')->id(),
            ]);
            $msg = 'Crossed off.';
        } else {
            $note->update(['completed_at' => null, 'completed_by' => null]);
            $msg = 'Back on the pad.';
        }

        return $this->backTo($request->input('back'))->with('flash', [
            'type' => 'success', 'message' => $msg,
        ]);
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $note = TenantNote::where('tenant_id', tenant()->id)->findOrFail($id);
        $note->delete();

        return $this->backTo($request->input('back'))->with('flash', [
            'type' => 'success', 'message' => 'Note deleted.',
        ]);
    }

    /** Open notes for the whole shop — the badge on the pad button. */
    public static function openCount(): int
    {
        return TenantNote::where('tenant_id', tenant()->id)
            ->whereNull('completed_at')->count();
    }

    /**
     * Return to where the note was written.
     *
     * Only same-host paths are honoured — an open redirect from a posted
     * field is exactly the kind of thing that looks harmless in a scratch
     * pad and is not.
     */
    private function backTo(?string $back): RedirectResponse
    {
        if (is_string($back) && str_starts_with($back, '/') && ! str_starts_with($back, '//')) {
            return redirect()->to($back);
        }
        return redirect()->back();
    }
}
