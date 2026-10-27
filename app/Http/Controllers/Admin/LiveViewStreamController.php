<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LiveViewSession;
use App\Services\LiveView;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MARKER-LIVE-VIEW: master admin's side of a live view, as server-sent
 * events. Each batch the shop's tab posted goes out as one message with its
 * number as the id, so a reconnect (the browser does it by itself) carries
 * on where it left off. Each connection runs about 50 seconds, inside
 * nginx's 60 second limit, and holds one PHP worker while it does.
 */
class LiveViewStreamController extends Controller
{
    /** How long one connection runs. Tests shorten it. */
    public static float $seconds = 50;
    /** Push each message out as it's written. Tests read the output instead. */
    public static bool $flush = true;

    public function stream(Request $request, string $id): StreamedResponse
    {
        $s = LiveViewSession::with('tenant')->findOrFail($id);
        $last = $request->headers->get('Last-Event-ID', $request->query('after'));
        $after = is_numeric($last) ? (int) $last : -1;
        if ($after < 0) LiveView::wantSnapshot($s);

        return new StreamedResponse(function () use ($s, $after) {
            @set_time_limit((int) self::$seconds + 15);
            @ini_set('zlib.output_compression', '0');
            if (self::$flush) while (ob_get_level() > 0) @ob_end_flush();

            $send = function (string $chunk) {
                echo $chunk;
                if (self::$flush) @flush();
            };
            $send("retry: 500\n\n");

            $cursor = $after;
            $stop = microtime(true) + self::$seconds;
            $nextStatus = 0.0;
            $nextBeat = microtime(true) + 15;
            $said = null;

            while (microtime(true) < $stop) {
                $now = microtime(true);
                if ($now >= $nextStatus) {
                    $s->refresh();
                    LiveView::refresh($s);
                    $d = LiveView::describe($s);
                    $j = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($j !== $said) { $send("event: status\ndata: {$j}\n\n"); $said = $j; }
                    $nextStatus = $now + 1;
                    if (! $s->isOpen()) break;
                }

                if ($s->status === 'active') {
                    foreach (LiveView::batches($s, $cursor) as $n => $json) {
                        $send("id: {$n}\ndata: {$json}\n\n");
                        $cursor = $n;
                    }
                }

                if ($now >= $nextBeat) { $send(": beat\n\n"); $nextBeat = $now + 15; }
                if (connection_aborted()) break;
                usleep(100_000);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }
}
