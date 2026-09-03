<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// MARKER-BILLING-NOTICES — what a billing notice says. Editable, never hard-coded.
class BillingNoticeTemplate extends Model
{
    protected $table = 'billing_notice_templates';
    protected $primaryKey = 'event';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'event', 'label', 'send_alert', 'send_email', 'repeat_after_hours', 'subject', 'body',
    ];

    protected $casts = [
        'send_alert' => 'boolean',
        'send_email' => 'boolean',
    ];

    /** The placeholders a template may use, for the editor's help text. */
    public const TOKENS = [
        '{balance}'    => 'the unbilled balance, e.g. $18.40',
        '{amount}'     => 'the amount of this charge',
        '{messages}'   => 'how many messages it covered',
        '{card}'       => 'e.g. Visa ···· 4417',
        '{card_last4}' => 'just the last four digits',
        '{expires}'    => 'the card expiry, e.g. 09/2028',
        '{shop}'       => "the shop's name",
        '{link}'       => 'link to their payment method page',
    ];
}
