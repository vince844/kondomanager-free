<?php

namespace App\Models;

use App\Traits\HasCustomIdentifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * The anagrafiche that belong to the building.
     */
    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class);
    }

     /**
     * The comunicazioni that belong to the condominio.
     */
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
        return $this->belongsToMany(Evento::class, 'condominio_evento', 'condominio_id', 'evento_id');
    }

}
