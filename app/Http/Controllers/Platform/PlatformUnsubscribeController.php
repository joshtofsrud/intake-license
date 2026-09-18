<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformEmailOptout;
use Illuminate\Http\Request;

/**
 * MARKER-PLATFORM-EMAIL — stateless unsubscribe.
 *
 * The link carries the address and an APP_KEY HMAC. No DB token, nothing to
 * expire, nothing to clean up. A GET only ASKS — scanners and link-prefetchers
 * follow links, and one of them unsubscribing a reader on their behalf is a
 * real failure mode. The POST does the work, which is also what Gmail's
 * one-click header calls.
 */
class PlatformUnsubscribeController extends Controller
{
    public static function signature(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), config('app.key'));
    }

    public static function url(string $email): string
    {
        return route('platform.unsubscribe', [
            'e'   => rtrim(strtr(base64_encode(mb_strtolower(trim($email))), '+/', '-_'), '='),
            'sig' => substr(self::signature($email), 0, 32),
        ]);
    }

    protected function decode(string $e): ?string
    {
        $raw = base64_decode(strtr($e, '-_', '+/'), true);
        return $raw === false ? null : mb_strtolower(trim($raw));
    }

    public function show(Request $request, string $e, string $sig)
    {
        $email = $this->decode($e);
        abort_if(! $email || ! hash_equals(substr(self::signature($email), 0, 32), $sig), 404);

        return view('platform.unsubscribe', [
            'email' => $email,
            'e'     => $e,
            'sig'   => $sig,
            'done'  => PlatformEmailOptout::has($email),
        ]);
    }

    public function store(Request $request, string $e, string $sig)
    {
        $email = $this->decode($e);
        abort_if(! $email || ! hash_equals(substr(self::signature($email), 0, 32), $sig), 404);

        PlatformEmailOptout::updateOrCreate(
            ['email' => $email],
            ['reason' => 'link', 'source' => 'campaign']
        );

        return view('platform.unsubscribe', [
            'email' => $email, 'e' => $e, 'sig' => $sig, 'done' => true,
        ]);
    }
}
