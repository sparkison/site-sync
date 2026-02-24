<?php

namespace App\Services;

class AppSettings
{
    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        if ($value === null) {
            return $default instanceof \Closure ? $default() : $default;
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $all = $this->all();
        $all[$key] = $value;
        $this->persist($all);
        $this->cache = $all;
    }

    private function all(): array
    {
        if ($this->cache === null) {
            $path = $this->filePath();
            $this->cache = file_exists($path)
                ? (json_decode(file_get_contents($path), true) ?? [])
                : [];
        }

        return $this->cache;
    }

    private function persist(array $data): void
    {
        $path = $this->filePath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function filePath(): string
    {
        return storage_path('app/sitesync-settings.json');
    }
}
