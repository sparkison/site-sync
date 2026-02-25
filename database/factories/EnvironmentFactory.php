<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvironmentFactory extends Factory
{
    protected $model = Environment::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => $this->faker->randomElement(['local', 'staging', 'production']),
            'is_local' => true,
            'vhost' => null,
            'wordpress_path' => '/tmp',
            'db_name' => null,
            'db_user' => null,
            'db_password' => null,
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_prefix' => 'wp_',
            'mysqldump_options' => null,
            'ssh_host' => null,
            'ssh_user' => null,
            'ssh_port' => 22,
            'ssh_password' => null,
            'ssh_key_id' => null,
            'rsync_options' => null,
            'exclude' => [],
            'sync_hooks' => [],
        ];
    }
}
