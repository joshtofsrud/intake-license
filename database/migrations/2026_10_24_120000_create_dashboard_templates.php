<?php

use App\Support\Dashboard\DashboardTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-DASH-TEMPLATES: dashboard templates, phase 1.
 *
 * Overview, Tiles and Brief become templates made of blocks. Each is seeded
 * as v1 with exactly the blocks and order the view drew before, so no shop's
 * dashboard changes. Additive only: tenant_users.dashboard_view keeps holding
 * the picked view, and its values (overview / tiles / brief) are the template
 * keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('kind', 12)->default('data');
            $table->boolean('is_builtin')->default(false);
            $table->string('origin', 16)->nullable();
            $table->json('config')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dashboard_templates', function (Blueprint $table) {
            $table->id();
            // Stored in tenant_users.dashboard_view, which is 16 characters.
            $table->string('key', 16)->unique();
            $table->string('name', 80);
            $table->boolean('is_builtin')->default(false);
            $table->string('frame', 16)->default('overview');
            $table->string('state', 12)->default('draft');
            $table->string('switch_label', 40)->nullable();
            $table->boolean('beta_badge')->default(false);
            $table->string('icon', 16)->nullable();
            $table->string('rearrange', 8)->default('none');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort')->default(100);
            $table->unsignedBigInteger('live_version_id')->nullable();
            $table->json('draft_layout')->nullable();
            $table->json('audience')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dashboard_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_template_id')->constrained('dashboard_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('layout');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['dashboard_template_id', 'version'], 'dtv_template_version_unique');
        });

        DashboardTemplates::seedBuiltIns();
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_template_versions');
        Schema::dropIfExists('dashboard_templates');
        Schema::dropIfExists('dashboard_blocks');
    }
};
