<?php
// MARKER-SALES-CORE

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A shop you might sell Intake to. Platform-level, lives next to Tenant in the
 * master admin. When a prospect converts, set tenant_id and the funnel/MRR
 * roll-ups become real numbers instead of estimates.
 */
class SalesProspect extends Model
{
    use HasUuids;

    protected $table = 'sales_prospects';

    protected $fillable = [
        'shop', 'city', 'state', 'region', 'loop', 'route_loop', 'type', 'primary_type',
        'priority', 'verified', 'business_status', 'lead_score', 'rating', 'rating_count',
        'stage', 'next_action_on', 'next_action', 'last_contacted_at', 'lost_reason',
        'tenant_id',
        'channel_id', 'categories', 'quote_tier', 'quote_addons', 'quote_monthly',
        'agency_id', 'sales_rep_id', // MARKER-AGENCIES-ATTR
        'owner_contact', 'phone', 'email', 'website', 'address',
        'best_ask', 'source', 'source_url', 'google_maps_url', 'notes',
        'google_place_id', 'lat', 'lng',
    ];

    protected $casts = [
        'loop'              => 'integer',
        'verified'          => 'boolean',
        'lead_score'        => 'integer',
        'rating'            => 'decimal:1',
        'rating_count'      => 'integer',
        'categories'        => 'array',
        'quote_addons'      => 'array',
        'quote_monthly'     => 'integer',
        'next_action_on'    => 'date',
        'last_contacted_at' => 'datetime',
        'lat'               => 'decimal:6',
        'lng'               => 'decimal:6',
    ];

    /** Ordered pipeline. Order matters — used for funnel cumulative counts. */
    public const STAGES = [
        'prospect'    => 'Prospect',
        'verifying'   => 'Verifying',
        'contacted'   => 'Contacted',
        'demo_booked' => 'Demo booked',
        'demo_done'   => 'Demo done',
        'trial'       => 'Trial',
        'won'         => 'Won',
        'lost'        => 'Lost',
    ];

    public const PRIORITIES = ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'];

    /** Google Places business_status values. null = not yet discovered via Places. */
    public const BUSINESS_STATUSES = [
        'OPERATIONAL'        => 'Operational',
        'CLOSED_TEMPORARILY' => 'Closed (temp)',
        'CLOSED_PERMANENTLY' => 'Closed (permanent)',
    ];

    /**
     * Columns the national Places import is allowed to refresh on an EXISTING row.
     * Everything not in here (stage, next_action*, tenant_id, verified, priority,
     * lead_score, human notes, email, owner_contact) is human-owned and never
     * touched by a re-import — so running the pipeline again never clobbers work.
     */
    public const DISCOVERY_COLUMNS = [
        'business_status', 'rating', 'rating_count', 'lat', 'lng',
        'google_maps_url', 'primary_type', 'address', 'google_place_id',
    ];

    /** The 9 Washington driving loops. Static reference — rarely changes. */
    public const LOOPS = [
        1 => 'Spokane / Inland NW',
        2 => 'Palouse / Tri-Cities / SE WA',
        3 => 'Central WA / Wenatchee / Methow',
        4 => 'North Sound / Bellingham / Skagit / Islands',
        5 => 'Seattle Core',
        6 => 'Eastside / I-90 Corridor',
        7 => 'South Sound / Olympia',
        8 => 'Kitsap / Olympic Peninsula / Coast',
        9 => 'Southwest WA / Columbia River',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // MARKER-CAMPAIGNS-QUOTE — channel + quote
    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(SalesAgency::class, 'agency_id');
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(SalesRep::class, 'sales_rep_id');
    }

    // MARKER-QUOTE-REALPRICING — priced from the platform's real sources.
    /** Reference rate; per-agency rates on sales_agencies supersede this. */
    public const COMMISSION_YEAR1 = 0.25;

    /**
     * Quote = tier base + add-ons, in whole dollars. Tiers come from
     * config('intake.plan_prices') (cents); add-ons from the `addons` table.
     * An add-on whose included_in_plans covers the chosen tier prices at +$0
     * — same rule FeatureAccessService applies.
     */
    public function computeQuoteMonthly(): ?int
    {
        if (! $this->quote_tier) {
            return null;
        }
        $plans = config('intake.plan_prices', []);
        if (! isset($plans[$this->quote_tier])) {
            return null;
        }
        $sum = (int) round(((int) $plans[$this->quote_tier]) / 100);

        $selected = (array) $this->quote_addons;
        if ($selected !== []) {
            $rows = DB::table('addons')
                ->whereIn('code', $selected)
                ->get(['code', 'price_cents', 'included_in_plans']);
            foreach ($rows as $a) {
                $included = in_array(
                    $this->quote_tier,
                    (array) json_decode($a->included_in_plans ?? '[]', true),
                    true
                );
                if (! $included) {
                    $sum += (int) round(((int) $a->price_cents) / 100);
                }
            }
        }
        return $sum;
    }

    protected static function booted(): void
    {
        // Snapshot-on-write: quote_monthly is always derived, never hand-set.
        static::saving(function (self $p) {
            $p->quote_monthly = $p->computeQuoteMonthly();
        });
    }

    public function activities(): HasMany
    {
        return $this->hasMany(SalesActivity::class)->latest('occurred_at');
    }

    public function scopeOpen($q) { return $q->whereNotIn('stage', ['won', 'lost']); }

    public function scopeOperational($q)
    {
        // OPERATIONAL or not-yet-discovered (null) — i.e. "not known to be closed".
        return $q->where(function ($w) {
            $w->whereNull('business_status')->orWhere('business_status', 'OPERATIONAL');
        });
    }

    public function isOperational(): bool
    {
        return $this->business_status === null || $this->business_status === 'OPERATIONAL';
    }

    public function businessStatusLabel(): ?string
    {
        return $this->business_status
            ? (self::BUSINESS_STATUSES[$this->business_status] ?? $this->business_status)
            : null;
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? ucfirst($this->stage);
    }

    public function stageIndex(): int
    {
        $i = array_search($this->stage, array_keys(self::STAGES), true);
        return $i === false ? 0 : (int) $i;
    }

    public function loopLabel(): ?string
    {
        return $this->loop ? (self::LOOPS[$this->loop] ?? null) : null;
    }

    /**
     * Move to a new stage and log it. If the new stage is won/lost we stamp
     * last_contacted_at; otherwise leave the work queue alone.
     */
    public function advanceTo(string $stage, ?string $note = null): void
    {
        $from = $this->stage;
        if ($from === $stage) {
            return;
        }
        $this->update(['stage' => $stage]);
        $this->activities()->create([
            'type'       => 'stage_change',
            'stage_from' => $from,
            'stage_to'   => $stage,
            'body'       => $note,
        ]);
    }
}
