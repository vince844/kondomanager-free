<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Condominio extends Model
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    protected $table = 'condomini';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
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
    
}
