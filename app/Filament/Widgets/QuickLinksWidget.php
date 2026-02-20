<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SiteResource;
use App\Filament\Resources\SshKeyResource;
use App\Models\Site;
use App\Models\SshKey;
use Filament\Widgets\Widget;
use Livewire\Attributes\Computed;

class QuickLinksWidget extends Widget
{
    protected string $view = 'filament.widgets.quick-links-widget';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    #[Computed]
    public function siteCount(): int
    {
        return Site::count();
    }

    #[Computed]
    public function sshKeyCount(): int
    {
        return SshKey::count();
    }

    #[Computed]
    public function siteCreateUrl(): string
    {
        return SiteResource::getUrl('create');
    }

    #[Computed]
    public function siteIndexUrl(): string
    {
        return SiteResource::getUrl('index');
    }

    #[Computed]
    public function sshKeyCreateUrl(): string
    {
        return SshKeyResource::getUrl('create');
    }

    #[Computed]
    public function sshKeyIndexUrl(): string
    {
        return SshKeyResource::getUrl('index');
    }
}
