<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Expand payment_status enum to include 'draft' and 'quote'.
        //    Raw SQL because Laravel's schema builder doesn't handle enum modification cleanly.
        DB::statement("
            ALTER TABLE tenant_sales
            MODIFY COLUMN payment_status
            ENUM('draft', 'quote', 'unpaid', 'partial', 'paid', 'refunded')
            NOT NULL DEFAULT 'unpaid'
        ");

        // 2. Make sale_number nullable (drafts and quotes don't get a number until commit).
        DB::statement("
            ALTER TABLE tenant_sales
            MODIFY COLUMN sale_number VARCHAR(20) NULL
        ");

        // 3. Add quote_expires_at for quote lifecycle.
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->timestamp('quote_expires_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        // Drop quote_expires_at first so we can shrink the enum without data loss.
        Schema::table('tenant_sales', function (Blueprint $table) {
            $table->dropColumn('quote_expires_at');
        });

        // Restore sale_number to NOT NULL — only safe if no draft/quote rows exist.
        // If rollback fails here, manually delete draft/quote rows first.
        DB::statement("
            ALTER TABLE tenant_sales
            MODIFY COLUMN sale_number VARCHAR(20) NOT NULL
        ");

        // Restore original enum. Will fail if any rows still have draft/quote status.
        DB::statement("
            ALTER TABLE tenant_sales
            MODIFY COLUMN payment_status
            ENUM('unpaid', 'partial', 'paid', 'refunded')
            NOT NULL DEFAULT 'unpaid'
        ");
    }
};
