<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-DASH-PEOPLE: a record of every time master admin moved people to a
 * different dashboard (by hand, or while retiring one), with what each person
 * had before, so a move can be undone. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dashboard_moves')) {
            return;
        }
        Schema::create('dashboard_moves', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_user_id')->nullable();
            $t->string('admin_name', 120)->nullable();
            $t->string('reason', 12)->default('move');       // move | retire
            $t->string('from_label', 190);
            $t->string('to_key', 16);
            $t->string('to_name', 80);
            $t->string('written', 16)->nullable();            // what dashboard_view was set to (null = follows the default)
            $t->unsignedInteger('people');
            $t->unsignedInteger('shops');
            $t->json('previous');                              // person id => what they had before (null = followed the default)
            $t->timestamp('undone_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_moves');
    }
};
