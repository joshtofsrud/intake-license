<?php

namespace App\Filament\Resources\RoadmapEntryResource\Pages;

use App\Filament\Resources\RoadmapEntryResource;
use App\Services\Platform\ChangelogRoadmapImporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListRoadmapEntries extends ListRecords
{
    protected static string $resource = RoadmapEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importFromFile')
                ->label('Import from file')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Import roadmap entries from YAML')
                ->modalDescription('Upload a .yml/.yaml file. You will see a preview before any rows are written.')
                ->modalSubmitActionLabel('Parse file')
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label('YAML file')
                        ->acceptedFileTypes(['application/x-yaml', 'text/yaml', 'text/x-yaml', 'text/plain'])
                        ->disk('local')
                        ->directory('changelog-imports/staging')
                        ->visibility('private')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $relPath = $data['file'];
                    $absPath = Storage::disk('local')->path($relPath);
                    if (! is_file($absPath)) {
                        Notification::make()->title('Upload failed')->danger()->send();
                        return;
                    }
                    $contents = file_get_contents($absPath);

                    $importer = app(ChangelogRoadmapImporter::class);
                    $plan = $importer->parse(ChangelogRoadmapImporter::KIND_ROADMAP, $contents);

                    $token = (string) Str::uuid();
                    $finalPath = "changelog-imports/{$token}.yml";
                    Storage::disk('local')->put($finalPath, $contents);
                    Storage::disk('local')->delete($relPath);

                    Cache::put("changelog_import_plan:{$token}", [
                        'plan'      => $plan,
                        'file_path' => $finalPath,
                        'kind'      => ChangelogRoadmapImporter::KIND_ROADMAP,
                    ], now()->addMinutes(30));

                    return redirect()->to(route('filament.admin.pages.changelog-import-preview', ['token' => $token]));
                }),

            Actions\CreateAction::make(),
        ];
    }
}
