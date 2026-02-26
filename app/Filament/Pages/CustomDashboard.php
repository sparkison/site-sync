<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard;

class CustomDashboard extends Dashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $title = '';

    protected static ?string $navigationLabel = 'SiteSync';
}
