<?php

namespace App\Filament\Pages;

use App\Jobs\SyncJob;
use App\Models\Environment;
use App\Models\Site;
use App\Models\SyncLog;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Dashboard;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Native\Desktop\Facades\Notification as DesktopNotification;

class CustomDashboard extends Dashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $title = 'SiteSync';

    protected static ?string $navigationLabel = 'SiteSync';

    protected function getActions(): array
    {
        return [
            Action::make('sync_site')
                ->label('Sync Site')
                ->tooltip('Sync data between two environments of the selected site. Watch the terminal on the dashboard for progress and output.')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->schema([
                    Forms\Components\Select::make('site_id')
                        ->label('Site')
                        ->options(Site::all(['id', 'name'])->pluck('name', 'id'))
                        ->afterStateUpdated(function (callable $set) {
                            $set('from_environment_id', null);
                            $set('to_environment_id', null);
                        })
                        ->required()
                        ->live()
                        ->reactive(),
                    Schemas\Components\Grid::make()
                        ->columns(2)
                        ->hidden(fn (Get $get) => ! $get('site_id'))
                        ->schema([
                            Forms\Components\Select::make('from_environment_id')
                                ->label('From (Source)')
                                ->options(fn (Get $get) => Environment::where('site_id', $get('site_id'))->pluck('name', 'id'))
                                ->required()
                                ->searchable(),

                            Forms\Components\Select::make('to_environment_id')
                                ->label('To (Target)')
                                ->options(fn (Get $get) => Environment::where('site_id', $get('site_id'))->pluck('name', 'id'))
                                ->required()
                                ->searchable(),
                        ]),
                    Forms\Components\Select::make('direction')
                        ->options([
                            'push' => 'Push (source → target)',
                            'pull' => 'Pull (source → target)',
                        ])
                        ->default('pull')
                        ->required(),

                    Forms\Components\CheckboxList::make('scope')
                        ->label('What to sync')
                        ->options([
                            'themes' => 'Themes',
                            'plugins' => 'Plugins',
                            'mu-plugins' => 'MU-Plugins',
                            'uploads' => 'Uploads',
                            'core' => 'WordPress Core',
                            'db' => 'Database',
                            'all' => 'Everything',
                        ])
                        ->default(['themes'])
                        ->columns(2),

                    Forms\Components\TagsInput::make('custom_paths')
                        ->label('Custom paths')
                        ->placeholder('backups, wp-content/plugins/my-plugin, …')
                        ->helperText('Relative to the WordPress root. Press Enter to add each path.')
                        ->splitKeys(['Enter', 'Tab', ',']),
                ])
                ->action(function (array $data): void {
                    try {
                        $record = Site::findOrFail($data['site_id']);
                        $from = $record->environments()->find($data['from_environment_id']);
                        $to = $record->environments()->find($data['to_environment_id']);

                        if (! $from || ! $to) {
                            DesktopNotification::title('Invalid environments selected.')
                                ->message('Please check your selections and try again.')
                                ->show();

                            return;
                        }

                        $scope = array_values(array_unique(array_filter(array_merge(
                            $data['scope'] ?? [],
                            $data['custom_paths'] ?? [],
                        ))));

                        $syncLog = SyncLog::create([
                            'site_id' => $record->id,
                            'from_environment_id' => $from->id,
                            'to_environment_id' => $to->id,
                            'direction' => $data['direction'],
                            'scope' => $scope,
                            'status' => 'pending',
                        ]);

                        SyncJob::dispatch($syncLog);

                        DesktopNotification::title('Sync queued')
                            ->message("Syncing {$record->name}: {$from->name} → {$to->name}. Check sync history for progress.")
                            ->show();
                    } catch (\Throwable $e) {
                        DesktopNotification::title('Failed to start sync')
                            ->message($e->getMessage())
                            ->show();
                    }
                })
                ->modalIcon('heroicon-o-arrow-path')
                ->modalWidth('lg'),
        ];
    }
}
