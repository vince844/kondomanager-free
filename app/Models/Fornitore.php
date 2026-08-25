<?php

namespace App\Models;

use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;

class Fornitore extends Model
{
    use HasFactory;

    /**
     * Il nome della tabella associata al modello.
     *
     * @var string
     */
    protected $table = 'fornitori';

    /**
     * Gli attributi assegnabili in massa (Mass Assignment).
     *
     * @var array<int, string>
     */
    protected $fillable = [
        // Identità aziendale
        'ragione_sociale',
        'partita_iva',
        'codice_fiscale',
        
        // Sede legale
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
        'categoria_id',
        'certificazione_iso',
        'capitale_sociale',
        
        // Contatti
        'telefono',
        'cellulare',
        'fax',
        'email',
        'pec',
        'sito_web',
        
        // Stato e Note
        'stato',
        'note',
        
        // Codici Bancari (Storici)
        'codice_sia',
        'codice_cuc',
        'codice_sepa',

        // --- NUOVI CAMPI FISCALI E PAGAMENTI (V 1.9) ---
        'soggetto_ritenuta',
        'perc_ritenuta',
        'perc_imponibile_ritenuta',
        'codice_tributo',
        'giorni_scadenza',
        'modalita_pagamento_default',
        'iban_principale',

        // --- REGIME FISCALE RITENUTA (V 1.10, Fase 1 — additivo ai campi legacy sopra) ---
        'tipo_ritenuta',
        'natura_percipiente',
        'residente_fiscale',
        'regime_forfetario',
        'forfetario_dichiarato_il',
        'forfetario_riferimento',
        'provvigioni_base_ridotta',
        'provvigioni_dichiarazione_il',
    ];

    /**
     * Il casting automatico degli attributi.
     * Trasforma automaticamente i dati del DB in tipi nativi PHP.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'certificazione_iso'       => 'boolean',
        'soggetto_ritenuta'        => 'boolean',
        'data_iscrizione_cciaa'    => 'date',
        'perc_ritenuta'            => 'decimal:2',
        'perc_imponibile_ritenuta' => 'decimal:2',
        'capitale_sociale'         => 'integer',

        'tipo_ritenuta'                 => \App\Enums\Fiscale\TipoRitenuta::class,
        'natura_percipiente'            => \App\Enums\Fiscale\NaturaPercipiente::class,
        'residente_fiscale'             => 'boolean',
        'regime_forfetario'             => 'boolean',
        'forfetario_dichiarato_il'      => 'date',
        'provvigioni_base_ridotta'      => 'boolean',
        'provvigioni_dichiarazione_il'  => 'date',
    ];

    /**
     * Relazione: Un fornitore appartiene a una Categoria.
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaFornitore::class, 'categoria_id');
    }

    /**
     * Relazione Many-to-Many: Referenti (Anagrafiche) associate al fornitore.
     */
    public function referenti(): BelongsToMany
    {
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_fornitore')
                    ->withPivot('ruolo', 'referente_principale')
                    ->withTimestamps();
    }

    /**
     * Relazione Polimorfica: Un fornitore può avere più conti correnti.
     * Questa è essenziale per la gestione della Tesoreria!
     */
    public function contiCorrenti(): MorphMany
    {
        return $this->morphMany(ContoCorrente::class, 'contable');
    }

    /**
     * Relazione: Un fornitore può avere molte fatture passive.
     */
    public function fatture()
    {
        return $this->hasMany(FatturaPassiva::class, 'fornitore_id');
    }

    /**
     * Relazione Polimorfica: Documenti associati al fornitore.
     */
    public function documenti(): MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    /**
     * Scope per filtrare i fornitori attivi.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAttivi(Builder $query): Builder
    {
        return $query->where('stato', 'attivo');
    }
}