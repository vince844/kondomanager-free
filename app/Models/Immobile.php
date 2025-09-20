<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Immobile extends Model
{
    
    use HasFactory;

    protected $table = 'immobili';

    protected $fillable = [
        'condominio_id',
        'palazzina_id',
        'scala_id',
        'tipologia_id',
        'nome',
        'descrizione',
        'interno',
        'piano',
        'superficie',
        'numero_vani',
        'codice_unita',
        'comune_catasto',
        'sezione_catasto',
        'foglio_catasto',
        'particella_catasto',
        'subalterno_catasto',
        'codice_catasto',
        'attivo',
        'note',
    ];

    protected static function booted()
    {
        static::creating(function ($immobile) {
            // Only generate if not manually assigned
            if (! $immobile->codice_immobile) {
                $lastCode = Immobile::where('condominio_id', $immobile->condominio_id)
                    ->orderByDesc('id')
                    ->value('codice_immobile');

                $nextNumber = 1;
                if ($lastCode && preg_match('/\d+$/', $lastCode, $matches)) {
                    $nextNumber = intval($matches[0]) + 1;
                }

                // Example format: C2-0004 → "C{condominio_id}-{progressive}"
                $immobile->codice_immobile = 'C' . $immobile->condominio_id . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    // Relazione con il condominio
    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }

    // Relazione con la palazzina
    public function palazzina()
    {
        return $this->belongsTo(Palazzina::class);
    }

    // Relazione con la scala
    public function scala()
    {
        return $this->belongsTo(Scala::class);
    }

    // Relazione con la tipologia dell’immobile
    public function tipologiaImmobile()
    {
        return $this->belongsTo(TipologiaImmobile::class, 'tipologia_id');
    }

    // Relazione molti-a-molti con anagrafiche (proprietari, inquilini, usufruttuari)
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

    // Immobile → pertinenze collegate (es. l’appartamento ha box e cantina)
    public function pertinenze()
    {
        return $this->belongsToMany(
            Immobile::class,
            'immobile_pertinenza',
            'immobile_id',
            'pertinenza_id'
        )->withPivot('quota_possesso')->withTimestamps();
    }

    // Pertinenza → immobili collegati (es. il box è condiviso da 2 unità)
    public function immobiliPrincipali()
    {
        return $this->belongsToMany(
            Immobile::class,
            'immobile_pertinenza',
            'pertinenza_id',
            'immobile_id'
        )->withPivot('quota_possesso')->withTimestamps();
    }

    public function documenti()
    {
        return $this->morphMany(Documento::class, 'documentable');
    }
}
