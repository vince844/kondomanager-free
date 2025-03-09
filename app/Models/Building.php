<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Building extends Model
{
    
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
}
