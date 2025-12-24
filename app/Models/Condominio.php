<?php

namespace App\Models;

use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Traits\HasCustomIdentifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'email',   
        'note',              
        'codice_fiscale',       
        'comune_catasto',     
        'codice_catasto',      
        'sezione_catasto', 
        'foglio_catasto',    
        'particella_catasto',
    ];

    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class);
    }

    public function comunicazioni()
    {
        return $this->belongsToMany(Comunicazione::class, 'comunicazione_condominio')->withTimestamps();
    }

    public function documenti()
    {
        return $this->belongsToMany(Documento::class, 'condominio_documento');
    }

    public function eventi()
    {
        return $this->belongsToMany(Evento::class, 'condominio_evento');
    }

    public function palazzine()
    {
        return $this->hasMany(Palazzina::class);
    }

    public function scale()
    {
        return $this->hasMany(Scala::class);
    }

    public function immobili()
    {
        return $this->hasMany(Immobile::class);
    }

    public function tabelle()
    {
        return $this->hasMany(Tabella::class);
    }

    public function esercizi()
    {
        return $this->hasMany(Esercizio::class);
    }

    public function gestioni()
    {
        return $this->hasMany(Gestione::class);
    }

    public function pianiDeiConti()
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
