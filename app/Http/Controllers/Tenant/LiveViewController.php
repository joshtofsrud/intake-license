<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\LiveViewSession;
use App\Services\LiveView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MARKER-LIVE-VIEW: the shop's side. Every signed-in Intake tab checks
 * pending() every 15 seconds; the tab that's allowed posts its recording to
 * events(). See App\Services\LiveView.
 */
class LiveViewController extends Controller
{
    public function pending(): JsonResponse
    {
        // master admin signed in as the shop is the one asking, not the shop
        if (is_impersonating()) return response()->json(['ask' => null]);

        $s = LiveView::pending(app('tenant'), auth('tenant')->user());

        return response()->json(['ask' => $s ? ['id' => $s->id, 'by' => $s->requested_by_name] : null]);
    }

    public function answer(Request $request, string $id): JsonResponse
    {
        $s = $this->find($id);
        $ok = LiveView::answer($s, auth('tenant')->user(), $request->boolean('allow'));

        return response()->json(['ok' => $ok, 'minutes' => LiveView::LASTS], $ok ? 200 : 409);
    }

    public function events(Request $request, string $id): JsonResponse
    {
        $s = $this->find($id);
        $body = (string) $request->getContent();
        if ($request->header('X-LV-Gzip') === '1' && $body !== '') {
            $body = @gzdecode($body, LiveView::MAX_BYTES);
            if ($body === false) return response()->json(['ok' => false, 'want' => true, 'end' => false], 422);
        }

        return response()->json(LiveView::push($s, auth('tenant')->user(), $body));
    }

    public function end(string $id): JsonResponse
    {
        $s = $this->find($id);
        $u = auth('tenant')->user();
        if ($s->accepted_by_user_id === $u->id) LiveView::end($s, 'they pressed Stop', $u->name);

        return response()->json(['ok' => true]);
    }

    private function find(string $id): LiveViewSession
    {
        return LiveViewSession::where('tenant_id', app('tenant')->id)->findOrFail($id);
    }
}
