<?php

namespace App\Services;

use App\Models\DebugLog;
use App\Models\Tenant;
use App\Models\Tenant\TenantUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * DebugLogService — the single entry point for writing to the debug panel.
 *
 * Registered as a singleton in AppServiceProvider and bound to the
 * `debug_log` container key, so it can be reached via:
 *
 *   app('debug_log')->request(...)
 *   debug_log()->error($exception, ...)        // helper function
 *
 * All methods return the DebugLog row (or null if the channel is disabled),
 * so callers can attach extra context after the fact if they want.
 */
class DebugLogService
{
    /** Per-request correlation ID. Set by the request middleware. */
    protected ?string $correlationId = null;

    /** Cached request context so we don't rebuild it on every log call. */
    protected ?array $requestContext = null;

    // ================================================================
    // Setup
    // ================================================================

    public function setCorrelationId(string $id): void
    {
        $this->correlationId = $id;
    }

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * Called by LogRequests middleware at the start of a request so
     * every subsequent log line can inherit method/route/host/ip.
     */
    public function bindRequest(Request $request): void
    {
        $this->requestContext = [
            'method' => $request->method(),
            'route' => optional($request->route())->getName(),
            'path'  => '/' . ltrim($request->path(), '/'),
            'host'  => $request->getHost(),
            'ip'    => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
        ];
    }

    // ================================================================
    // Public channel methods — one per channel
    // ================================================================

    /**
     * Log a completed HTTP request. Called from LogRequests middleware
     * in its terminate() hook.
     */
    public function request(Request $request, int $statusCode, int $durationMs, ?string $note = null): ?DebugLog
    {
        if (! $this->channelEnabled('request')) return null;

        $isError = $statusCode >= 500;
        $isSlow  = $durationMs >= (int) config('debug.request.slow_threshold_ms', 1500);
        $severity = $isError ? 'error' : ($isSlow ? 'notice' : 'info');

        // Sampling: skip info-level rows when sample rate is below 1.0
        $sample = (float) config('debug.request.sample_rate', 1.0);
        if ($severity === 'info' && $sample < 1.0 && mt_rand() / mt_getrandmax() > $sample) {
            return null;
        }

        $message = $request->method() . ' ' . $request->path() . ' → ' . $statusCode
            . ' (' . $durationMs . 'ms)'
            . ($note ? ' — ' . $note : '');

        return $this->write('request', 'request.completed', $message, [
            'severity'    => $severity,
            'method'      => $request->method(),
            'route'       => optional($request->route())->getName(),
            'path'        => '/' . ltrim($request->path(), '/'),
            'host'        => $request->getHost(),
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'ip'          => $request->ip(),
            'user_agent'  => Str::limit((string) $request->userAgent(), 490, ''),
            'context'     => $isError || $isSlow ? [
                'query'  => $this->redact($request->query()),
                'input'  => $this->redact($request->except([])),
            ] : null,
        ]);
    }

    /**
     * Log an unhandled exception. Called from the exception handler hook
     * in bootstrap/app.php.
     */
    public function error(Throwable $e, array $extra = []): ?DebugLog
    {
        if (! $this->channelEnabled('error')) return null;

        $fingerprint = substr(hash('sha256',
            get_class($e) . '|' . $e->getFile() . '|' . $e->getLine()
        ), 0, 32);

        return $this->write('error', 'error.uncaught', Str::limit($e->getMessage() ?: get_class($e), 490, ''), [
            'severity'    => 'error',
            'fingerprint' => $fingerprint,
            'context'     => array_merge([
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $this->trimTrace($e),
                'previous'  => $e->getPrevious() ? [
                    'exception' => get_class($e->getPrevious()),
                    'message'   => $e->getPrevious()->getMessage(),
                    'file'      => $e->getPrevious()->getFile(),
                    'line'      => $e->getPrevious()->getLine(),
                ] : null,
            ], $extra),
        ]);
    }

    /**
     * Log a sent email. Wire this from the MessageSent Mail event listener.
     */
    public function mail(string $recipient, string $template, array $context = [], string $severity = 'info'): ?DebugLog
    {
        if (! $this->channelEnabled('mail')) return null;

        return $this->write('mail', 'mail.sent', 'Sent ' . $template . ' to ' . $recipient, [
            'severity'  => $severity,
            'recipient' => Str::limit($recipient, 250, ''),
            'template'  => Str::limit($template, 120, ''),
            'context'   => $context,
        ]);
    }

    public function mailFailed(string $recipient, string $template, string $reason, array $context = []): ?DebugLog
    {
        if (! $this->channelEnabled('mail')) return null;

        return $this->write('mail', 'mail.failed', 'Failed ' . $template . ' to ' . $recipient . ' — ' . Str::limit($reason, 200), [
            'severity'  => 'error',
            'recipient' => Str::limit($recipient, 250, ''),
            'template'  => Str::limit($template, 120, ''),
            'context'   => array_merge(['reason' => $reason], $context),
        ]);
    }

