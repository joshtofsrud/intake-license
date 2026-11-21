<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Jobs\SiteGoogleSyncJob;
use App\Jobs\SiteHealthJob;
use App\Models\Tenant\TenantGoogleConnection;
use App\Models\Tenant\TenantPage;
use App\Models\Tenant\TenantSiteHealth;
use App\Models\Tenant\TenantSiteIssue;
use App\Services\SiteHealth\GoogleSiteData;
use App\Services\SiteHealth\SiteChecker;
use App\Support\SiteHealthView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * MARKER-SITE-HEALTH: the shop's list of things to fix so more people find it.
 * Nothing on the public site changes until a fix is saved here, and every saved
 * fix can be undone until the next check.
 */
class SiteHealthController extends Controller
{
    /** GET /site-health  (?part=main|rail returns just that part, for the page's own refresh) */
    public function index(Request $request)
    {
        $sh = SiteHealthView::data(tenant());
        if (in_array($request->query('part'), ['main', 'rail'], true)) {
            return view('tenant.site-health._' . $request->query('part'), ['sh' => $sh]);
        }

        return view('tenant.site-health.index', ['sh' => $sh]);
    }

    /** GET /site-health/status — how far a running check and a running Google read have got. */
    public function status(): JsonResponse
    {
        // asked every second or two while something runs, so it reads two rows and nothing else
        $h = TenantSiteHealth::where('tenant_id', tenant()->id)->first();
        $st = (array) ($h?->state ?? []);
        $run = [
            'running' => (bool) ($st['running'] ?? false), 'stopped' => (bool) ($st['stopped'] ?? false), 'error' => $st['error'] ?? null,
            'pct' => SiteChecker::percent($st), 'done' => (int) ($st['done'] ?? 0), 'total' => (int) ($st['total'] ?? 0), 'what' => SiteChecker::phaseWord($st),
        ];
        if ($run['running'] && $h->updated_at?->lt(now()->subMinutes(10))) {
            $run = ['running' => false, 'stopped' => true, 'error' => 'The check stopped part way.'] + $run;
        }
        $conn = GoogleSiteData::available() ? $this->conn() : null;
        $gRunning = (bool) (($conn?->state ?? [])['running'] ?? false) && $conn->updated_at?->gt(now()->subMinutes(6));

        $found = array_fill_keys(array_keys(SiteHealthView::GROUPS), 0);
        foreach (TenantSiteIssue::where('tenant_id', tenant()->id)->where('status', 'open')->selectRaw('grp, COUNT(*) as n')->groupBy('grp')->pluck('n', 'grp') as $g => $n) {
            $found[$g] = (int) $n;
        }

        return response()->json(['run' => $run, 'checked' => (bool) $h?->checked_at, 'google' => ['running' => $gRunning], 'live' => SiteHealthView::live($st, $found)]);
    }

    public function check(SiteChecker $checker): JsonResponse
    {
        $tenant = tenant();
        $h = TenantSiteHealth::for($tenant->id);
        $st = (array) ($h->state ?? []);
        if (($st['running'] ?? false) && $h->updated_at?->gt(now()->subMinutes(10))) {
            return response()->json(['ok' => true]);
        }
        $checker->start($tenant);
        SiteHealthJob::dispatch($tenant->id);

        return response()->json(['ok' => true]);
    }

    public function stop(SiteChecker $checker): JsonResponse
    {
        if ($h = TenantSiteHealth::where('tenant_id', tenant()->id)->first()) {
            $checker->stop($h);
        }

        return response()->json(['ok' => true]);
    }

