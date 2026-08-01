<?php

// MARKER-RENTAL-WAIVER-DISPLAY — signature evidence on rentals + a persistent
// display override on registers.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_rentals', function (Blueprint $t) {
            // The typed/drawn name. Until now this only survived in notes,
            // which meant the signed-state UI had nothing to show.
            $t->string('agreement_signer_name', 160)->nullable()->after('agreement_method');
            // Relative path on the public disk. Nullable: desk signings that
            // predate this patch (and the typed-name path) have no image.
            $t->string('agreement_signature_path')->nullable()->after('agreement_signer_name');
            // v6 addresses are 45 chars at worst.
            $t->string('agreement_signed_ip', 45)->nullable()->after('agreement_signature_path');
        });

        Schema::table('tenant_registers', function (Blueprint $t) {
            // null = normal cart mirroring. 'agreement' = waiver takes over.
            $t->string('display_mode', 20)->nullable()->after('cart_updated_at');
            $t->foreignUuid('display_rental_id')->nullable()->after('display_mode')
              ->constrained('tenant_rentals')->nullOnDelete();
            $t->timestamp('display_mode_at')->nullable()->after('display_rental_id');
            $t->string('display_sign_nonce', 64)->nullable()->after('display_mode_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_registers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('display_rental_id');
            $t->dropColumn(['display_mode', 'display_mode_at', 'display_sign_nonce']);
        });

        Schema::table('tenant_rentals', function (Blueprint $t) {
            $t->dropColumn([
                'agreement_signer_name',
                'agreement_signature_path',
                'agreement_signed_ip',
            ]);
        });
    }
};
