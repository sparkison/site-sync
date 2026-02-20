<?php

namespace App\Helpers;

class Bin
{
    public static function path(string $tool): string
    {
        // If we are running as a compiled app
        if (app()->isProduction()) {
            return base_path("../Resources/bin/{$tool}");
        }

        // If we are in local development
        return resource_path("bin/{$tool}");
    }
}
