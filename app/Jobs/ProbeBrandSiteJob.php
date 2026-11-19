<?php

namespace App\Jobs;

use App\Services\Distributors\BrandSiteProbe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** MARKER-BRAND-BUILDER: Add a brand's look at a website, in the background so the page can show it live. */
class ProbeBrandSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Above the check's own budget (BrandSiteProbe::BUDGET_SECONDS), so it reports rather than being killed. */
    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public string $token, public string $url)
    {
        // MARKER-INTERACTIVE-QUEUE: quick jobs a person waits on get their own worker
        $this->onQueue('interactive');
    }

    public function handle(): void
    {
        (new BrandSiteProbe($this->token))->run($this->url);
    }

    public function failed(\Throwable $e): void
    {
        BrandSiteProbe::markFailed($this->token, $e instanceof \Illuminate\Queue\TimeoutExceededException
            ? 'The check ran out of time. The site may be slow or limiting requests; try again in a few minutes.'
            : 'The check stopped: ' . $e->getMessage());
    }
}
