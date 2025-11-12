<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PianoRate extends Model
{
    use HasFactory;

    protected $table = 'piani_rate';

    protected $fillable = [
        'gestione_id',
        'condominio_id',
        'nome',
        'descrizione',
        'metodo_calcolo',
        'numero_rate',
        'giorno_scadenza',
        'data_inizio',
        'attivo',
        'note',
    ];

    protected $casts = [
        'data_inizio' => 'date',
        'attivo' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELAZIONI
    |--------------------------------------------------------------------------
    */

    public function gestione()
    {
        return $this->belongsTo(\App\Models\Gestione::class);
    }

    public function condominio()
    {
        return $this->belongsTo(\App\Models\Condominio::class);
    }

    public function ricorrenza()
    {
        return $this->hasOne(\App\Models\Gestionale\RicorrenzaRata::class);
    }

    public function rate()
    {
        return $this->hasMany(\App\Models\Gestionale\Rata::class);
    }

    
}