    public function resume(SiteChecker $checker): JsonResponse
    {
        $tenant = tenant();
        $h = TenantSiteHealth::for($tenant->id);
        $st = (array) ($h->state ?? []);
        // a check the queue dropped part way still says "running": treat it as stopped
        if (($st['running'] ?? false) && $h->updated_at?->lt(now()->subMinutes(10))) {
            $st['running'] = false;
            $st['stopped'] = true;
            $h->state = $st;
            $h->save();
        }
        if (! $checker->resume($h)) {
            return response()->json(['ok' => false, 'message' => 'There is no stopped check to carry on. Start a new one.'], 422);
        }
        SiteHealthJob::dispatch($tenant->id);

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ fixes

    /** Save the title or description written for a page. */
    public function save(Request $request, int $issue): JsonResponse
    {
        $i = $this->issue($issue);
        $field = ($i->detail ?? [])['field'] ?? null;
        $page = $i->subject_type === 'page' && $i->subject_id ? TenantPage::where('tenant_id', tenant()->id)->whereKey($i->subject_id)->first() : null;
        if (! $page || ! in_array($field, ['title', 'description'], true)) {
            return response()->json(['ok' => false, 'message' => 'This one is fixed where the link takes you, not here.'], 422);
        }
        $col = $field === 'title' ? 'meta_title' : 'meta_description';
        $value = trim((string) $request->input('value', ''));
        if ($value === '') {
            throw ValidationException::withMessages(['value' => 'Write something first.']);
        }
        if (mb_strlen($value) > ($field === 'title' ? 120 : 320)) {
            throw ValidationException::withMessages(['value' => 'That is longer than the field holds.']);
        }
        if ($value === trim((string) $page->{$col})) {
            throw ValidationException::withMessages(['value' => 'That is what the page already says.']);
        }

        $i->undo = ['col' => $col, 'was' => $page->{$col}];
        $page->{$col} = $value;
        $page->save();
        $i->status = 'fixed';
        $i->save();
        SiteChecker::rescore(tenant()->id);

        return response()->json(['ok' => true, 'message' => ($field === 'title' ? 'Title' : 'Description') . ' saved. It is live on your site now.']);
    }

    /** Put back what a saved fix replaced. */
    public function undo(int $issue): JsonResponse
    {
        $i = $this->issue($issue);
        $u = (array) ($i->undo ?? []);
        $page = $i->subject_id ? TenantPage::where('tenant_id', tenant()->id)->whereKey($i->subject_id)->first() : null;
        if ($i->status !== 'fixed' || ! $page || ! in_array($u['col'] ?? '', ['meta_title', 'meta_description'], true)) {
            return response()->json(['ok' => false, 'message' => 'There is nothing to undo here.'], 422);
        }
        $page->{$u['col']} = $u['was'];
        $page->save();
        $i->status = 'open';
        $i->undo = null;
        $i->save();
        SiteChecker::rescore(tenant()->id);

        return response()->json(['ok' => true, 'message' => 'Put back the way it was.']);
    }

    /** "Not a problem": set it aside. It stops counting against the score. */
    public function hide(int $issue): JsonResponse
    {
        $i = $this->issue($issue);
        if ($i->status === 'open') {
            $i->status = 'hidden';
            $i->save();
            SiteChecker::rescore(tenant()->id);
        }

        return response()->json(['ok' => true, 'message' => 'Set aside. Find it under Done and set aside.']);
    }

    public function restore(int $issue): JsonResponse
    {
        $i = $this->issue($issue);
        if ($i->status === 'hidden') {
            $i->status = 'open';
            $i->save();
            SiteChecker::rescore(tenant()->id);
        }

        return response()->json(['ok' => true, 'message' => 'Back on the list.']);
    }

    /** GET /site-health/fix/{issue}/items?offset= — the next items behind an item row, by name. */
    public function items(Request $request, int $issue): JsonResponse
    {
        $i = $this->issue($issue);
        abort_unless($i->subject_type === 'items', 404);
        $offset = max(0, (int) $request->query('offset', 0));
        $base = \Illuminate\Support\Facades\DB::table('tenant_site_issue_items as b')
            ->join('tenant_inventory_items as it', 'it.id', '=', 'b.item_id')
            ->where('b.tenant_id', tenant()->id)->where('it.tenant_id', tenant()->id)
            ->where('b.code', $i->code)->where('b.cat', (string) $i->subject_id)->where('b.run', $i->run)->whereNull('it.deleted_at');
        $total = (clone $base)->count();
        $rows = $base->orderBy('it.name')->orderBy('it.id')->offset($offset)->limit(50)->get(['it.id', 'it.name', 'b.n']);

        return response()->json([
            'total' => $total,
            'items' => $rows->map(fn ($r) => ['name' => \Illuminate\Support\Str::limit((string) $r->name, 60), 'words' => (int) $r->n, 'url' => route('tenant.inventory.edit', $r->id)])->all(),
            'next' => $offset + $rows->count() < $total ? $offset + $rows->count() : null,
        ]);
    }

    private function issue(int $id): TenantSiteIssue
    {
        return TenantSiteIssue::where('tenant_id', tenant()->id)->whereKey($id)->firstOrFail();
    }

    // ----------------------------------------------------------------- Google

    public function googleConnect(GoogleSiteData $google)
    {
        abort_unless(GoogleSiteData::available(), 404);
        if (function_exists('is_impersonating') && is_impersonating()) {
            return back()->with('error', 'Connect Google as the shop, not while viewing as them.');
        }

        return redirect()->away($google->begin(tenant(), Auth::guard('tenant')->id(), route('tenant.site_health.index') . '?from=google'));
    }

    public function googleDisconnect(GoogleSiteData $google)
    {
        if ($conn = $this->conn()) {
            $google->disconnect($conn);
        }

        return redirect()->route('tenant.site_health.index')->with('success', 'Google is disconnected. Intake has forgotten the sign-in and the numbers it read.');
    }

    public function googleRefresh(): JsonResponse
    {
        abort_unless(GoogleSiteData::available(), 404);
        $conn = $this->conn();
        if (! $conn) {
            return response()->json(['ok' => false, 'message' => 'Connect Google first.'], 422);
        }
        $st = (array) ($conn->state ?? []);
        $st['running'] = true;
        $st['error'] = null;
        $conn->state = $st;
        $conn->save();
        SiteGoogleSyncJob::dispatch($conn->tenant_id);

        return response()->json(['ok' => true]);
    }

    /** Which property / account / location to read, when the sign-in can see more than one. */
    public function googlePick(Request $request): JsonResponse
    {
        abort_unless(GoogleSiteData::available(), 404);
        $conn = $this->conn();
        $src = (string) $request->input('source');
        $ids = array_column((array) (($conn?->options ?? [])[$src] ?? []), 'id');
        if (! $conn || ! in_array($request->input('id'), $ids, true)) {
            throw ValidationException::withMessages(['id' => 'Pick one from the list.']);
        }
        $picks = (array) ($conn->picks ?? []);
        $picks[$src] = (string) $request->input('id');
        $conn->picks = $picks;
        $st = (array) ($conn->state ?? []);
        $st['running'] = true;
        $conn->state = $st;
        $conn->save();
        SiteGoogleSyncJob::dispatch($conn->tenant_id);

        return response()->json(['ok' => true, 'message' => 'Saved. Reading it now.']);
    }

    /** Post the shop's reply to a Google review. Public, so the page asks first. */
    public function googleReply(Request $request, GoogleSiteData $google): JsonResponse
    {
        abort_unless(GoogleSiteData::available() && GoogleSiteData::settings()['reviews'], 404);
        $data = $request->validate(['review' => ['required', 'string', 'max:300'], 'text' => ['required', 'string', 'min:2', 'max:4000']]);
        $conn = $this->conn();
        if (! $conn) {
            return response()->json(['ok' => false, 'message' => 'Connect Google first.'], 422);
        }
        try {
            $google->reply($conn, $data['review'], trim($data['text']));
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Your reply is posted on Google.']);
    }

    private function conn(): ?TenantGoogleConnection
    {
        return TenantGoogleConnection::where('tenant_id', tenant()->id)->first();
    }
}
