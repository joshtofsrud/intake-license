<?php
namespace App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantCampaignSend extends Model
{
    use HasUuids;
    public    $timestamps = false;
    protected $table      = 'tenant_campaign_sends';
    protected $fillable   = [
        'campaign_id','customer_id','email','status','tracking_token',
        'open_count','click_count','sent_at','opened_at','clicked_at','error_message','created_at',
        'bounced_at','bounce_type','complained_at','unsubscribed_at', // MARKER-SHOP-CAMPAIGN-REPORT
    ];
    protected $casts = ['sent_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime', 'created_at' => 'datetime',
        'bounced_at' => 'datetime', 'complained_at' => 'datetime', 'unsubscribed_at' => 'datetime'];

    /**
     * MARKER-SHOP-CAMPAIGN-REPORT: stamp a bounce, complaint or unsubscribe on
     * the campaign email that caused it. Postmark names the send when it can;
     * otherwise (the unsubscribe page knows only the customer) it goes on the
     * newest campaign email this shop sent that person in the last 60 days.
     * First stamp wins, so a replayed webhook changes nothing.
     */
    public static function stampOutcome(string $field, ?string $sendId = null, ?string $tenantId = null, ?string $customerId = null, ?string $email = null, array $extra = []): void
    {
        if (! in_array($field, ['bounced_at', 'complained_at', 'unsubscribed_at'], true)) return;
        try {
            $row = $sendId ? static::find($sendId) : null;
            if (! $row && $tenantId && ($customerId || $email)) {
                $row = static::query()
                    ->join('tenant_campaigns as c', 'c.id', '=', 'tenant_campaign_sends.campaign_id')
                    ->where('c.tenant_id', $tenantId)
                    ->where('tenant_campaign_sends.status', 'sent')
                    ->where('tenant_campaign_sends.sent_at', '>=', now()->subDays(60))
                    ->when($customerId, fn ($q) => $q->where('tenant_campaign_sends.customer_id', $customerId),
                                        fn ($q) => $q->whereRaw('LOWER(tenant_campaign_sends.email) = ?', [mb_strtolower(trim((string) $email))]))
                    ->orderByDesc('tenant_campaign_sends.sent_at')
                    ->select('tenant_campaign_sends.*')->first();
            }
            if (! $row || $row->{$field}) return;
            $row->forceFill([$field => now()] + $extra)->save();
        } catch (\Throwable $e) {
            logger()->warning('Shop campaign outcome not recorded', ['field' => $field, 'error' => $e->getMessage()]);
        }
    }
}