    public function sms(string $recipient, string $event, string $message, array $context = [], string $severity = 'info'): ?DebugLog
    {
        if (! $this->channelEnabled('sms')) return null;

        return $this->write('sms', 'sms.' . $event, $message, [
            'severity'  => $severity,
            'recipient' => Str::limit($recipient, 50, ''),
            'context'   => $context,
        ]);
    }

    /**
     * Auth event: login success, failure, logout, password reset, lockout.
     */
    public function auth(string $event, string $message, array $context = [], string $severity = 'info'): ?DebugLog
    {
        if (! $this->channelEnabled('auth')) return null;

        return $this->write('auth', 'auth.' . $event, $message, [
            'severity' => $severity,
            'context'  => $context,
        ]);
    }

    /**
     * Impersonation start/stop. Always kept at 'notice' severity because
     * these rows are rare and always worth seeing.
     */
    public function impersonation(string $event, Tenant $tenant, ?TenantUser $target, array $context = []): ?DebugLog
    {
        if (! $this->channelEnabled('impersonation')) return null;

        $message = $event === 'start'
            ? 'Impersonation started: ' . $tenant->name . ($target ? ' as ' . $target->email : '')
            : 'Impersonation ended: ' . $tenant->name;

        return $this->write('impersonation', 'impersonation.' . $event, $message, [
            'severity'      => 'notice',
            'tenant_id'     => $tenant->id,
            'subject_type'  => 'tenant',
            'subject_id'    => $tenant->id,
            'subject_label' => $tenant->name . ' (' . $tenant->subdomain . ')',
            'context'       => array_merge([
                'tenant_subdomain' => $tenant->subdomain,
                'target_user_id'   => $target?->id,
                'target_email'     => $target?->email,
            ], $context),
        ]);
    }

    /**
     * Audit event: tenant admin changed something meaningful.
     * Use from service layer after writes that matter.
     */
    public function audit(string $event, string $message, $subject = null, array $changes = []): ?DebugLog
    {
        if (! $this->channelEnabled('audit')) return null;

        [$subjectType, $subjectId, $subjectLabel] = $this->resolveSubject($subject);

        return $this->write('audit', 'audit.' . $event, $message, [
            'severity'      => 'info',
            'subject_type'  => $subjectType,
            'subject_id'    => $subjectId,
            'subject_label' => $subjectLabel,
            'context'       => $changes ? ['changes' => $changes] : null,
        ]);
    }

    /**
     * Queue job event: processed, failed, retrying.
     */
    public function job(string $event, string $jobClass, string $queue, ?int $attempts, array $context = [], string $severity = 'info'): ?DebugLog
    {
        if (! $this->channelEnabled('job')) return null;

        $message = 'Job ' . $event . ': ' . class_basename($jobClass)
            . ' [' . $queue . ']' . ($attempts !== null ? ' attempt ' . $attempts : '');

        return $this->write('job', 'job.' . $event, $message, [
            'severity'  => $severity,
            'job_class' => $jobClass,
            'queue'     => $queue,
            'attempts'  => $attempts,
            'context'   => $context,
        ]);
    }

    /**
     * Incoming webhook. Use from webhook controllers.
     */
    public function webhook(string $source, string $event, array $payload = [], string $severity = 'info'): ?DebugLog
    {
        if (! $this->channelEnabled('webhook')) return null;

        return $this->write('webhook', 'webhook.' . $source . '.' . $event, 'Webhook ' . $source . ' — ' . $event, [
            'severity' => $severity,
            'context'  => ['source' => $source, 'payload' => $this->redact($payload)],
        ]);
    }

    /**
     * Generic write for anything not covered above.
     */
    public function system(string $event, string $message, array $context = [], string $severity = 'info'): ?DebugLog
    {
        if (! $this->channelEnabled('system')) return null;

        return $this->write('system', $event, $message, [
            'severity' => $severity,
            'context'  => $context,
        ]);
    }

    // ================================================================
    // Core writer
    // ================================================================

