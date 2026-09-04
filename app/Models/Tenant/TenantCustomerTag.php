<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * MARKER-CUSTOMER-TAGS — a label a shop puts on people: an imported list, a
 * race team, wholesale accounts.
 *
 * Says nothing about permission to market. A tag is who someone is; consent is
 * what you may send them, and the two are deliberately separate so tagging a
 * list can never be mistaken for being allowed to mail it.
 */
class TenantCustomerTag extends Model
{
    use HasUuids;

    protected $table = 'tenant_customer_tags';

    protected $fillable = ['tenant_id', 'name', 'description', 'created_by'];

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(
            TenantCustomer::class,
            'tenant_customer_tag_pivot',
            'tag_id',
            'customer_id'
        )->withTimestamps();
    }

    public function customerCount(): int
    {
        return \Illuminate\Support\Facades\DB::table('tenant_customer_tag_pivot')
            ->where('tag_id', $this->id)->count();
    }

    /** Find or make, so a second import never creates a duplicate tag. */
    public static function findOrCreateFor(string $tenantId, string $name, ?string $by = null): self
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        return static::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => $name],
            ['created_by' => $by]
        );
    }
}
