<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes the user FKs on the POS migrations from migrations 5 and 6.
 *
 * Original migrations referenced platform `users` table (bigint id) by
 * mistake. The actual user creating a movement at the register or
 * committing a shipment is a `tenant_users` row (UUID id).
 *
 * Safe to run because no rows exist in either table yet — POS code
 * isn't built yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        // tenant_inventory_movements.user_id : drop FK + bigint column, add UUID + new FK
        Schema::table('tenant_inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('tenant_inventory_movements', function (Blueprint $table) {
            $table->foreignUuid('tenant_user_id')
                ->nullable()
                ->after('notes')
                ->constrained(table: 'tenant_users', indexName: 'tim_tenant_user_fk')
                ->nullOnDelete();
        });

        // tenant_inventory_receive_shipments.created_by_user_id and .committed_by_user_id
        Schema::table('tenant_inventory_receive_shipments', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropForeign(['committed_by_user_id']);
            $table->dropColumn(['created_by_user_id', 'committed_by_user_id']);
        });

        Schema::table('tenant_inventory_receive_shipments', function (Blueprint $table) {
            $table->foreignUuid('created_by_tenant_user_id')
                ->nullable()
                ->after('notes')
                ->constrained(table: 'tenant_users', indexName: 'tirs_created_by_fk')
                ->nullOnDelete();
            $table->foreignUuid('committed_by_tenant_user_id')
                ->nullable()
                ->after('created_by_tenant_user_id')
                ->constrained(table: 'tenant_users', indexName: 'tirs_committed_by_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_inventory_movements', function (Blueprint $table) {
            $table->dropForeign('tim_tenant_user_fk');
            $table->dropColumn('tenant_user_id');
        });

        Schema::table('tenant_inventory_movements', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('notes')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('tenant_inventory_receive_shipments', function (Blueprint $table) {
            $table->dropForeign('tirs_created_by_fk');
            $table->dropForeign('tirs_committed_by_fk');
            $table->dropColumn(['created_by_tenant_user_id', 'committed_by_tenant_user_id']);
        });

        Schema::table('tenant_inventory_receive_shipments', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->after('notes')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('committed_by_user_id')
                ->nullable()
                ->after('created_by_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }
};
