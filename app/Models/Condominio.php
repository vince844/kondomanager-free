<?php

namespace App\Models;

use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Traits\HasCustomIdentifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Condominio extends Model
{
    use HasFactory, HasCustomIdentifier;

    protected $table = 'condomini';

    // Specify the prefix for this model (e.g., 'BLD' for buildings)
    protected $customIdentifierPrefix = 'BLD'; 

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'codice_identificativo',
        'nome',   
        'indirizzo',
        'comune',             
        'provincia',          
        'cap',                
        'email',   
        'note',              
        'codice_fiscale',
        'anno_costruzione',   
        'anno_acquisizione',  
        'numero_piani',       
        'comune_catasto',     
        'codice_catasto',      
        'sezione_catasto', 
        'foglio_catasto',    
        'particella_catasto',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'anno_costruzione' => 'integer',
        'anno_acquisizione' => 'integer',
        'numero_piani' => 'integer',
    ];

    /**
     * Le anagrafiche (condòmini, fornitori, professionisti) associate al condominio.
     */
    public function anagrafiche(): BelongsToMany
    {
        return $this->belongsToMany(Anagrafica::class);
    }

    /**
     * Le comunicazioni inviate o relative a questo condominio.
     */
    public function comunicazioni(): BelongsToMany
    {
        return $this->belongsToMany(Comunicazione::class, 'comunicazione_condominio')->withTimestamps();
    }

    /**
     * I documenti archiviati per il condominio nel sistema.
     */
    public function documenti(): BelongsToMany
    {
        return $this->belongsToMany(Documento::class, 'condominio_documento');
    }

    /**
     * Gli eventi (es. scadenze, alert scadenze intelligenti, o futuri moduli lavori e sinistri) legati al condominio.
     */
    public function eventi(): BelongsToMany
    {
        return $this->belongsToMany(Evento::class, 'condominio_evento');
    }

    /**
     * Le palazzine o gli edifici fisici che compongono il supercondominio/condominio.
     */
    public function palazzine(): HasMany
    {
        return $this->hasMany(Palazzina::class);
    }

    /**
     * Le scale presenti all'interno del condominio.
     */
    public function scale(): HasMany
    {
        return $this->hasMany(Scala::class);
    }

    /**
     * Le unità immobiliari (appartamenti, box, cantine) che compongono il condominio.
     */
    public function immobili(): HasMany
    {
        return $this->hasMany(Immobile::class);
    }

    /**
     * Le tabelle millesimali associate al condominio.
     */
    public function tabelle(): HasMany
    {
        return $this->hasMany(Tabella::class);
    }

    /**
     * Gli esercizi contabili (anni di gestione) del condominio.
     */
    public function esercizi(): HasMany
    {
        return $this->hasMany(Esercizio::class);
    }

    /**
     * Le gestioni attive nel condominio (ordinaria, ed eventualmente future gestioni straordinarie).
     */
    public function gestioni(): HasMany
    {
        return $this->hasMany(Gestione::class);
    }

    /**
     * Il piano dei conti economico (spese e ricavi) configurato per il condominio.
     */
    public function pianiDeiConti(): HasMany
    {
        return $this->hasMany(PianoConto::class);
    }

    /**
     * Le risorse finanziarie del condominio (Banche, Casse contanti, Fondi).
     */
    public function casse(): HasMany
    {
        return $this->hasMany(Cassa::class);
    }

    /**
     * Il Piano dei Conti Patrimoniale (Attività/Passività).
     * Fondamentale per generare lo Stato Patrimoniale.
     */
    public function contiContabili(): HasMany
    {
        return $this->hasMany(ContoContabile::class);
    }
}