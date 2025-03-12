<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Building extends Model
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    
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

    public function users()
    {
        return $this->belongsToMany(User::class);
    }
}


