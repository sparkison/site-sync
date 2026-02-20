<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'from_environment_id',
        'to_environment_id',
        'direction',
        'scope',
        'status',
        'output',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function fromEnvironment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'from_environment_id');
    }

    public function toEnvironment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'to_environment_id');
    }

    public function appendOutput(string $text): void
    {
        $this->update(['output' => ($this->output ?? '') . $text]);
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed', 'completed_at' => now()]);
    }

    public function markFailed(): void
    {
        $this->update(['status' => 'failed', 'completed_at' => now()]);
    }

    public function getScopeLabel(): string
    {
        return collect($this->scope)->implode(', ');
    }
}
