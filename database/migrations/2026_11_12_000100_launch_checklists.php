<?php

use App\Support\Launch\LaunchDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * MARKER-LAUNCH-CHECKLIST: launch checklists for the online store and rentals.
 *
 * launch_checklists      one row per module: how the checklist behaves (master admin)
 * launch_steps           the steps, editable in master admin
 * tenant_launch_states   per shop and module: preview / live / paused, plus what the last check saw
 * tenant_launch_marks    a shop's ticks and skips, and Intake's waivers
 * tenant_launch_events   the history shown on the shop's page in master admin
 *
 * Shops already selling or booking when this ships start as live, so nothing
 * they run today changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('launch_checklists')) {
            Schema::create('launch_checklists', function (Blueprint $t) {
                $t->string('module', 16)->primary();
                $t->json('settings')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('launch_steps')) {
            Schema::create('launch_steps', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('module', 16);
                $t->string('key', 40);
                $t->string('check', 40);              // a LaunchChecks key, or 'confirm'
                $t->string('group_name', 60);
                $t->string('title', 120);
                $t->string('explain', 400)->nullable();
                $t->string('how', 600)->nullable();
                $t->string('level', 4)->default('req'); // req | rec | opt
                $t->boolean('pause_on_fail')->default(false);
                $t->string('help_key', 64)->nullable();
                $t->string('min_tier', 16)->default('starter');
                $t->boolean('enabled')->default(true);
                $t->unsignedSmallInteger('sort')->default(100);
                $t->timestamps();
                $t->unique(['module', 'key'], 'ls_module_key');
            });
        }

        if (! Schema::hasTable('tenant_launch_states')) {
            Schema::create('tenant_launch_states', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $t->string('module', 16);
                $t->string('state', 12)->default('preview'); // preview | live | paused
                $t->timestamp('live_since')->nullable();
                $t->boolean('grandfathered')->default(false);
                $t->boolean('forced_live')->default(false);
                $t->string('forced_reason', 300)->nullable();
                $t->timestamp('paused_at')->nullable();
                $t->string('pause_step', 40)->nullable();    // null + paused = paused by Intake by hand
                $t->string('pause_reason', 300)->nullable();
                $t->json('last')->nullable();                // step key => ok|warn|bad|na at the last check
                $t->json('passed_at')->nullable();           // step key => first time it passed
                $t->json('broken')->nullable();              // step key => when it stopped passing on a live shop
                $t->timestamp('checked_at')->nullable();
                $t->unsignedTinyInteger('reminders')->default(0);
                $t->timestamp('reminded_at')->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'module'], 'tls_tenant_module');
                $t->index(['module', 'state'], 'tls_module_state');
            });
        }

        if (! Schema::hasTable('tenant_launch_marks')) {
            Schema::create('tenant_launch_marks', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $t->string('module', 16);
                $t->string('step_key', 40);
                $t->string('kind', 12);                       // confirmed | skipped | waived
                $t->string('reason', 300)->nullable();
                $t->string('by_name', 120)->nullable();
                $t->timestamps();
                $t->unique(['tenant_id', 'module', 'step_key'], 'tlm_tenant_step');
            });
        }

        if (! Schema::hasTable('tenant_launch_events')) {
            Schema::create('tenant_launch_events', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $t->string('module', 16);
                $t->string('message', 500);
                $t->timestamp('created_at')->nullable();
                $t->index(['tenant_id', 'module', 'created_at'], 'tle_tenant_module_at');
            });
        }

        $now = now();

        foreach (LaunchDefaults::behaviours() as $module => $settings) {
            if (! DB::table('launch_checklists')->where('module', $module)->exists()) {
                DB::table('launch_checklists')->insert(['module' => $module, 'settings' => json_encode($settings), 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        foreach (LaunchDefaults::steps() as $module => $steps) {
            $sort = 10;
            foreach ($steps as $s) {
                if (DB::table('launch_steps')->where('module', $module)->where('key', $s['key'])->exists()) { $sort += 10; continue; }
                DB::table('launch_steps')->insert([
                    'id' => (string) Str::uuid(), 'module' => $module, 'key' => $s['key'], 'check' => $s['check'],
                    'group_name' => $s['group'], 'title' => $s['title'], 'explain' => $s['explain'], 'how' => $s['how'],
                    'level' => $s['level'], 'pause_on_fail' => (bool) ($s['pause'] ?? false), 'help_key' => $s['help'] ?? null,
                    'min_tier' => $s['tier'] ?? 'starter', 'enabled' => true, 'sort' => $sort,
                    'created_at' => $now->copy()->subMinute(), 'updated_at' => $now,
                ]);
                $sort += 10;
            }
        }

        // Every shop whose store or rentals page is public today starts as live,
        // so nothing a visitor can see changes when this ships. The checklist is
        // then a health view for them.
        \App\Models\Tenant::query()->orderBy('id')->chunk(100, function ($tenants) use ($now) {
            foreach ($tenants as $tenant) {
                $s = (array) ($tenant->settings ?? []);
                $live = [];
                try {
                    if ($tenant->online_store_enabled && (bool) (($s['storefront']['enabled'] ?? true))) $live[] = 'ecom';
                } catch (\Throwable $e) {
                    report($e);
                }
                try {
                    if ($tenant->rentals_visible) $live[] = 'rent';
                } catch (\Throwable $e) {
                    report($e);
                }
                $r = (object) ['id' => $tenant->id];
                foreach ($live as $m) {
                    if (DB::table('tenant_launch_states')->where('tenant_id', $r->id)->where('module', $m)->exists()) continue;
                    DB::table('tenant_launch_states')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $r->id, 'module' => $m, 'state' => 'live',
                        'live_since' => $now, 'grandfathered' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('tenant_launch_events')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $r->id, 'module' => $m,
                        'message' => 'Started as live: the shop was already ' . ($m === 'ecom' ? 'selling online' : 'taking rentals') . ' when checklists arrived.',
                        'created_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_launch_events');
        Schema::dropIfExists('tenant_launch_marks');
        Schema::dropIfExists('tenant_launch_states');
        Schema::dropIfExists('launch_steps');
        Schema::dropIfExists('launch_checklists');
    }
};
