<?php
// MARKER-AGENCIES-SEEDER — Modus Sport Group + roster. Idempotent.

namespace Database\Seeders;

use App\Models\SalesAgency;
use App\Models\SalesRep;
use Illuminate\Database\Seeder;

class SalesAgencySeeder extends Seeder
{
    public function run(): void
    {
        $modus = SalesAgency::firstOrCreate(['slug' => 'modus-sport-group'], [
            'name'                => 'Modus Sport Group',
            'status'              => 'onboarding',
            'commission_year1'    => 0.25,
            'commission_residual' => 0.10,
            'deal_registration'   => true,
        ]);

        foreach ([
            ['Alex G',  'principal'],
            ['Alex',    'rep'],
            ['Adam',    'rep'],
            ['Nick',    'rep'],
            ['Jordan',  'rep'],
        ] as [$name, $role]) {
            SalesRep::firstOrCreate(
                ['agency_id' => $modus->id, 'name' => $name],
                ['role' => $role, 'status' => 'active'],
            );
        }

        $this->command?->info('Modus Sport Group seeded with 5 reps.');
    }
}
