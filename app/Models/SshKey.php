<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SshKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'file_path' => 'File Path',
            'string' => 'Key Content',
            default => $this->type,
        };
    }
}
