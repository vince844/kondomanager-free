<?php

namespace App\Models;

use App\Traits\RisolveIFigliDelleRotte;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\ScritturaContabile;
use App\Traits\HasCustomIdentifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Condominio extends Model
{
    use RisolveIFigliDelleRotte;

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

     /**
     * Le scritture contabili associate a questo condominio (Libro Giornale).
     */
    public function scrittureContabili(): HasMany
    {
        return $this->hasMany(ScritturaContabile::class);
    }

    /**
     * ## Le sei relazioni aggiunte nella beta.66, e perché stanno qui
     *
     * Servono allo *scoping* delle rotte annidate: `/gestionale/{condominio}/fatture/{fattura}` deve
     * poter rifiutare la fattura di un **altro** condominio, e Laravel lo fa chiedendo al padre una
     * relazione verso il figlio. Senza, quella rotta serviva il dato altrui senza dire niente.
     *
     * ⚠️ **Non sono un'impalcatura per il binding.** Le sei tabelle hanno già `condominio_id` —
     * verificato il 22/08/2026 — quindi queste relazioni descrivono un legame che esiste da sempre e
     * che semplicemente non era mai stato dichiarato. Sono relazioni che ci starebbero comunque.
     */
    public function fatture(): HasMany
    {
        return $this->hasMany(FatturaPassiva::class);
    }

    public function deleghe(): HasMany
    {
        return $this->hasMany(DelegaF24::class);
    }

    public function pagamenti(): HasMany
    {
        return $this->hasMany(PagamentoFornitore::class);
    }

    public function saldi(): HasMany
    {
        return $this->hasMany(Saldo::class);
    }

    public function pianiRate(): HasMany
    {
        return $this->hasMany(PianoRate::class);
    }

    /**
     * Tutti i conti del condominio, attraverso i suoi piani dei conti.
     *
     * ⚠️ **`conti` non ha `condominio_id`**: pende da `piano_conto_id`, e il piano dei conti ha il
     * condominio. Da qui il `hasManyThrough`, che è il legame vero e non una scorciatoia — un
     * `condominio_id` denormalizzato su `conti` sarebbe una seconda fonte di verità da tenere
     * allineata a mano.
     *
     * Serve allo scoped binding di `/gestionale/{condominio}/contributi/{conto}`: senza, quella
     * rotta accetta il conto di un altro condominio. Verificato il 22/08/2026 sui dati reali: il
     * condominio 16 restituisce 7 conti su 27 in archivio.
     */
    public function conti(): HasManyThrough
    {
        return $this->hasManyThrough(Conto::class, PianoConto::class);
    }

    public function lottiImportazione(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    /**
     * Le rotte annidate sotto questo modello, e la relazione che porta a ciascun figlio.
     *
     * Vedi il blocco in testa a `App\Traits\RisolveIFigliDelleRotte` per il perché serve: Laravel
     * deriverebbe il nome con una pluralizzazione inglese, e su nomi italiani sbaglia sempre.
     *
     * @return array<string, string>
     */
    protected function relazioniDeiFigliNelleRotte(): array
    {
        return [
            'esercizio' => 'esercizi',
            'immobile' => 'immobili',
            'tabella' => 'tabelle',
            'cassa' => 'casse',
            'palazzina' => 'palazzine',
            'scala' => 'scale',
            'anagrafica' => 'anagrafiche',
            'scrittura' => 'scrittureContabili',
            'fattura' => 'fatture',
            'delega' => 'deleghe',
            'pagamento' => 'pagamenti',
            'saldo' => 'saldi',
            'batch' => 'lottiImportazione',
            'pianoRate' => 'pianiRate',
            'conto' => 'conti',
        ];
    }

}