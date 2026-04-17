<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DebugLog
 *
 * Row in the unified debug_logs table that backs the master admin debug
 * panel. Don't instantiate or call ->save() directly — use the
 * DebugLogService facade / helpers:
 *
 *   debug_log()->request(...)
 *   debug_log()->error($exception, $context)
 *   debug_log()->audit('settings_updated', $tenant, $changes)
 *   debug_log()->mail($recipient, $template, $context)
 *
 * That service handles severity, fingerprinting, correlation IDs, and
 * request context enrichment so call sites stay tiny.
 */
class DebugLog extends Model
{
    use HasUuids;

    public $timestamps = false; // only created_at, no updated_at

    protected $fillable = [
        'tenant_id',
        'channel',
        'severity',
        'event',
        'message',
        'actor_type', 'actor_id', 'actor_label',
        'subject_type', 'subject_id', 'subject_label',
        'method', 'route', 'path', 'host',
        'status_code', 'duration_ms', 'ip', 'user_agent',
        'recipient', 'template',
        'job_class', 'queue', 'attempts',
        'context',
        'is_resolved', 'resolved_at', 'resolved_by', 'resolution_note',
        'fingerprint', 'correlation_id',
        'created_at',
    ];

    protected $casts = [
        'context'     => 'array',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
        'created_at'  => 'datetime',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
        'attempts'    => 'integer',
    ];

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Polymorphic actor — could be a User (master admin) or a TenantUser.
     * We don't use Laravel's morphTo because actor_type stores a short
     * key ('user', 'tenant_user') rather than the full class name.
     */
    public function actor()
    {
        return match ($this->actor_type) {
            'user'        => User::find($this->actor_id),
            'tenant_user' => \App\Models\Tenant\TenantUser::find($this->actor_id),
            default       => null,
        };
    }

    // ----------------------------------------------------------------
    // Scopes — used by the Filament table and widgets
    // ----------------------------------------------------------------

    public function scopeChannel(Builder $q, string $channel): Builder
    {
        return $q->where('channel', $channel);
    }

    public function scopeSeverityAtLeast(Builder $q, string $severity): Builder
    {
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];
        $min    = array_search($severity, $levels, true);
        if ($min === false) return $q;
        $allowed = array_slice($levels, $min);
        return $q->whereIn('severity', $allowed);
    }

    public function scopeUnresolved(Builder $q): Builder
    {
        return $q->where('is_resolved', false);
    }

    public function scopeSince(Builder $q, \DateTimeInterface $when): Builder
    {
        return $q->where('created_at', '>=', $when);
    }

    public function scopeForTenant(Builder $q, string $tenantId): Builder
    {
        return $q->where('tenant_id', $tenantId);
    }

    // ----------------------------------------------------------------
    // Display helpers
    // ----------------------------------------------------------------

    /**
     * Color used for severity badges in the Filament table.
     */
    public function severityColor(): string
    {
        return match ($this->severity) {
            'critical' => 'danger',
            'error'    => 'danger',
            'warning'  => 'warning',
            'notice'   => 'info',
            'info'     => 'gray',
            'debug'    => 'gray',
            default    => 'gray',
        };
    }

    public function channelColor(): string
    {
        return match ($this->channel) {
            'error', 'impersonation' => 'danger',
            'auth', 'webhook'        => 'warning',
            'audit'                  => 'info',
            'mail', 'sms'            => 'primary',
            'request', 'job', 'api'  => 'gray',
            default                  => 'gray',
        };
    }

    /**
     * Does this log row likely have a stack trace / rich context worth expanding?
     */
    public function hasRichContext(): bool
    {
        return !empty($this->context) && (
            isset($this->context['trace']) ||
            isset($this->context['exception']) ||
            isset($this->context['changes']) ||
            isset($this->context['payload']) ||
            count($this->context) > 2
        );
    }
}
