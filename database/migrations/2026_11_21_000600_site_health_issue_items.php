<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-SITE-HEALTH: every item behind an item row on Site health ("212 items online have
 * no photo"), so the page can list all of them and not only the first few. Written by the
 * check as it reads items; a finished check clears the run before it. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_site_issue_items')) {
            Schema::create('tenant_site_issue_items', function (Blueprint $t) {
                $t->id();
                $t->uuid('tenant_id');
                $t->string('code', 40);          // items_no_photo | items_few_words
                $t->string('cat', 64);           // the category the row is for ('0' = none)
                $t->uuid('item_id');
                $t->unsignedSmallInteger('n')->default(0); // words in the description, for items_few_words
                $t->unsignedInteger('run');
                $t->index(['tenant_id', 'code', 'cat', 'run'], 'tsii_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_site_issue_items');
    }
};
