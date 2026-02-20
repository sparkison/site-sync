<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sql_adapter',
        'notes',
    ];

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class)->orderBy('name');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class)->latest();
    }
}
