@php
    $siteCount = \App\Models\Site::count();
    $siteUrl = \App\Filament\Resources\SiteResource::getUrl('index');
@endphp
<x-filament-widgets::widget class="fi-filament-info-widget">
    <x-filament::section>
        <div class="flex items-center gap-x-3">
            <div>
                <a class="w-12 h-12 flex items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-900 outline outline-1 outline-gray-300 dark:outline-gray-700 hover:outline-primary-500 transition group-hover:outline-primary-500"
                    href="{{ $siteUrl }}" rel="noopener noreferrer">
                    <x-filament::icon icon="heroicon-o-globe-alt" class="w-6 h-6 text-indigo-500" />
                </a>
            </div>

            <div class="flex-1">
                <h2 class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    Sites
                </h2>
                <p class="text-gray-600 dark:text-gray-300">
                    Manage your sites and environments. Keep your data in sync across all your environments
                    with ease.
                </p>
            </div>

            <div class="flex flex-col items-end gap-y-1">
                <x-filament::button color="gray" tag="a" href="{{ $siteUrl }}"
                    icon="heroicon-o-arrow-top-right-on-square" badge="{{ $siteCount }}"
                    icon-alias="panels::widgets.filament-info.open-documentation-button" rel="noopener noreferrer">
                    Sites
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>