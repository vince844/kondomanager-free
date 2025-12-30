<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Helpers\MoneyHelper;
use App\Models\Gestionale\Conto;

class Rata extends Model
{
    protected $table = 'rate';

    protected $fillable = [
        'piano_rate_id',
        'conto_id',
        'numero_rata',
        'data_scadenza',
        'data_emissione',
        'descrizione',
        'importo_totale',
        'stato'
    ];

    protected $casts = [
        'numero_rata' => 'integer',
        'data_scadenza' => 'date',
        'data_emissione' => 'date',
        'importo_totale' => 'integer',
        'scadenza' => 'date',
    ];

    // === ACCESSORS con MoneyHelper ===
    public function getImportoTotaleFormattatoAttribute(): string
    {
        return MoneyHelper::format($this->importo_totale);
    }

    public function getImportoTotaleSenzaSimboloAttribute(): string
    {
        return MoneyHelper::format($this->importo_totale, false);
    }

    // === RELAZIONI ===
    public function pianoRate(): BelongsTo
    {
        return $this->belongsTo(PianoRate::class, 'piano_rate_id');
    }

    public function conto(): BelongsTo
    {
        return $this->belongsTo(Conto::class);
    }

    public function rateQuote(): HasMany
    {
        return $this->hasMany(RataQuote::class, 'rata_id');
    }

    // === SCOPES ===
    public function scopeBozza($query)
    {
        return $query->where('stato', 'bozza');
    }

    public function scopeEmessa($query)
    {
        return $query->where('stato', 'emessa');
    }

    public function scopeChiusa($query)
    {
        return $query->where('stato', 'chiusa');
    }

    // === METODI UTILI ===
    public function calcolaTotaleQuote(): int
    {
        return $this->rateQuote()->sum('importo');
    }

    public function isTotaleCoerente(): bool
    {
        return $this->importo_totale === $this->calcolaTotaleQuote();
    }

    public function isScaduta(): bool
    {
        return $this->data_scadenza?->isPast();
    }

    public function scritture()
    {
        return $this->belongsToMany(
            ScritturaContabile::class, 
            'rata_scrittura', 
            'rata_id', 
            'scrittura_contabile_id'
        )->withPivot(['importo_pagato', 'data_pagamento'])->withTimestamps();
    }

    public function getTotaleRataAttribute(): float
    {
        return $this->importo_totale / 100;
    }
}
