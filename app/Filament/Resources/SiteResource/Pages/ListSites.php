<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Services\SiteExportImportService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListSites extends ListRecords
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('import')
                ->label('Import Site')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->modalWidth('md')
                ->modalHeading('Import Site')
                ->modalDescription('Upload a SiteSync export file (.json) to import a site and its environments. Sensitive fields (passwords) are not included in exports and will need to be added manually.')
                ->form([
                    Forms\Components\FileUpload::make('import_file')
                        ->label('Export File (.json)')
                        ->acceptedFileTypes(['application/json', 'text/plain', 'text/json'])
                        ->disk('local')
                        ->directory('site-imports')
                        ->required(),
                ])
                ->action(function (array $data, SiteExportImportService $service): void {
                    try {
                        $content = Storage::disk('local')->get($data['import_file']);
                        Storage::disk('local')->delete($data['import_file']);

                        if (! $content) {
                            throw new \RuntimeException('Could not read the uploaded file.');
                        }

                        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                        $site = $service->import($payload);

                        Notification::make()
                            ->success()
                            ->title('Site imported')
                            ->body("\"{$site->name}\" was imported with ".($site->environments()->count()).' environment(s). Add any passwords or SSH keys manually.')
                            ->persistent()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Import failed')
                            ->body($e->getMessage())
                            ->send();
                    }
                }),
            Actions\CreateAction::make()
                ->slideOver(),
        ];
    }
}
