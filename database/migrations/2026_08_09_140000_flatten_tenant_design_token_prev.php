<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MARKER-PREVFIX — one-time flatten of the recursive _prev chains already in
// the column. Keeps the top-level snapshot (the only one revert can reach) and
// discards the nested history underneath it.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->whereNotNull('design_tokens')
            ->orderBy('id')
            ->chunkById(100, function ($tenants) {
                foreach ($tenants as $tenant) {
                    $tokens = json_decode($tenant->design_tokens ?? 'null', true);
                    if (! is_array($tokens) || ! isset($tokens['_prev']) || ! is_array($tokens['_prev'])) {
                        continue;
                    }

                    $prev = $tokens['_prev'];

                    // The snapshot's OWN design_tokens is where the chain
                    // continues — strip its _prev and stop there.
                    if (isset($prev['design_tokens']) && is_array($prev['design_tokens'])) {
                        unset($prev['design_tokens']['_prev']);
                        if ($prev['design_tokens'] === []) {
                            $prev['design_tokens'] = null;
                        }
                    }

                    $tokens['_prev'] = $prev;

                    DB::table('tenants')->where('id', $tenant->id)
                        ->update(['design_tokens' => json_encode($tokens)]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by nature: the discarded history is gone. No-op rather
        // than pretending otherwise.
    }
};
