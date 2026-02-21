<?php

namespace App\Providers\Filament;

use Filament\Facades\Filament;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // Add footer view
        FilamentView::registerRenderHook(
            PanelsRenderHook::FOOTER,
            fn() => view('footer')
        );

        Filament::registerRenderHook('panels::body.end', fn() => Blade::render("@vite('resources/js/app.js')"));

        return $panel
            ->default()
            ->id('admin')
            ->path('/')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'danger' => Color::hex('#bf616a'), // nord11
                'gray' => [
                    50 => '#eceff4',  // nord6 - snow storm
                    100 => '#e5e9f0', // nord5 - snow storm
                    200 => '#d8dee9', // nord4 - snow storm
                    300 => '#a7b1c5',
                    400 => '#8c9ab3',
                    500 => '#71829b',
                    600 => '#4c566a', // nord3 - polar night
                    700 => '#434c5e', // nord2 - polar night
                    800 => '#3b4252', // nord1 - polar night
                    900 => '#2e3440', // nord0 - polar night
                    950 => '#232831',
                ],
                'info' => Color::hex('#81a1c1'), // nord9
                // 'primary' => Color::hex('#88c0d0'), // nord8
                'primary' => Color::Indigo,
                // 'primary' => [
                //     50 => '#FAFCFD',
                //     100 => '#F3F9FA',
                //     200 => '#E3EFF2',
                //     300 => '#CFE6EC',
                //     400 => '#ACD4DD',
                //     500 => '#88C0D0',
                //     600 => '#7AADBB',
                //     700 => '#66909B',
                //     800 => '#52737D',
                //     900 => '#445E66',
                //     950 => '#293A3D',
                // ],
                'secondary' => Color::hex('#5e81ac'), // nord10
                'success' => Color::hex('#a3be8c'), // nord14
                'warning' => Color::hex('#ebcb8b'), // nord13
                // 'polarnight' => Color::hex('#3b4353'), // nord1
            ])
            ->darkMode(true, true)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop(false)
            ->brandName('m3u editor')
            ->brandLogo(fn() => view('filament.admin.logo'))
            ->favicon('/favicon.png')
            ->brandLogoHeight('2.5rem')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->unsavedChangesAlerts()
            ->authMiddleware([]);
    }
}
