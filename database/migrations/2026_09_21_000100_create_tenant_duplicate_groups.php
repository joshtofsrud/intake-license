<?php
// MARKER-DUP-MERGE — one row per set of items that are the same product.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_duplicate_groups')) {
            return;
        }
        Schema::create('tenant_duplicate_groups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->string('group_key', 40);          // sha1 of the sorted item ids
            $t->string('kind', 16);               // barcode | part_number
            $t->string('label', 191);             // what the copies share
            $t->string('status', 16)->default('ready'); // ready | review | merged | dismissed | failed
            $t->json('reasons')->nullable();      // why a person should look
            $t->uuid('keep_item_id')->nullable();
            $t->json('item_ids');
            $t->json('preview')->nullable();      // copies as they were when found
            $t->text('error')->nullable();
            $t->timestamp('found_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->uuid('resolved_by')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'group_key'], 'tdg_tenant_key_unique');
            $t->index(['tenant_id', 'status'], 'tdg_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_duplicate_groups');
    }
};
