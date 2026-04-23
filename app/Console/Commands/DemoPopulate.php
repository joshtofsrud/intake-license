<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Demo\DemoSeeder;
use App\Services\Demo\Industries\BikeShopData;
use App\Services\Demo\Industries\IndustryDataContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoPopulate extends Command
{
    protected $signature = 'demo:populate
                            {subdomain : Subdomain for the demo tenant, e.g. "blueridge"}
                            {industry : Industry slug, e.g. "bike-shops"}
                            {--name= : Override the default shop name}
                            {--owner-name= : Display name for the demo owner user (default: "[Shop Name] Owner")}
                            {--owner-email=owner@demo.intake.works : Email for the demo owner user}
                            {--owner-password=demo-password-123 : Password for the demo owner user}
                            {--fresh : If the subdomain exists, destroy it and rebuild}';

    protected $description = 'Populate a demo tenant with industry-specific services, customers, and appointments.';

    private const INDUSTRY_MAP = [
        'bike-shops' => BikeShopData::class,
    ];

    public function handle(): int
    {
        $subdomain = strtolower(trim($this->argument('subdomain')));
        $industrySlug = strtolower(trim($this->argument('industry')));

        if (!isset(self::INDUSTRY_MAP[$industrySlug])) {
            $this->error("Unknown industry: {$industrySlug}");
            $this->line("Available: " . implode(', ', array_keys(self::INDUSTRY_MAP)));
            return self::FAILURE;
        }

        /** @var IndustryDataContract $industry */
        $industry = new (self::INDUSTRY_MAP[$industrySlug])();

        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/', $subdomain)) {
            $this->error("Invalid subdomain: {$subdomain}");
            $this->line("Must be lowercase alphanumeric with optional hyphens, 3-32 chars.");
            return self::FAILURE;
        }

        if (str_starts_with($subdomain, '__') || in_array($subdomain, ['admin', 'www'], true)) {
            $this->error("Reserved subdomain: {$subdomain}");
            return self::FAILURE;
        }

        $existing = Tenant::where('subdomain', $subdomain)->first();
        if ($existing) {
            if (!$this->option('fresh')) {
                $this->error("Tenant with subdomain '{$subdomain}' already exists. Use --fresh to rebuild.");
                return self::FAILURE;
            }
            $this->warn("Dropping existing tenant: {$existing->id} ({$subdomain})");
            if (!$this->confirm("This will permanently delete all data for this tenant. Continue?", false)) {
                $this->info("Aborted.");
                return self::FAILURE;
            }
            $existing->forceDelete();
        }

        $shopName = $this->option('name') ?: $industry->defaultShopName();
        $ownerName = $this->option('owner-name') ?: "{$shopName} Owner";
        $ownerEmail = $this->option('owner-email');
        $ownerPassword = $this->option('owner-password');

        $this->info("Creating demo tenant:");
        $this->line("  Subdomain:  {$subdomain}");
        $this->line("  Shop name:  {$shopName}");
        $this->line("  Industry:   {$industry->label()}");
        $this->line("  Owner name: {$ownerName}");
        $this->line("  Owner:      {$ownerEmail}");
        $this->newLine();

        try {
            DB::transaction(function () use ($subdomain, $shopName, $industry, $ownerName, $ownerEmail, $ownerPassword) {
                $tenant = Tenant::create([
                    'id'          => (string) Str::uuid(),
                    'license_id'  => null,
                    'name'        => $shopName,
                    'subdomain'   => $subdomain,
                    'plan_tier'   => 'scale',
                    'is_active'   => true,
                ]);

                $seeder = new DemoSeeder(
                    industry: $industry,
                    logger: fn(string $msg) => $this->line($msg),
                );
                $seeder->seed($tenant, $ownerName, $ownerEmail, $ownerPassword);
            });
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("Seeding failed: " . $e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Demo tenant ready.");
        $this->line("  URL:      https://{$subdomain}.intake.works/admin");
        $this->line("  Email:    {$ownerEmail}");
        $this->line("  Password: {$ownerPassword}");
        return self::SUCCESS;
    }
}
