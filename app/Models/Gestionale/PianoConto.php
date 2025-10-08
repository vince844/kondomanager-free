<?php

namespace App\Models\Gestionale;

use App\Models\Gestione;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PianoConto extends Model
{
    use HasFactory;

    protected $table = 'piani_conti';

    protected $fillable = [
        'gestione_id',
        'nome',
        'descrizione',
        'note',
    ];

    /** RELAZIONI */
    public function gestione()
    {
        return $this->belongsTo(Gestione::class);
    }

    public function conti()
    {
        return $this->hasMany(Conto::class, 'piano_conto_id');
    }
}
