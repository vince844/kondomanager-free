<?php

namespace App\Models;

use App\Enums\BackupStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Backup extends Model
{
    protected $fillable = [
        'uuid',
        'filename',
        'disk',
        'status',
        'progress',
        'checkpoint',
        'manifest',
        'size',
        'checksum',
        'error',
        'created_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => BackupStatus::class,
        'progress' => 'array',
        'checkpoint' => 'array',
        'manifest' => 'array',
        'size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Backup $backup) {
            $backup->uuid = $backup->uuid ?: (string) Str::uuid();
        });
    }

    /**
     * Le rotte usano lo uuid (non enumerabile) al posto dell'id incrementale:
     * l'archivio contiene database completo e .env.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (BackupStatus $status) => $status->value,
            BackupStatus::runningStates()
        ));
    }

    public function isRunning(): bool
    {
        return $this->status->isRunning();
    }
}
