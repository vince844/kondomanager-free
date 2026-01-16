<?php

namespace App\Models\Gestionale;

use App\Enums\StatoPianoRate;
use App\Models\Condominio;
use App\Models\Gestione;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PianoRate extends Model
{
    use HasFactory;

    protected $table = 'piani_rate';

    protected $fillable = [
        'gestione_id',
        'condominio_id',
        'nome',
        'descrizione',
        'metodo_distribuzione',
        'numero_rate',
        'giorno_scadenza',
        'data_inizio',
        'attivo',
        'note',
        'stato',
    ];

    protected $casts = [
        'stato'       => StatoPianoRate::class,
        'data_inizio' => 'date',
        'attivo'      => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELAZIONI
    |--------------------------------------------------------------------------
    */

    public function gestione()
    {
        return $this->belongsTo(Gestione::class);
    }

    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    public function ricorrenza()
    {
        return $this->hasOne(RicorrenzaRata::class);
    }

    public function rate()
    {
        return $this->hasMany(Rata::class);
    }

    /**
     * I capitoli di spesa inclusi in questo piano rate.
     * Se la collezione è vuota, si intende che include TUTTI i capitoli della gestione.
     */
    public function capitoli(): BelongsToMany
    {
        return $this->belongsToMany(Conto::class, 'piano_rate_capitoli', 'piano_rate_id', 'conto_id')
                    ->withTimestamps();
    }

    
}

