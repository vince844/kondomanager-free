<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Models\Anagrafica;
use App\Models\Immobile;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\Cassa; // Importante per la relazione cassa

class RigaScrittura extends Model
{
    protected $table = 'righe_scritture';

    protected $fillable = [
        'scrittura_id',
        'conto_contabile_id',
        'cassa_id',       // Fondamentale per i movimenti bancari
        'voce_spesa_id',  // (Ex conto_id) Per le spese di gestione
        'tipo_riga',      // 'dare' o 'avere'
        'importo',        // Salvato in CENTESIMI (integer)
        'immobile_id',
        'anagrafica_id',
        'rata_id',
        'riferimento_type', // Per il polimorfismo
        'riferimento_id',
        'note'
    ];

    /**
     * ACCESSOR & MUTATOR: Importo in Euro
     * Ti permette di usare $riga->importo_euro
     * - In lettura: 1050 diventa 10.50
     * - In scrittura: 10.50 diventa 1050
     */
    protected function importoEuro(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->importo / 100,
            set: fn ($value) => ['importo' => (int) round($value * 100)],
        );
    }

    // --- RELAZIONI ---

    public function scrittura(): BelongsTo
    {
        return $this->belongsTo(ScritturaContabile::class, 'scrittura_id');
    }

    public function contoContabile(): BelongsTo
    {
        return $this->belongsTo(ContoContabile::class);
    }

    public function cassa(): BelongsTo
    {
        return $this->belongsTo(Cassa::class);
    }
    
    // Relazione opzionale con la voce di spesa (Preventivo)
    public function voceSpesa(): BelongsTo
    {
        // Punta alla tabella 'conti' (o 'voci_spesa' se l'hai rinominata)
        return $this->belongsTo(Conto::class, 'voce_spesa_id');
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    public function immobile(): BelongsTo
    {
        return $this->belongsTo(Immobile::class);
    }

    public function rata(): BelongsTo
    {
        return $this->belongsTo(Rata::class);
    }

    public function riferimento(): MorphTo
    {
        return $this->morphTo();
    }
}