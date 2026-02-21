<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SshKeyResource\Pages;
use App\Models\SshKey;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class SshKeyResource extends Resource
{
    protected static ?string $model = SshKey::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'SSH Keys';

    protected static ?int $navigationSort = 2;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Key Name / Label')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('My Server Key'),

                        Forms\Components\Select::make('type')
                            ->label('Key Type')
                            ->options([
                                'file_path' => 'File Path (path to .pem / .pub key on this server)',
                                'string' => 'Key Content (paste private key)',
                            ])
                            ->native(false)
                            ->default('file_path')
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('value')
                            ->label('Key File Path')
                            ->placeholder('/root/.ssh/id_rsa or ~/.ssh/my_key')
                            ->helperText('Absolute path to the private key file accessible from this server.')
                            ->required()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('type') === 'file_path'),

                        Forms\Components\Textarea::make('value')
                            ->label('Private Key Content')
                            ->placeholder("-----BEGIN OPENSSH PRIVATE KEY-----\n...")
                            ->rows(10)
                            ->required()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('type') === 'string'),
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

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'file_path' => 'File Path',
                        'string' => 'Key Content',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'file_path' => 'info',
                        'string' => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('environments_count')
                    ->label('Used By')
                    ->counts('environments')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()->button()->hiddenLabel(),
                EditAction::make()->button()->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSshKeys::route('/'),
            // 'create' => Pages\CreateSshKey::route('/create'),
            'edit' => Pages\EditSshKey::route('/{record}/edit'),
        ];
    }
}
