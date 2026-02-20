<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Environment extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'name',
        'is_local',
        'vhost',
        'wordpress_path',
        'db_name',
        'db_user',
        'db_password',
        'db_host',
        'db_port',
        'db_prefix',
        'mysqldump_options',
        'ssh_host',
        'ssh_user',
        'ssh_port',
        'ssh_password',
        'ssh_key_id',
        'rsync_options',
        'exclude',
    ];

    protected function casts(): array
    {
        return [
            'is_local' => 'boolean',
            'db_port' => 'integer',
            'ssh_port' => 'integer',
            'db_password' => 'encrypted',
            'ssh_password' => 'encrypted',
            'exclude' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function sshKey(): BelongsTo
    {
        return $this->belongsTo(SshKey::class);
    }

    public function syncLogsAsSource()
    {
        return $this->hasMany(SyncLog::class, 'from_environment_id');
    }

    public function syncLogsAsTarget()
    {
        return $this->hasMany(SyncLog::class, 'to_environment_id');
    }

    /**
     * Build SSH connection string for CLI use.
     */
    public function getSshConnectionString(): string
    {
        return "{$this->ssh_user}@{$this->ssh_host}";
    }
}
