<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * MARKER-EMAIL-OUTREACH: a saved prospect email. Subject and body may carry
 * [first_name] [shop] [city] [link]; [link] becomes that shop's own link to
 * the landing page picked when sending (page is only the default).
 */
class SalesEmailTemplate extends Model
{
    use HasUuids;

    protected $table = 'sales_email_templates';

    protected $fillable = ['name', 'subject', 'body', 'page', 'sort'];

    protected $casts = ['sort' => 'integer'];
}
