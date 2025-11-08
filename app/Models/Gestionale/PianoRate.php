<?php

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\Gestione;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PianoRate extends Model
{
    protected $table = 'piani_rate';

    protected $fillable = [
        'gestione_id',
        'condominio_id',
        'nome',
        'descrizione',
        'tipo',
        'metodo_calcolo',
        'attivo',
        'note'
    ];

    protected $casts = [
        'attivo' => 'boolean',
    ];

    // === RELAZIONI ===
    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class);
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function rate(): HasMany
    {
        return $this->hasMany(Rata::class, 'piano_rate_id');
    }

    public function ricorrenza(): HasOne
    {
        return $this->hasOne(RicorrenzaRata::class, 'piano_rate_id');
    }

    // === SCOPES ===
    public function scopeAttivo($query)
    {
        return $query->where('attivo', true);
    }

    public function scopeTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    // === METODI UTILI ===
    public function haRicorrenza(): bool
    {
        return $this->ricorrenza()->exists();
    }

    public function haRateGenerate(): bool
    {
        return $this->rate()->exists();
    }
}
