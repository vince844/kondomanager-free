<?php

namespace App\Models\Gestionale;

use App\Traits\HasProtocolNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;

class ScritturaContabile extends Model
{
    use SoftDeletes, HasProtocolNumber;

    protected $table = 'scritture_contabili';

    protected $fillable = [
        'condominio_id',
        'gestione_id',
        'esercizio_id',
        'data_registrazione',
        'data_competenza',
        'numero_protocollo',
        'causale',
        'descrizione',
        'tipo_movimento', 
        'stato',          
        'created_by',
        'registrata_by',
        'registrata_at',
        'note'
    ];

    protected $casts = [
        'data_registrazione' => 'date',
        'data_competenza'    => 'date',
        'registrata_at'      => 'datetime',
    ];

    // --- RELAZIONI CHE MANCAVANO ---

    /**
     * Risolve l'errore: Undefined relationship [gestione]
     */
    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class, 'gestione_id');
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    // --- RELAZIONI PRINCIPALI ---

    public function righe(): HasMany
    {
        // 🔥 IMPORTANTE: Qui usiamo 'scrittura_id' perché così è definito nella tua migration
        return $this->hasMany(RigaScrittura::class, 'scrittura_id');
    }

    public function documentabile(): MorphTo
    {
        return $this->morphTo();
    }
    
    public function isQuadrata(): bool
    {
        $dare = $this->righe->where('tipo_riga', 'dare')->sum('importo');
        $avere = $this->righe->where('tipo_riga', 'avere')->sum('importo');
        
        return $dare === $avere;
    }

    public function quotePagate()
    {
        // Relazione molti-a-molti verso RataQuote passando per la tabella pivot 'quota_scrittura'
        // Ma per semplicità, possiamo definire una HasMany verso la tabella pivot stessa se esiste il model,
        // oppure usare DB query builder nel controller per performance.
        // Usiamo una relazione diretta verso la tabella pivot se hai creato il Model 'QuotaScrittura', 
        // altrimenti usiamo il query builder nel controller.
        return $this->belongsToMany(RataQuote::class, 'quota_scrittura', 'scrittura_contabile_id', 'rate_quota_id')
                    ->withPivot('importo_pagato');
    }
}