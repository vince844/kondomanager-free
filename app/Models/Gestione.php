<?php

namespace App\Models;

use App\Models\Gestionale\PianoConto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Gestione extends Model
{
    use HasFactory;

    protected $table = 'gestioni';

    protected $fillable = [
        'condominio_id',
        'nome',
        'descrizione',
        'tipo',
        'attiva',
        'data_inizio',
        'data_fine',
        'note',
    ];

    protected $casts = [
        'data_inizio' => 'date',
        'data_fine'   => 'date',
    ];

    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizi()
    {
        return $this->belongsToMany(Esercizio::class, 'esercizio_gestione')
            ->withPivot(['attiva', 'data_inizio', 'data_fine'])
            ->withTimestamps();
    }

    public function pianoConto() 
    {
        return $this->hasOne(PianoConto::class); 
    }
}
