<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantCampaignImage extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'tenant_campaign_images';

    protected $fillable = [
        'tenant_id', 'filename', 'path', 'url', 'mime_type',
        'bytes', 'width', 'height', 'uploaded_by', 'created_at',
    ];

    protected $casts = [
        'bytes'      => 'integer',
        'width'      => 'integer',
        'height'     => 'integer',
        'created_at' => 'datetime',
    ];
}
