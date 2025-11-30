<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Fornitore extends Model
{
    protected $table = 'fornitori';

    protected $fillable = [
        'ragione_sociale',
        'partita_iva',
        'codice_fiscale',
        // Sede
        'indirizzo',
        'cap',
        'comune',
        'provincia',
        'nazione',
        // Dati societari
        'iscrizione_cciaa',
        'data_iscrizione_cciaa',
        'codice_ateco',
        'numero_iscrizione_ordine',
        'tipologia_ordine',
        'tipologia_fornitore',
        'certificazione_iso',
        'capitale_sociale',
        // Contatti
        'telefono',
        'cellulare',
        'fax',
        'email',
        'pec',
        'sito_web',
        // Stato
        'stato',
        'note',
        // Codici bancari / SEPA
        'codice_sia',
        'codice_cuc',
        'codice_sepa',
    ];

    protected $casts = [
        'certificazione_iso'      => 'boolean',
        'data_iscrizione_cciaa'   => 'date',
        'capitale_sociale'        => 'integer',
    ];

    /** ----------------------------------------------------
     *  REFERENTI (molti-anagrafiche)
     * ----------------------------------------------------*/
    public function referenti(): BelongsToMany
    {
        return $this->belongsToMany(
                Anagrafica::class,
                'anagrafica_fornitore',
                'fornitore_id',
                'anagrafica_id'
            )
            ->using(AnagraficaFornitore::class)
            ->withPivot(['ruolo', 'referente_principale'])
            ->withTimestamps();
    }

    /** ----------------------------------------------------
     *  CONTI CORRENTI (morph)
     * ----------------------------------------------------*/
    public function contiCorrenti(): MorphMany
    {
        return $this->morphMany(ContoCorrente::class, 'contable');
    }

    public function contoPredefinito()
    {
        return $this->contiCorrenti()->where('predefinito', true)->first();
    }

    /** ----------------------------------------------------
     *  SCOPES
     * ----------------------------------------------------*/
    public function scopeAttivi($query)
    {
        return $query->where('stato', 'attivo');
    }

    public function scopeSospesi($query)
    {
        return $query->where('stato', 'sospeso');
    }

    public function scopeCessati($query)
    {
        return $query->where('stato', 'cessato');
    }
    
    public function categoria()
    {
        return $this->belongsTo(CategoriaFornitore::class, 'categoria_id');
    }
}
