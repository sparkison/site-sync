<?php

namespace App\Filament\Resources\SiteResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SyncLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncLogs';

    protected static ?string $title = 'Sync History';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('direction')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'push' => 'warning',
                        'pull' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('fromEnvironment.name')
                    ->label('From')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('toEnvironment.name')
                    ->label('To')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('scope')
                    ->label('Scope')
                    ->formatStateUsing(fn($state) => implode(', ', (array) $state)),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'completed' => 'success',
                        'running' => 'warning',
                        'failed' => 'danger',
                        'pending' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view_output')
                    ->label('Output')
                    ->icon('heroicon-o-command-line')
                    ->modalContent(fn($record) => view('filament.sync-log-output', ['log' => $record]))
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25]);
    }
}
