<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use App\Services\SiteDiscoveryService;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('discover')
                ->label('Discover from Path')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->schema([
                    TextInput::make('path')
                        ->label('Local WordPress Path')
                        ->placeholder('/Users/me/Sites/my-wp-site')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $discovery = app(SiteDiscoveryService::class);
                    $discovered = $discovery->discoverFromPath($data['path']);

                    $this->form->fill([
                        'name' => basename(rtrim($data['path'], '/')),
                    ]);

                    // Store discovered data in session for use after site is created
                    session(['sitesync_discovered' => $discovered]);

                    Notification::make()
                        ->success()
                        ->title('Site discovered')
                        ->body('Site name pre-filled. Save to create the site, then environments will be imported automatically.')
                        ->send();
                }),
        ];
    }

    protected function afterCreate(): void
    {
        $discovered = session('sitesync_discovered');

        if (! $discovered) {
            return;
        }

        session()->forget('sitesync_discovered');

        $site = $this->record;

        if (! empty($discovered['environments'])) {
            foreach ($discovered['environments'] as $envData) {
                $site->environments()->create(array_merge($envData, ['site_id' => $site->id]));
            }
        } elseif (! empty($discovered['db'])) {
            $site->environments()->create(array_merge([
                'site_id' => $site->id,
                'name' => 'local',
                'is_local' => true,
                'wordpress_path' => $discovered['wordpress_path'],
            ], $discovered['db']));
        }
    }
}
