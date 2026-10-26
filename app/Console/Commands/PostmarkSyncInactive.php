<?php

namespace App\Console\Commands;

use App\Services\PostmarkInactive;
use Illuminate\Console\Command;

/** MARKER-POSTMARK-INACTIVE: pull Postmark's inactive-recipient list for each marketing stream. */
class PostmarkSyncInactive extends Command
{
    protected $signature = 'postmark:sync-inactive';
    protected $description = "Copy Postmark's inactive-recipient list for each marketing stream into Intake";

    public function handle(): int
    {
        $res = PostmarkInactive::syncAll();
        if (! $res) {
            $this->line('No marketing stream is set on Platform email: nothing to sync.');
        }
        foreach ($res as $stream => [$count, $error]) {
            $this->line($stream . ': ' . ($error ?? $count . ' inactive'));
        }

        return self::SUCCESS;
    }
}