    /**
     * Build and persist the row. Every public method funnels through here
     * so enrichment (actor, tenant, request context, correlation id) happens
     * exactly once.
     */
    protected function write(string $channel, string $event, string $message, array $attrs = []): ?DebugLog
    {
        if (! config('debug.enabled', true)) return null;

        try {
            $row = new DebugLog();
            $row->id             = (string) Str::uuid();
            $row->channel        = $channel;
            $row->event          = $event;
            $row->message        = Str::limit($message, 490, '');
            $row->severity       = $attrs['severity'] ?? 'info';
            $row->correlation_id = $this->correlationId;
            $row->created_at     = now();

            // Tenant — prefer explicit from attrs, fall back to resolved tenant.
            $row->tenant_id = $attrs['tenant_id'] ?? $this->resolveTenantId();

            // Actor — prefer explicit, fall back to authed user on either guard.
            [$actorType, $actorId, $actorLabel] = $this->resolveActor(
                $attrs['actor_type'] ?? null,
                $attrs['actor_id'] ?? null,
                $attrs['actor_label'] ?? null,
            );
            $row->actor_type  = $actorType;
            $row->actor_id    = $actorId;
            $row->actor_label = $actorLabel;

            // Subject
            $row->subject_type  = $attrs['subject_type'] ?? null;
            $row->subject_id    = $attrs['subject_id'] ?? null;
            $row->subject_label = isset($attrs['subject_label']) ? Str::limit($attrs['subject_label'], 250, '') : null;

            // Request context — use attrs if provided, else inherit from bound request.
            $rc = $this->requestContext ?? [];
            $row->method      = $attrs['method']      ?? ($rc['method']      ?? null);
            $row->route       = $attrs['route']       ?? ($rc['route']       ?? null);
            $row->path        = $attrs['path']        ?? ($rc['path']        ?? null);
            $row->host        = $attrs['host']        ?? ($rc['host']        ?? null);
            $row->ip          = $attrs['ip']          ?? ($rc['ip']          ?? null);
            $row->user_agent  = $attrs['user_agent']  ?? ($rc['user_agent']  ?? null);
            $row->status_code = $attrs['status_code'] ?? null;
            $row->duration_ms = $attrs['duration_ms'] ?? null;

            // Channel-specific fields
            $row->recipient   = $attrs['recipient']   ?? null;
            $row->template    = $attrs['template']    ?? null;
            $row->job_class   = $attrs['job_class']   ?? null;
            $row->queue       = $attrs['queue']       ?? null;
            $row->attempts    = $attrs['attempts']    ?? null;
            $row->fingerprint = $attrs['fingerprint'] ?? null;

            $row->context = $attrs['context'] ?? null;

            $row->save();
            return $row;
        } catch (Throwable $e) {
            // A failing log write must never break a user request or another
            // log write. Fall back to Laravel's log file and move on.
            try {
                \Illuminate\Support\Facades\Log::error('DebugLogService.write failed: ' . $e->getMessage(), [
                    'original_channel' => $channel,
                    'original_event'   => $event,
                ]);
            } catch (Throwable $_) {
                // give up quietly
            }
            return null;
        }
    }

    // ================================================================
    // Helpers
    // ================================================================

    protected function channelEnabled(string $channel): bool
    {
        if (! config('debug.enabled', true)) return false;
        return (bool) config("debug.channels.$channel", true);
    }

    protected function resolveTenantId(): ?string
    {
        try {
            $t = app()->bound('tenant') ? app('tenant') : null;
            return $t?->id;
        } catch (Throwable $_) {
            return null;
        }
    }

    protected function resolveActor(?string $type, ?string $id, ?string $label): array
    {
        if ($type && $id) {
            return [$type, $id, $label];
        }

        // Master admin
        if ($u = Auth::guard('web')->user()) {
            /** @var User $u */
            return ['user', (string) $u->getKey(),
                trim(($u->name ?? 'Admin') . ' <' . ($u->email ?? '') . '>'),
            ];
        }

        // Tenant staff
        if ($u = Auth::guard('tenant')->user()) {
            /** @var TenantUser $u */
            return ['tenant_user', (string) $u->getKey(),
                trim(($u->name ?? 'User') . ' <' . ($u->email ?? '') . '>'),
            ];
        }

        return [null, null, null];
    }

    protected function resolveSubject($subject): array
    {
        if (! $subject) return [null, null, null];
        if (is_array($subject)) {
            return [$subject['type'] ?? null, $subject['id'] ?? null, $subject['label'] ?? null];
        }
        if (is_object($subject)) {
            $type = class_basename($subject);
            $id   = method_exists($subject, 'getKey') ? (string) $subject->getKey() : null;
            $label = $subject->name ?? $subject->email ?? $subject->title ?? null;
            return [strtolower($type), $id, $label];
        }
        return [null, null, null];
    }

    /**
     * Redact sensitive fields from a payload before storing.
     */
    protected function redact(array $data): array
    {
        $keys = (array) config('debug.request.redact_keys', []);
        $out  = [];
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), $keys, true)) {
                $out[$k] = '[REDACTED]';
            } elseif (is_array($v)) {
                $out[$k] = $this->redact($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Trim a PHP stack trace so the JSON column doesn't balloon on deep stacks.
     */
    protected function trimTrace(Throwable $e, int $depth = 25): array
    {
        $trace = $e->getTrace();
        $out = [];
        foreach (array_slice($trace, 0, $depth) as $frame) {
            $out[] = ($frame['file'] ?? '?')
                . ':' . ($frame['line'] ?? '?')
                . ' — '
                . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
        }
        return $out;
    }
}
