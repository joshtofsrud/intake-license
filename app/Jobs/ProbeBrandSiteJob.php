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

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public string $token, public string $url) {}

    public function handle(): void
    {
        (new BrandSiteProbe($this->token))->run($this->url);
    }
}
