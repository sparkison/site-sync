<?php

namespace App\Filament\Widgets;

use App\Models\SyncLog;
use Filament\Actions\Action;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class SyncLogsWidget extends BaseWidget
{
    protected static ?string $heading = 'Recent Sync Activity';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(SyncLog::query()->latest()->limit(10))
            ->columns([
                Tables\Columns\TextColumn::make('site.name')
                    ->label('Site')
                    ->searchable(),

                Tables\Columns\TextColumn::make('direction')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
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
                    ->formatStateUsing(fn ($state) => implode(', ', (array) $state)),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
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
            ])
            ->actions([
                Action::make('view_output')
                    ->label('Output')
                    ->icon('heroicon-o-command-line')
                    ->modalContent(fn ($record) => view('filament.sync-log-output', ['log' => $record]))
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->paginated(false);
    }
}
