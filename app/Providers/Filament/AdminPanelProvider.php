<?php

namespace App\Providers\Filament;

use App\Filament\Pages\CustomDashboard;
use Filament\Facades\Filament;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
            fn () => view('footer')
        );

        Filament::registerRenderHook('panels::body.end', fn () => Blade::render("@vite('resources/js/app.js')"));

        return $panel
            ->default()
            ->id('admin')
            ->path('/')
            // ->topbar(false)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Color::Indigo,
                'secondary' => Color::hex('#5e81ac'),
                'info' => Color::hex('#81a1c1'),
                'success' => Color::hex('#a3be8c'),
                'warning' => Color::hex('#ebcb8b'),
                'danger' => Color::hex('#bf616a'),
                'gray' => [
                    50 => '#eceff4',
                    100 => '#e5e9f0',
                    200 => '#d8dee9',
                    300 => '#a7b1c5',
                    400 => '#8c9ab3',
                    500 => '#71829b',
                    600 => '#4c566a',
                    700 => '#434c5e',
                    800 => '#3b4252',
                    900 => '#2e3440',
                    950 => '#232831',
                ],

            ])
            ->darkMode(true, true)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop(false)
            ->brandName('m3u editor')
            ->brandLogo(fn () => view('filament.admin.logo'))
            ->favicon('/favicon.png')
            ->brandLogoHeight('2.5rem')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                CustomDashboard::class,
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
