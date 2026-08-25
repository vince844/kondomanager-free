<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipologiaImmobile extends Model
{
    use HasFactory;

    protected $table = 'tipologie_immobili';

    protected $fillable = [
        'nome',
        'categoria',
    ];

    /**
     * Gli immobili che appartengono a questa tipologia.
     *
     * ⚠️ **La chiave esterna era dichiarata `tipologia`, una colonna che non esiste**: la vera è
     * `tipologia_id`. Il difetto era latente perché la relazione non aveva chiamanti — chiamandola
     * si sarebbe presa un errore SQL su colonna sconosciuta — e si sarebbe svegliata alla prima
     * funzione che contasse gli immobili per tipologia, che è esattamente ciò che serve al campo
     * «Pertinenza di».
     */
    public function immobili()
    {
        return $this->hasMany(Immobile::class, 'tipologia_id');
    }
}
