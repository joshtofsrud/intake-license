<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MARKER-SHOP-SEARCH-V2: what every shop's search starts with. Set in master admin
 * (Search defaults). A shop can switch one off for itself; it cannot edit it.
 *   type synonym: from_term <-> to_value, both ways
 *   type stop:    from_term is ignored when it is what stops a search matching
 */
class SearchDefault extends Model
{
    protected $table = 'search_defaults';
    protected $fillable = ['type', 'from_term', 'to_value', 'enabled', 'sort'];
    protected $casts = ['enabled' => 'boolean', 'sort' => 'integer'];
}
