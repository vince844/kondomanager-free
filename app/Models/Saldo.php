<?php

namespace App\Models;

use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;

class Saldo extends Model
{
    protected $table = 'saldi';

    protected $fillable = [
        'esercizio_id',
        'condominio_id',
        'anagrafica_id',
        'immobile_id',
        'saldo_iniziale',
        'saldo_finale',
    ];

    protected $casts = [
        'saldo_iniziale' => MoneyIntegerCast::class . ':EUR',
        'saldo_finale'   => MoneyIntegerCast::class . ':EUR',
    ];

    public function esercizio() 
    { 
        return $this->belongsTo(Esercizio::class); 
    }

    public function condominio() 
    { 
        return $this->belongsTo(Condominio::class); 
    }

    public function anagrafica()
    { 
        return $this->belongsTo(Anagrafica::class); 
    }

    public function immobile() 
    { 
        return $this->belongsTo(Immobile::class); 
    }
}
