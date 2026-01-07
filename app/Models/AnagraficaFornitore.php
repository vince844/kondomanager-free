<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnagraficaFornitore extends Model
{
    protected $table = 'anagrafica_fornitore';

    protected $fillable = [
        'fornitore_id',
        'anagrafica_id',
        'ruolo',
        'referente_principale',
    ];

    // Relazioni
    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class);
    }

    public function anagrafica()
    {
        return $this->belongsTo(Anagrafica::class);
    }
}
