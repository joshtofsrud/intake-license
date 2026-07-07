<?php
// MARKER-REPPANEL-RESOURCE — creating a prospect IS deal registration.

namespace App\Filament\Rep\Resources\RepProspectResource\Pages;

use App\Filament\Rep\Resources\RepProspectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRepProspect extends CreateRecord
{
    protected static string $resource = RepProspectResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $rep = RepProspectResource::currentRep();

        $data['agency_id']    = $rep?->agency_id;
        $data['sales_rep_id'] = $rep?->id;
        $data['verified']     = false;
        $data['source']       = 'Rep registration';

        return $data;
    }
}
