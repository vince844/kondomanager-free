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
        'ritenuta_decisa_il',
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
        'ritenuta_decisa_il'            => 'datetime',
    ];

    /**
     * Nessuno si è mai pronunciato sulla ritenuta di questo fornitore.
     *
     * ⚠️ **Non è la stessa cosa di «non è soggetto a ritenuta»**, ed è tutta la ragione per cui
     * `ritenuta_decisa_il` esiste (Coda 116). `soggetto_ritenuta` è `NOT NULL default 0`: un
     * fornitore appena censito e uno per cui la risposta è davvero no hanno lo stesso valore in
     * colonna, quindi da soli non si distinguono. Con la data si distinguono, e la domanda si
     * può fare **una volta sola** invece che a ogni fattura — che è la differenza fra un avviso
     * che si legge e uno che in una settimana nessuno guarda più.
     *
     * ⛔ **Non va usato per decidere se applicare la ritenuta**: quello lo dicono
     * `soggetto_ritenuta` e `tipo_ritenuta`, e continuano a dirlo da soli. Questo campo dice
     * soltanto se qualcuno li ha guardati.
     */
    public function posizioneRitenutaMaiDecisa(): bool
    {
        // ⚠️ **La data non è l'unica prova che qualcuno abbia deciso, ed è la correzione del
        // 04/09/2026.** La prima stesura guardava solo `ritenuta_decisa_il`, e chiedeva la
        // posizione anche di fornitori palesemente classificati — quelli nati fuori dal
        // modulo: un seeder, una factory, un'importazione. Il difetto si è visto subito,
        // perché quattro test che registrano fatture sono diventati rossi su fornitori che
        // la ritenuta ce l'avevano dichiarata.
        //
        // La regola vera: un «sì» si vede dai campi che lo esprimono, e non ha bisogno di una
        // data che lo confermi. È il «no» a non avere modo di distinguersi dal silenzio, ed è
        // per quello che la colonna esiste. Il backfill della migrazione resta utile — mette
        // la data dove la decisione c'era — ma non è più l'unica cosa che regge la guardia.
        return $this->ritenuta_decisa_il === null
            && ! $this->soggetto_ritenuta
            && $this->tipo_ritenuta === null
            && ! $this->regime_forfetario;
    }

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