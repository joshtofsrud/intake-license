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
        $tab = $request->input('tab') === 'done' ? 'done' : 'open';

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
