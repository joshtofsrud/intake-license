<?php

namespace App\Support;

use App\Exceptions\LockAcquisitionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MySQL advisory lock wrapper.
 *
 * Wraps GET_LOCK / RELEASE_LOCK so callers never write the SQL themselves.
 * Callback form is the primary API because it guarantees release-on-exception
 * and release-on-return — the kind of discipline that at 10K+ tenants is the
 * difference between "system works" and "MySQL connection pool exhausts under
 * load because someone forgot a finally block."
 *
 * Lock keys must be under 64 characters (MySQL 5.7+ constraint).
 * Convention: "intake:<tenant_id>:<scope>:<identifier>" — e.g.
 *   intake:tenant-abc:booking:2026-05-01-1400
 * Keys longer than 64 chars are hashed to fit.
 *
 * Advisory locks are CONNECTION-scoped in MySQL, not transaction-scoped.
 * This class holds locks on the default connection. Holding a lock across
 * a queued job or a new connection will not work — intentionally. Locks
 * should protect short synchronous critical sections, not span requests.
 */
class MySQLLock
{
    protected const MAX_KEY_LENGTH = 64;
    protected const DEFAULT_TIMEOUT_SECONDS = 5;

    /**
     * Run $work with the named lock held. Guarantees release on both
     * normal return and thrown exception. Primary API — prefer this.
     *
     * @template T
     * @param  callable(): T  $work
     * @return T
     *
     * @throws LockAcquisitionException  when the lock cannot be acquired within $timeoutSeconds
     */
    public function withLock(string $key, callable $work, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): mixed
    {
        $this->acquire($key, $timeoutSeconds);

        try {
            return $work();
        } finally {
            $this->release($key);
        }
    }

    /**
     * Imperative acquire. Callers MUST pair with release() in a try/finally.
     * Exists as an escape hatch for rare cases where callback form doesn't fit
     * (e.g. multi-step flows that span two HTTP round trips — which you probably
     * shouldn't be doing anyway, but the option is there).
     *
     * @throws LockAcquisitionException
     */
    public function acquire(string $key, int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): void
    {
        $normalized = $this->normalizeKey($key);

        // GET_LOCK returns 1 on acquired, 0 on timeout, NULL on error.
        $result = DB::selectOne(
            'SELECT GET_LOCK(?, ?) AS got',
            [$normalized, $timeoutSeconds]
        );

        if ((int) ($result->got ?? 0) !== 1) {
            throw new LockAcquisitionException(
                "Could not acquire lock '{$key}' within {$timeoutSeconds}s"
            );
        }
    }

    /**
     * Release a held lock. Idempotent in practice: releasing a lock that
     * isn't held by this connection is a no-op that MySQL reports as NULL,
     * which we log but don't throw on — at scale the debug noise of spurious
     * release failures would drown out real signal.
     */
    public function release(string $key): void
    {
        $normalized = $this->normalizeKey($key);

        try {
            $result = DB::selectOne(
                'SELECT RELEASE_LOCK(?) AS released',
                [$normalized]
            );

            $released = $result->released ?? null;

            // released === 1 : normal case, lock was held and is now freed
            // released === 0 : lock exists but was held by a DIFFERENT connection
            //                   (should never happen with this class — log it)
            // released === NULL : lock did not exist
            //                   (can happen on timeout paths — not worth alerting)
            if ($released === 0 || $released === '0') {
                Log::warning('MySQLLock::release — lock owned by another connection', [
                    'key'       => $key,
                    'normalized'=> $normalized,
                ]);
            }
        } catch (Throwable $e) {
            // Never let release failure propagate — that would mask the original
            // exception that the caller was handling. Log and swallow.
            Log::warning('MySQLLock::release threw', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalize the lock key to fit MySQL's 64-char limit.
     *
     * Strategy: if the key fits, use as-is (readable in slow logs).
     * If it's too long, sha1 it with a short prefix so debugging is still
     * possible — the prefix says "this was an intake lock" and the hash
     * is deterministic for the same input.
     */
    protected function normalizeKey(string $key): string
    {
        if (strlen($key) <= self::MAX_KEY_LENGTH) {
            return $key;
        }

        // 'intake:' (7) + sha1 (40) = 47 chars, well under 64.
        return 'intake:' . sha1($key);
    }
}
