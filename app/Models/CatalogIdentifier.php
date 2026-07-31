<?php

// MARKER-CATALOG-IDENTIFIERS

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogIdentifier extends Model
{
    protected $fillable = [
        'distributor_catalog_id', 'distributor_code',
        'identifier_type', 'value_norm',
    ];

    public const TYPE_UPC = 'upc';
    public const TYPE_EAN = 'ean';
    public const TYPE_MPN = 'mpn';

    public function catalogRow()
    {
        return $this->belongsTo(PlatformDistributorCatalog::class, 'distributor_catalog_id');
    }
}
