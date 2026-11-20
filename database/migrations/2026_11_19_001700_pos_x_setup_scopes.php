<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-POS-TAB-V2: X-Series needs permission to read registers, staff and taxes
 * so a shop can choose what online sales are recorded with. Only widens the
 * shipped default; an edited value is left alone. Shops reconnect to pick it up.
 */
return new class extends Migration {
    private const WAS = 'retailer:read outlets:read payment_types:read products:read products:write inventory:read inventory:write customers:read customers:write sales:read sales:write webhooks';
    private const NOW = 'retailer:read outlets:read registers:read users:read taxes:read payment_types:read products:read products:write inventory:read inventory:write customers:read customers:write sales:read sales:write webhooks';

    public function up(): void
    {
        DB::table('platform_pos_adapters')->where('code', 'lightspeed-x')->where('scopes', self::WAS)->update(['scopes' => self::NOW]);
    }

    public function down(): void
    {
        DB::table('platform_pos_adapters')->where('code', 'lightspeed-x')->where('scopes', self::NOW)->update(['scopes' => self::WAS]);
    }
};
