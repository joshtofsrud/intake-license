<?php
// MARKER-NAV-ORDER

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per navigation item that has been CUSTOMISED. Absence means
        // "use what the class declares" — never "hide it".
        Schema::create('admin_nav_items', function (Blueprint $table) {
            $table->id();
            $table->string('class', 191)->unique();   // the Filament page/resource
            $table->string('group', 60)->nullable();  // null = its declared group
            $table->string('label', 60)->nullable();  // null = its declared label
            $table->integer('sort')->default(0);
            $table->boolean('hidden')->default(false);
            $table->timestamps();
        });

        // Group order and renaming, keyed by the group's own name.
        Schema::create('admin_nav_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('label', 60)->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('collapsed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_nav_items');
        Schema::dropIfExists('admin_nav_groups');
    }
};
