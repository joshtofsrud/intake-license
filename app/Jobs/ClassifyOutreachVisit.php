<?php

namespace App\Jobs;

use App\Services\Sales\OutreachVisitJudge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-OUTREACH-SCANNERS: look up which network an email-link visit came
 * from (reverse DNS can take seconds, so not in the request), keep only that
 * name, and judge the visit again. The address lives only in this job.
 */
class ClassifyOutreachVisit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public int $visitId, public string $ip) {}

    public function handle(): void
    {
        $v = DB::table('sales_prospect_visits')->where('id', $this->visitId)->first(['id', 'sales_prospect_id']);
        if (! $v) return;
        DB::table('sales_prospect_visits')->where('id', $v->id)->update(['network' => OutreachVisitJudge::networkOf($this->ip)]);
        OutreachVisitJudge::rejudgeProspect((string) $v->sales_prospect_id);
    }
}
