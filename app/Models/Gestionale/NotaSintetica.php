<?php

namespace App\Models\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La bozza della nota sintetica esplicativa del rendiconto di un esercizio: una per esercizio, mentre l'amministratore la scrive. Il contenuto è un
 * JSON (le parti con il loro testo, lo stato dei fatti proposti, gli elenchi, la dichiarazione sulle liti, le spiegazioni dei controlli). Quando il
 * rendiconto si ferma per l'assemblea, la versione ne copia il contenuto com'è quel giorno.
 */
class NotaSintetica extends Model
{
    protected $table = 'note_sintetiche';

    protected $fillable = ['condominio_id', 'esercizio_id', 'contenuto', 'aggiornata_da'];

    protected $casts = ['contenuto' => 'array'];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    public function aggiornataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aggiornata_da');
    }
}
