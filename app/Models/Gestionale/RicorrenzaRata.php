<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RicorrenzaRata extends Model
{
    protected $table = 'ricorrenze_rate';

    protected $fillable = [
        'piano_rate_id',
        'frequency',
        'interval',
        'by_day',
        'by_month_day',
        'until',
        'rrule',
        'timezone',
    ];

    protected $casts = [
        'by_day' => 'array',
        'until' => 'datetime',
    ];

    // === RELAZIONI ===
    public function pianoRate(): BelongsTo
    {
        return $this->belongsTo(PianoRate::class, 'piano_rate_id');
    }

    // === METODI UTILI ===
    public function isScaduta(): bool
    {
        return $this->until && $this->until->isPast();
    }
}
