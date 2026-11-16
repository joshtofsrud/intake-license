<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** rules over a source, never a fixed list of people. */
class PlatformAudience extends Model
{
    use HasUuids;

    protected $table    = 'platform_audiences';
    protected $fillable = ['name', 'source', 'rules'];
    protected $casts    = ['rules' => 'array'];

    /** The only sources that exist. Shop customers are absent on purpose. */
    public const SOURCES = [
        'tenants'       => 'Tenants',
        'tenant_owners' => 'Tenant owners',
        'prospects'     => 'Prospects',
        'wrote_in'      => 'Wrote in',
        'reps'          => 'Reps & agencies',
        'investors'     => 'Investors',
        'inv_leads'     => 'Investor mailing list',
    ];

    /** One line under the Who choices saying who each source is. */
    public const SOURCE_HELP = [
        'tenants'       => 'Every shop on Intake, at the shop\'s notification email (the owner\'s login if it has none).',
        'tenant_owners' => 'The owner of each shop on Intake, at their own login email.',
        'prospects'     => 'Shops in your prospect list that asked to hear from you (booked a call or contacted you first).',
        'wrote_in'      => 'People who contacted Intake themselves, through the site, or replied to an email you sent.',
        'reps'          => 'Your sales reps and agencies.',
        'investors'     => 'Everyone in your raise except those who declined. For investor updates only.',
        'inv_leads'     => 'People who signed up on the site for investor news.',
    ];
}
