<?php

namespace App\Filament\Pages;

use App\Services\AppSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.manage-settings';

    public ?array $data = [];

    public static function defaultIgnores(): array
    {
        return [
            '.DS_Store',
            '.git/',
            '.gitignore',
            '.gitmodules',
            '.env',
            'node_modules/',
            'movefile.yml',
            'movefile.yaml',
            'Movefile',
            'wp-config.php',
            'wp-content/*.sql.gz',
            '*.orig',
        ];
    }

    public static function defaultPaths(): array
    {
        return [
            'rsync' => null,
            'ssh' => null,
            'gzip' => null,
            'wp' => null,
            'mysqldump' => null,
            'mysql' => null,
        ];
    }

    public function mount(): void
    {
        $settings = app(AppSettings::class);

        $this->form->fill([
            'default_ignores' => $settings->get('default_ignores', static::defaultIgnores()),
            'custom_paths' => $settings->get('custom_paths', static::defaultPaths()),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Form::make([
                    Tabs::make('General Settings')
                        ->persistTabInQueryString()
                        ->columnSpanFull()
                        ->contained(false)
                        ->tabs([
                            Tab::make(label: 'Sync')
                                ->icon('heroicon-o-arrow-path')
                                ->columnSpanFull()
                                ->schema([
                                    Section::make('Sync Settings')
                                        ->description('Configure default settings for file and database sync operations.')
                                        ->columnSpanFull()
                                        ->schema([
                                            TagsInput::make('default_ignores')
                                                ->label('Default Ignores')
                                                ->placeholder('Enter a file or directory to ignore')
                                                ->helperText('Files and directories ignored by default when syncing. Can be overridden per environment.'),
                                        ]),
                                ]),

                            Tab::make(label: 'Paths')
                                ->icon('heroicon-o-link')
                                ->columnSpanFull()
                                ->schema([
                                    Section::make('Tool Paths')
                                        ->description('Override the path to each tool binary. Leave blank to use the system default from your PATH.')
                                        ->columnSpanFull()
                                        ->columns(2)
                                        ->schema([
                                            TextInput::make('custom_paths.rsync')
                                                ->label('rsync')
                                                ->placeholder('rsync')
                                                ->helperText('Required for file sync'),
                                            TextInput::make('custom_paths.ssh')
                                                ->label('ssh')
                                                ->placeholder('ssh')
                                                ->helperText('Required for remote connections'),
                                            TextInput::make('custom_paths.gzip')
                                                ->label('gzip')
                                                ->placeholder('gzip')
                                                ->helperText('Required for database backups'),
                                            TextInput::make('custom_paths.wp')
                                                ->label('WP-CLI (wp)')
                                                ->placeholder('wp')
                                                ->helperText('Required for WP-CLI database sync'),
                                            TextInput::make('custom_paths.mysqldump')
                                                ->label('mysqldump')
                                                ->placeholder('mysqldump')
                                                ->helperText('Required for mysqldump database export'),
                                            TextInput::make('custom_paths.mysql')
                                                ->label('mysql')
                                                ->placeholder('mysql')
                                                ->helperText('Required for mysqldump database import'),
                                        ]),
                                ]),
                        ]),
                ])->livewireSubmitHandler('save'),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(AppSettings::class);

        $settings->set('default_ignores', $data['default_ignores'] ?? []);
        $settings->set('custom_paths', $data['custom_paths'] ?? []);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Settings')
                ->action(fn () => $this->save()),
        ];
    }
}
