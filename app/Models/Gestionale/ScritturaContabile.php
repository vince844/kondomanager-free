<?php

namespace App\Models\Gestionale;

use App\Traits\HasProtocolNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
        'tipo_movimento', // 'incasso_rata', 'pagamento_fornitore', etc.
        'stato',          // 'registrata', 'bozza'
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

    // Relazione principale: Una scrittura ha molte righe
    public function righe(): HasMany
    {
        return $this->hasMany(RigaScrittura::class, 'scrittura_id');
    }

    // Polimorfismo (es. collegata a una Rata o Fattura intera)
    public function documentabile(): MorphTo
    {
        return $this->morphTo();
    }
    
    // Helper per verificare se DARE == AVERE
    public function isQuadrata(): bool
    {
        $dare = $this->righe->where('tipo_riga', 'dare')->sum('importo');
        $avere = $this->righe->where('tipo_riga', 'avere')->sum('importo');
        
        return $dare === $avere;
    }
}