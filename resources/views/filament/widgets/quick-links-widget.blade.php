<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

        {{-- Sites Card --}}
        <a href="{{ $this->siteIndexUrl }}"
            class="group relative flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-primary-500">
            <div
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 transition group-hover:bg-primary-100 dark:bg-primary-950 dark:text-primary-400 dark:group-hover:bg-primary-900">
                <x-filament::icon icon="heroicon-o-globe-alt" class="h-6 w-6" />
            </div>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">Sites</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->siteCount }} {{ Str::plural('site', $this->siteCount) }} configured
                </p>
            </div>
        </a>

        {{-- SSH Keys Card --}}
        <a href="{{ $this->sshKeyIndexUrl }}"
            class="group relative flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-primary-500">
            <div
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-600 transition group-hover:bg-green-100 dark:bg-green-950 dark:text-green-400 dark:group-hover:bg-green-900">
                <x-filament::icon icon="heroicon-o-key" class="h-6 w-6" />
            </div>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-900 dark:text-white">SSH Keys</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->sshKeyCount }} {{ Str::plural('key', $this->sshKeyCount) }} stored
                </p>
            </div>
        </a>

    </div>
</x-filament-widgets::widget>