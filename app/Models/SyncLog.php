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

    protected static function booted(): void
    {
        static::deleted(function (SyncLog $log): void {
            $path = $log->logFilePath();
            if (file_exists($path)) {
                unlink($path);
            }
        });
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

    public function logFilePath(): string
    {
        return storage_path("app/sync-logs/sync-{$this->id}.log");
    }

    public function appendOutput(string $text): void
    {
        $dir = storage_path('app/sync-logs');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->logFilePath(), $text, FILE_APPEND);
    }

    /**
     * @return array{content: string, offset: int}
     */
    public function readOutputChunk(int $offset = 0): array
    {
        $path = $this->logFilePath();

        if (! file_exists($path)) {
            return ['content' => '', 'offset' => 0];
        }

        clearstatcache(true, $path);
        $size = filesize($path);

        if ($offset >= $size) {
            return ['content' => '', 'offset' => $size];
        }

        $fp = fopen($path, 'rb');
        fseek($fp, $offset);
        $content = (string) fread($fp, $size - $offset);
        fclose($fp);

        return ['content' => $content, 'offset' => $size];
    }

    public function getOutputContent(): string
    {
        return file_exists($this->logFilePath())
            ? (file_get_contents($this->logFilePath()) ?: '')
            : '';
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

    public function markCancelled(): void
    {
        $this->update(['status' => 'cancelled', 'completed_at' => now()]);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function getScopeLabel(): string
    {
        return collect($this->scope)->implode(', ');
    }
}
