<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\QuizCompletion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PlanQuizController — handles plan quiz analytics logging.
 *
 * The quiz itself runs entirely client-side (see _plan-quiz.blade.php).
 * This controller just logs completions for the master admin dashboard.
 *
 * Rate-limited per session_id to prevent spam. No auth required since the quiz
 * is a marketing funnel — anyone on the site can take it.
 */
class PlanQuizController extends Controller
{
    /**
     * Log a completed quiz submission.
     *
     * Route: POST /api/plan-quiz/complete
     */
    public function complete(Request $request)
    {
        // Validate shape. Tolerant on specific values since client-side controls them.
        $data = $request->validate([
            'session_id'     => ['required', 'string', 'max:64'],
            'answers'        => ['required', 'array'],
            'answers.volume'   => ['required', 'string', 'max:32'],
            'answers.website'  => ['required', 'string', 'max:32'],
            'answers.locations' => ['required', 'string', 'max:32'],
            'answers.branding' => ['required', 'string', 'max:32'],
            'answers.setup'    => ['required', 'string', 'max:32'],
            'recommendation' => ['required', 'string', 'in:starter,branded,scale'],
            'tags_applied'   => ['nullable', 'array'],
            'tags_applied.*' => ['string', 'max:32'],
        ]);

        // Rate limit: max 5 completions per session_id per hour to prevent spam.
        $recent = QuizCompletion::where('session_id', $data['session_id'])
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recent >= 5) {
            return response()->json(['ok' => false, 'reason' => 'rate_limit'], 429);
        }

        try {
            QuizCompletion::create([
                'session_id'     => $data['session_id'],
                'answers'        => $data['answers'],
                'recommendation' => $data['recommendation'],
                'tags_applied'   => $data['tags_applied'] ?? [],
                'user_agent'     => substr((string) $request->userAgent(), 0, 255),
                'referrer'       => substr((string) $request->headers->get('referer', ''), 0, 255),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Plan quiz completion logging failed', [
                'error' => $e->getMessage(),
                'session_id' => $data['session_id'],
            ]);
            // Return success to client — analytics must never break user flow.
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => true]);
    }
}
