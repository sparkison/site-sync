<?php

use App\Models\Environment;
use App\Models\Site;
use App\Services\SyncService;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

it('can execute a simple local command via runCommand', function () {
    $env = Environment::factory()->make(['is_local' => true]);

    $result = app(SyncService::class)->runCommand($env, 'echo hello');

    expect($result['success'])->toBeTrue();
    expect($result['output'])->toContain('hello');
});

it('runs hooks during a sync', function () {
    // we only care that the commands are executed, so capture output
    $output = '';
    // use service instance (no log needed)
    $service = app(SyncService::class);

    $site = Site::factory()->create();

    $from = Environment::factory()->make([
        'site_id' => $site->id,
        'name' => 'from',
        'is_local' => true,
        'sync_hooks' => [
            'before_push_source' => [
                ['command' => 'echo foo'],
            ],
            'after_push_source' => [
                ['command' => 'echo bar'],
            ],
        ],
    ]);

    $to = Environment::factory()->make([
        'site_id' => $site->id,
        'name' => 'to',
        'is_local' => true,
        'sync_hooks' => [],
    ]);

    ob_start();
    $service->push($from, $to, []);
    $output = ob_get_clean();

    expect($output)->toContain('foo');
    expect($output)->toContain('bar');
});
