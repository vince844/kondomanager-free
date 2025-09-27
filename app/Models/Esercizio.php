<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Esercizio extends Model
{
    use HasFactory;

    protected $table = 'esercizi';

    protected $fillable = [
        'condominio_id',
        'nome',
        'descrizione',
        'data_inizio',
        'data_fine',
        'stato',
        'note', 
    ];

    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

/*     public function gestioni()
    {
        return $this->hasMany(Gestione::class);
    } */
}
