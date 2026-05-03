<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Tax (Path B onboarding)
            $table->decimal('default_tax_rate', 5, 3)->nullable()->after('id');
            $table->boolean('tax_services_default')->default(true)->after('default_tax_rate');
            $table->boolean('tax_supports_exempt')->default(false)->after('tax_services_default');

            // Card surcharge (Path 2)
            $table->boolean('passthrough_card_fees')->default(false)->after('tax_supports_exempt');
            $table->decimal('card_surcharge_percent', 4, 2)->nullable()->after('passthrough_card_fees');
            $table->string('card_surcharge_label')->default('Card processing fee')->after('card_surcharge_percent');
            $table->timestamp('surcharge_disclaimer_ack_at')->nullable()->after('card_surcharge_label');

            // Tips (full setup, off by default)
            $table->boolean('tips_enabled')->default(false)->after('surcharge_disclaimer_ack_at');
            $table->string('tip_default_method', 10)->nullable()->after('tips_enabled');
            $table->json('tip_default_options')->nullable()->after('tip_default_method');
            $table->boolean('tip_allow_custom')->default(true)->after('tip_default_options');
            $table->boolean('tip_attributable')->default(true)->after('tip_allow_custom');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'default_tax_rate',
                'tax_services_default',
                'tax_supports_exempt',
                'passthrough_card_fees',
                'card_surcharge_percent',
                'card_surcharge_label',
                'surcharge_disclaimer_ack_at',
                'tips_enabled',
                'tip_default_method',
                'tip_default_options',
                'tip_allow_custom',
                'tip_attributable',
            ]);
        });
    }
};
