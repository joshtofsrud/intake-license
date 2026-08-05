<?php

// MARKER-QBP-CLS

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CLS image URL prefix belongs to ONE QBP account — it embeds that
 * retailer's Image Service ID. It therefore lives on the tenant's
 * subscription, never on the shared catalog, and one tenant's prefix must
 * never render on another's pages.
 *
 * Stored rather than fetched per request: the guide says this data changes
 * rarely, and refreshing it on every page view would be a call per image.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $t) {
            $t->string('cls_image_url', 255)->nullable()->after('credentials_encrypted');
            $t->json('cls_image_sizes')->nullable()->after('cls_image_url');
            $t->timestamp('cls_checked_at')->nullable()->after('cls_image_sizes');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_distributor_catalog_subscriptions', function (Blueprint $t) {
            $t->dropColumn(['cls_image_url', 'cls_image_sizes', 'cls_checked_at']);
        });
    }
};
