<?php

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\Gestionale\Conto; 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContoContabile extends Model
{
    use SoftDeletes;

    protected $table = 'conti_contabili';

    protected $fillable = [
        'condominio_id',
        'codice',       
        'nome',         
        'descrizione',
        'tipo',         
        'categoria',    
        'parent_id',    
        'livello',      
        'di_sistema',   
        'attivo',
        'ordine',
        'note'
    ];

    protected $casts = [
        'di_sistema' => 'boolean',
        'attivo' => 'boolean',
        'livello' => 'integer',
        'ordine' => 'integer',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    /**
     * Relazione "Genitore" (Mastro di riferimento)
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ContoContabile::class, 'parent_id');
    }

    /**
     * Relazione "Figli" (Sottoconti)
     */
    public function children(): HasMany
    {
        return $this->hasMany(ContoContabile::class, 'parent_id')->orderBy('codice');
    }

    /**
     * Relazione inversa con la Cassa
     * Un conto contabile di tipo liquidità può essere associato a UNA cassa specifica.
     */
    public function cassa(): HasOne
    {
        return $this->hasOne(Cassa::class, 'conto_contabile_id');
    }

    /**
     * Relazione con i Conti Economici (Spese/Entrate)
     * Utile se questo conto contabile funge da contropartita o raggruppamento
     */
    public function contiEconomici(): HasMany
    {
        return $this->hasMany(Conto::class, 'conto_contabile_id');
    }
}