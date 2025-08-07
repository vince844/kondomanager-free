<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Immobile extends Model
{
    
    use HasFactory;

    protected $fillable = [
        'condominio_id',
        'palazzina_id',
        'scala_id',
        'tipologia',
        'interno',
        'piano',
        'superficie',
        'numero_vani',
        'codice_unita',
        'comune_catasto',
        'sezione_catasto',
        'foglio_catasto',
        'particella_catasto',
        'attivo',
        'note',
    ];

    // 🔁 Relazione con il condominio
    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    // 🔁 Relazione con la palazzina
    public function palazzina()
    {
        return $this->belongsTo(Palazzina::class);
    }

    // 🔁 Relazione con la scala
    public function scala()
    {
        return $this->belongsTo(Scala::class);
    }

    // 🔁 Relazione con la tipologia dell’immobile
    public function tipologiaImmobile()
    {
        return $this->belongsTo(TipologiaImmobile::class, 'tipologia_id');
    }

    // 🔁 Relazione molti-a-molti con anagrafiche (proprietari, inquilini, usufruttuari)
    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_immobile')
            ->withPivot([
                'tipologia',
                'quota',
                'tipologie_spese',
                'data_inizio',
                'data_fine',
                'attivo',
                'note',
            ])
            ->withTimestamps();
    }

        // ✅ Immobile → pertinenze collegate (es. l’appartamento ha box e cantina)
    public function pertinenze()
    {
        return $this->belongsToMany(
            Immobile::class,
            'immobile_pertinenza',
            'immobile_id',
            'pertinenza_id'
        )->withPivot('quota_possesso')->withTimestamps();
    }

    // ✅ Pertinenza → immobili collegati (es. il box è condiviso da 2 unità)
    public function immobiliPrincipali()
    {
        return $this->belongsToMany(
            Immobile::class,
            'immobile_pertinenza',
            'pertinenza_id',
            'immobile_id'
        )->withPivot('quota_possesso')->withTimestamps();
    }
}
