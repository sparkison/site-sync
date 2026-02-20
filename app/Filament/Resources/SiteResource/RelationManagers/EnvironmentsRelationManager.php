<?php

namespace App\Filament\Resources\SiteResource\RelationManagers;

use App\Models\SshKey;
use App\Services\SiteDiscoveryService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class EnvironmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'environments';

    protected static ?string $title = 'Environments';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Environment Name')
                            ->placeholder('local, staging, production, dev...')
                            ->required()
                            ->maxLength(100),

                        Forms\Components\Toggle::make('is_local')
                            ->label('Local environment (no SSH)')
                            ->live()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('vhost')
                            ->label('Site URL / Vhost')
                            ->placeholder('https://example.com')
                            ->url()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('wordpress_path')
                            ->label('WordPress Path')
                            ->placeholder('/var/www/html or /home/user/htdocs/example.com')
                            ->required()
                            ->maxLength(500)
                            ->suffixAction(
                                Action::make('discover')
                                    ->label('Auto-detect')
                                    ->icon('heroicon-o-magnifying-glass')
                                    ->action(function (Set $set, Get $get): void {
                                        $path = $get('wordpress_path');
                                        if (! $path) {
                                            Notification::make()
                                                ->warning()
                                                ->title('Enter a WordPress path first.')
                                                ->send();

                                            return;
                                        }

                                        $discovery = app(SiteDiscoveryService::class);
                                        $discovered = $discovery->discoverFromPath($path);

                                        if (! empty($discovered['db'])) {
                                            foreach ($discovered['db'] as $key => $value) {
                                                $set($key, $value);
                                            }
                                            Notification::make()
                                                ->success()
                                                ->title('Database config discovered from wp-config.php')
                                                ->send();
                                        } else {
                                            Notification::make()
                                                ->warning()
                                                ->title('No wp-config.php found at that path.')
                                                ->send();
                                        }
                                    })
                            ),
                    ])
                    ->columns(2),

                Section::make('Database')
                    ->schema([
                        Forms\Components\TextInput::make('db_name')
                            ->label('Database Name')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('db_user')
                            ->label('Database User')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('db_password')
                            ->label('Database Password')
                            ->password()
                            ->revealable()
                            ->maxLength(500),

                        Forms\Components\TextInput::make('db_host')
                            ->label('Database Host')
                            ->default('127.0.0.1')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('db_port')
                            ->label('Database Port')
                            ->numeric()
                            ->default(3306)
                            ->minValue(1)
                            ->maxValue(65535),

                        Forms\Components\TextInput::make('db_prefix')
                            ->label('Table Prefix')
                            ->default('wp_')
                            ->maxLength(20),

                        Forms\Components\TextInput::make('mysqldump_options')
                            ->label('Extra mysqldump Options')
                            ->placeholder('--max_allowed_packet=1G')
                            ->columnSpanFull()
                            ->visible(fn () => $this->getOwnerRecord()->sql_adapter === 'mysqldump'),
                    ])
                    ->columns(3),

                Section::make('SSH Connection')
                    ->schema([
                        Forms\Components\TextInput::make('ssh_host')
                            ->label('SSH Host / IP')
                            ->placeholder('123.45.67.89 or server.example.com')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('ssh_user')
                            ->label('SSH User')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('ssh_port')
                            ->label('SSH Port')
                            ->numeric()
                            ->default(22)
                            ->minValue(1)
                            ->maxValue(65535),

                        Forms\Components\TextInput::make('ssh_password')
                            ->label('SSH Password')
                            ->password()
                            ->revealable()
                            ->maxLength(500)
                            ->helperText('Optional if using an SSH key below.'),

                        Forms\Components\Select::make('ssh_key_id')
                            ->label('SSH Key')
                            ->options(SshKey::pluck('name', 'id'))
                            ->searchable()
                            ->nullable()
                            ->helperText('Select a saved SSH key to use instead of a password.'),

                        Forms\Components\TextInput::make('rsync_options')
                            ->label('Extra rsync Options')
                            ->placeholder('--verbose --itemize-changes --no-perms --no-owner --no-group')
                            ->default('--verbose --itemize-changes --no-perms --no-owner --no-group')
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->hidden(fn (Get $get): bool => (bool) $get('is_local')),

                Section::make('Exclude Patterns')
                    ->schema([
                        Forms\Components\TagsInput::make('exclude')
                            ->label('Exclude from Sync')
                            ->placeholder('Add pattern (e.g. wp-content/plugins/woocommerce/)')
                            ->helperText('These patterns will be passed to rsync as --exclude flags. Common patterns like .git/, node_modules/, wp-config.php are excluded by default.')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Environment')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'local' => 'success',
                        'staging' => 'warning',
                        'production' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_local')
                    ->label('Local')
                    ->boolean(),

                Tables\Columns\TextColumn::make('vhost')
                    ->label('URL')
                    ->url(fn ($record) => $record->vhost)
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('wordpress_path')
                    ->label('Path')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->wordpress_path),

                Tables\Columns\TextColumn::make('ssh_host')
                    ->label('SSH Host')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('db_name')
                    ->label('Database')
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Environment'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->reorderable(false);
    }
}
