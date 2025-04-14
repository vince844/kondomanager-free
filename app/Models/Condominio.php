<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

    protected static function booted()
    {
        static::creating(function ($condominio) {
            if (!$condominio->codice_identificativo) {
                $condominio->codice_identificativo = self::generateUniqueCodice();
            }
        });
        
    }

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

    /**
     * Generate unique code for condominio
     */
    public static function generateUniqueCodice()
    {
        do {
            $codice_identificativo = 'BLD-' . Str::upper(Str::random(3)) . rand(100, 999);
        } while (self::where('codice_identificativo', $codice_identificativo)->exists());

        return $codice_identificativo;
    }
    
}
