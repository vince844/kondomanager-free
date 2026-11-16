<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Evento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * La risposta dell'amministratore a un promemoria che il programma calcola (la riga dei sei mesi di un esercizio, una persona in quella riga, i 180 giorni di
 * un rendiconto) o a un compito dell'agenda: lo stato di oggi (agito, fatto, non serve, ricordamelo, o nessuno dopo «Riapri»), l'ultima nota, i dati che
 * servono per decidere se la persona o il compito tornano, e il registro di ogni gesto. Lo scrive solo il server ({@see \App\Services\Gestionale\RispostePromemoria}).
 *
 * La riga della persona (argomento `persona`, senza esercizio) tiene il fascicolo per il legale: a ogni fascicolo una riga del registro, e l'indice e il
 * prospetto di quel giorno come documenti della riga ({@see documenti()}), fuori dall'archivio e dal portale.
 */
class RispostaPromemoria extends Model
{
    protected $table = 'risposte_promemoria';

    protected $fillable = [
        'condominio_id', 'argomento', 'evento_id', 'titolo', 'tipo_compito', 'esercizio_id', 'anagrafica_id', 'chiave', 'stato', 'giorno', 'ricordami_il',
        'promemoria_evento_id', 'agenda_evento_id', 'nota', 'dati', 'registro', 'aggiornata_da',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'evento_id' => 'integer',
        'esercizio_id' => 'integer',
        'anagrafica_id' => 'integer',
        'promemoria_evento_id' => 'integer',
        'agenda_evento_id' => 'integer',
        'aggiornata_da' => 'integer',
        'giorno' => 'date',
        'ricordami_il' => 'date',
        'dati' => 'array',
        'registro' => 'array',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class, 'anagrafica_id');
    }

    public function promemoria(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'promemoria_evento_id');
    }

    public function agenda(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'agenda_evento_id');
    }

    /** L'indice e il prospetto di ogni fascicolo per il legale della persona: documenti della riga, mai nell'archivio né nel portale. */
    public function documenti(): MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }
}
