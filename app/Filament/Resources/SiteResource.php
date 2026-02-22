<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Filament\Resources\SiteResource\RelationManagers;
use App\Jobs\SyncJob;
use App\Models\Site;
use App\Models\SyncLog;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Sites';

    protected static ?int $navigationSort = 1;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'notes'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Site Details')
                    ->compact()
                    ->icon('heroicon-o-globe-alt')
                    ->collapsible(fn ($record): bool => $record !== null)
                    ->collapsed(fn ($record): bool => $record !== null)
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('sql_adapter')
                            ->label('SQL Adapter')
                            ->options([
                                'wpcli' => 'WP-CLI (recommended)',
                                'mysqldump' => 'mysqldump / mysql',
                            ])
                            ->native(false)
                            ->default('wpcli')
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('sql_adapter')
                    ->label('SQL Adapter')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'wpcli' => 'success',
                        'mysqldump' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('environments_count')
                    ->label('Environments')
                    ->counts('environments')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('syncLogs_count')
                    ->label('Syncs')
                    ->counts('syncLogs')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                DeleteAction::make()->button()->hiddenLabel(),
                EditAction::make()->button()->hiddenLabel(),

                Action::make('export')
                    ->label('Export')
                    ->tooltip('Export this site and its environments to a JSON file. Sensitive fields (passwords) are not included in exports and will need to be added manually when importing.')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(fn (Site $record): string => route('sites.export', $record))
                    ->button()->hiddenLabel(),

                Action::make('sync')
                    ->label('Sync')
                    ->tooltip('Sync data between two environments of this site. Watch the terminal on the dashboard for progress and output.')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->schema(fn (Site $record) => [
                        Schemas\Components\Grid::make()
                            ->columns(2)
                            ->schema([
                                Forms\Components\Select::make('from_environment_id')
                                    ->label('From (Source)')
                                    ->options($record->environments()->pluck('name', 'id'))
                                    ->required()
                                    ->searchable(),

                                Forms\Components\Select::make('to_environment_id')
                                    ->label('To (Target)')
                                    ->options($record->environments()->pluck('name', 'id'))
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
                                'db' => 'Database',
                                'themes' => 'Themes',
                                'plugins' => 'Plugins',
                                'mu-plugins' => 'MU-Plugins',
                                'uploads' => 'Uploads',
                                'core' => 'WordPress Core',
                                'all' => 'Everything',
                            ])
                            ->default(['db'])
                            ->columns(2),

                        Forms\Components\TagsInput::make('custom_paths')
                            ->label('Custom paths')
                            ->placeholder('backups, wp-content/plugins/my-plugin, …')
                            ->helperText('Relative to the WordPress root. Press Enter to add each path.')
                            ->splitKeys(['Enter', 'Tab', ',']),
                    ])
                    ->action(function (Site $record, array $data): void {
                        $from = $record->environments()->find($data['from_environment_id']);
                        $to = $record->environments()->find($data['to_environment_id']);

                        if (! $from || ! $to) {
                            Notification::make()
                                ->danger()
                                ->title('Invalid environments selected.')
                                ->send();

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

                        Notification::make()
                            ->success()
                            ->title('Sync queued')
                            ->body("Syncing {$from->name} → {$to->name}. Check sync history for progress.")
                            ->send();
                    })
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalWidth('lg')
                    ->button()->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EnvironmentsRelationManager::class,
            RelationManagers\SyncLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}
