<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pertinenza extends Model
{
        protected $table = 'immobile_pertinenza';

    protected $fillable = [
        'immobile_id',
        'pertinenza_id',
        'quota_possesso',
    ];

    // Se vuoi che Laravel tratti i timestamp
    public $timestamps = true;

    // Relazione con l'immobile principale
    public function immobile()
    {
        return $this->belongsTo(Immobile::class, 'immobile_id');
    }

    // Relazione con la pertinenza
    public function pertinenza()
    {
        return $this->belongsTo(Immobile::class, 'pertinenza_id');
    }
}
