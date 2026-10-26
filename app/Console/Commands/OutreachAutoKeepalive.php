<?php

namespace App\Console\Commands;

use App\Services\Sales\OutreachAutoSend;
use Illuminate\Console\Command;

/** MARKER-OUTREACH-AUTO: restarts auto-send's chain if a deploy or worker restart dropped it. */
class OutreachAutoKeepalive extends Command
{
    protected $signature = 'outreach:auto-keepalive';
    protected $description = 'Keep Email outreach auto-send going after a deploy or worker restart';

    public function handle(): int
    {
        OutreachAutoSend::keepAlive();

        return self::SUCCESS;
    }
}
