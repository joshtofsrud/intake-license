<?php
// MARKER-BG-IMPORT: a shop list being loaded on the server, so the page can be closed.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sales_import_runs')) return;
        Schema::create('sales_import_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('batch', 60);
            $t->string('file_name', 191)->nullable();
            $t->string('path', 255);
            $t->unsignedBigInteger('pos')->default(0);
            $t->unsignedBigInteger('size')->default(0);
            $t->json('map')->nullable();
            $t->boolean('assign')->default(true);
            $t->string('channel_id', 36)->nullable();
            $t->unsignedInteger('expect')->default(0);
            foreach (['total', 'inserted', 'matched', 'blank', 'assigned', 'with_coords'] as $c) $t->unsignedInteger($c)->default(0);
            $t->string('status', 16)->default('queued'); // queued | running | done | failed
            $t->string('error', 255)->nullable();
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_import_runs');
    }
};
