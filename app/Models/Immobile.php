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
        // ⚠️ Assegnabili in massa, ma **non liberi**: che il principale stia nello stesso
        // condominio, che non sia l'unità stessa e che non sia a sua volta una pertinenza lo
        // verifica la FormRequest. Sono regole che si spiegano meglio con un messaggio che con un
        // errore SQL, ed è la ragione per cui non stanno nello schema.
        'pertinenza_di_immobile_id',
        'pertinenza_di_esterna',
        'nome',
        'descrizione',
        'interno',
        'piano',
        'superficie',
        'numero_vani',
        // ⚠️ `codice_unita` era qui e **la colonna non esiste**: sullo schema ci sono
        // `codice_immobile` e `codice_catasto`. Una chiave fillable che non corrisponde a una
        // colonna non dà errore — viene semplicemente ignorata al `create()` — quindi è il tipo di
        // riga che sopravvive per anni facendo credere che quel campo si possa valorizzare.
        //
        // `codice_immobile` resta fuori dal fillable **di proposito**: è NOT NULL, univoco a
        // livello globale, e lo genera `Immobile::booted()`. Renderlo assegnabile dall'esterno
        // significherebbe poter creare due unità con lo stesso codice da una richiesta HTTP.
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

    /**
     * L'unità di cui questa è pertinenza — il box che punta al suo appartamento.
     *
     * ⚠️ **Sostituisce le due `belongsToMany` su `immobile_pertinenza`, tolte nella beta.53.** La
     * cardinalità molti-a-molti modellava una cosa che il diritto non consente: l'art. 817 c.c.
     * chiede che i due beni appartengano allo **stesso proprietario**, e da lì discende che una
     * pertinenza ha un solo bene principale. Il caso che il commento invocava — «il box è condiviso
     * da 2 unità» — è comproprietà del box fra due persone, e vive in `anagrafica_immobile`; se
     * invece il box è comune a un gruppo di unità non è una pertinenza, è un bene ex art. 1117 c.c.
     *
     * **Nulla nel motore la legge, ed è deliberato:** il legame non sposta millesimi, riparto,
     * saldi, rate né quorum. È presentazione.
     */
    public function pertinenzaDi()
    {
        return $this->belongsTo(Immobile::class, 'pertinenza_di_immobile_id');
    }

    /**
     * Le pertinenze di questa unità — l'appartamento che raccoglie box, cantina e soffitta.
     *
     * È il lato «uno-a-molti» del legame: un principale ne ha quante ne ha, ciascuna ne ha uno.
     */
    public function pertinenze()
    {
        return $this->hasMany(Immobile::class, 'pertinenza_di_immobile_id');
    }

    /**
     * Questa unità è dichiarata pertinenza di qualcosa? Anche di un'unità fuori dal condominio.
     *
     * Le due colonne sono alternative: `pertinenza_di_immobile_id` quando il principale è qui,
     * `pertinenza_di_esterna` per il caso Tognoli, dove l'art. 9 co. 5 L. 122/1989 impone la
     * destinazione a un'unità nello stesso **comune** — che può stare in un altro condominio.
     */
    public function haUnPrincipale(): bool
    {
        return $this->pertinenza_di_immobile_id !== null
            || filled($this->pertinenza_di_esterna);
    }

    public function documenti()
    {
        return $this->morphMany(Documento::class, 'documentable');
    }

    public function saldi()
    {
        return $this->hasMany(Saldo::class);
    }

}
