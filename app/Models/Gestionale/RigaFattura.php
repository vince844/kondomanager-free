<?php

namespace App\Models\Gestionale;

use App\Models\Immobile;
use Illuminate\Database\Eloquent\Model;

class RigaFattura extends Model
{
     protected $table = 'righe_fattura';
    protected $guarded = ['id'];

    protected $casts = [
        'dati_extra'         => 'array',
        'importo_imponibile' => 'integer',
        'importo_iva'        => 'integer',
    ];

    public function fattura()
    {
        return $this->belongsTo(FatturaPassiva::class);
    }

    public function conto()
    {
        return $this->belongsTo(Conto::class);
    }

    public function immobile()
    {
        return $this->belongsTo(Immobile::class);
    }
}
