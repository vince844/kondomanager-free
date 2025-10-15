<?php

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Cknow\Money\Casts\MoneyCast;

class Conto extends Model
{
    use HasFactory;

    protected $table = 'conti';

    protected $fillable = [
        'piano_conto_id',
        'parent_id',
        'nome',
        'descrizione',
        'tipo',
        'importo',
        'destinazione_id',
        'destinazione_tipo',
        'note',
    ];
    
    /** RELAZIONI */
    public function pianoConto()
    {
        return $this->belongsTo(PianoConto::class, 'piano_conto_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function sottoconti()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function destinazione()
    {
        return $this->morphTo();
    }

    public function tabelleMillesimali()
    {
        return $this->hasMany(ContoTabellaMillesimale::class);
    }
}
