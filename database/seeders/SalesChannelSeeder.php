<?php
// MARKER-CAMPAIGNS-SEEDER — Bike shops channel (active) + Salons (draft).
// Idempotent on slug. Also folds all channel-less prospects into the bike
// channel, so the existing WA/national book lands in the right campaign.

namespace Database\Seeders;

use App\Models\SalesChannel;
use App\Models\SalesProspect;
use Illuminate\Database\Seeder;

class SalesChannelSeeder extends Seeder
{
    public function run(): void
    {
        $bike = SalesChannel::firstOrCreate(['slug' => 'bike-shops'], [
            'name' => 'Bike shops',
            'status' => 'active',
            'categories' => ['Sales', 'Rental', 'Service'],
            'business_types' => ['Full-service shop', 'Service-only', 'Mobile service', 'Rental / resort', 'Boutique / custom'],
            'criteria' => [
                ['label' => 'Service revenue share', 'note' => 'Books repair work — Intake\'s core wedge'],
                ['label' => 'Owner-operated', 'note' => 'Decision-maker reachable at the counter'],
                ['label' => 'Software pain', 'note' => 'On paper, spreadsheets, or legacy POS'],
                ['label' => 'Rental exposure', 'note' => 'Fleet management is a strong add-on hook'],
            ],
            'playbook' => SalesChannel::DEFAULT_PLAYBOOK,
            'best_ask' => '15-min owner demo at the shop',
        ]);

        SalesChannel::firstOrCreate(['slug' => 'salons'], [
            'name' => 'Salons',
            'status' => 'draft',
            'categories' => ['Service', 'Retail'],
            'business_types' => ['Full-service salon', 'Barber shop', 'Booth-rental suite', 'Spa hybrid'],
            'criteria' => [
                ['label' => 'Chair count 3+', 'note' => 'Enough volume for scheduling pain'],
                ['label' => 'Booth rental mix', 'note' => 'Rent tracking maps to rental module'],
                ['label' => 'Retail shelf', 'note' => 'Product sales use inventory + POS'],
                ['label' => 'Walk-in heavy', 'note' => 'Needs the register + capacity tools'],
            ],
            'playbook' => SalesChannel::DEFAULT_PLAYBOOK,
            'best_ask' => 'Demo between appointments, mid-week',
        ]);

        $folded = SalesProspect::whereNull('channel_id')->update(['channel_id' => $bike->id]);
        $this->command?->info("Channels seeded. Folded {$folded} channel-less prospects into Bike shops.");
    }
}
