<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Filament\Resources\SiteResource\RelationManagers;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Native\Desktop\Facades\ChildProcess;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Sites';

    protected static ?int $navigationSort = 1;

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
                Action::make('sync')
                    ->label('Sync')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->schema(fn (Site $record) => [
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
                                'files' => 'WordPress Files (themes/plugins/uploads)',
                                'core' => 'WordPress Core',
                                'all' => 'Everything',
                            ])
                            ->default(['db'])
                            ->columns(2)
                            ->required(),
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

                        $syncLog = SyncLog::create([
                            'site_id' => $record->id,
                            'from_environment_id' => $from->id,
                            'to_environment_id' => $to->id,
                            'direction' => $data['direction'],
                            'scope' => $data['scope'],
                            'status' => 'pending',
                        ]);

                        ChildProcess::start(
                            cmd: [PHP_BINARY, 'artisan', 'sitesync:run', $syncLog->id],
                            alias: 'sync-'.$syncLog->id,
                            cwd: base_path(),
                        );

                        Notification::make()
                            ->success()
                            ->title('Sync started')
                            ->body("Syncing {$from->name} → {$to->name}. Watch the terminal on the dashboard for progress.")
                            ->send();
                    })
                    ->modalWidth('lg'),

                EditAction::make(),
                DeleteAction::make(),
            ])
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
