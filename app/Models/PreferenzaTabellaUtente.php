<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quante righe un utente vuole vedere su un dato elenco.
 *
 * Una riga per coppia utente-elenco, con `chiave` uguale al nome della rotta dell'indice. Il
 * perché di una tabella invece di una colonna JSON su `users` sta nella migrazione che la crea.
 *
 * ⚠️ **Non è configurazione: è una comodità.** Se questa tabella fosse vuota, o venisse svuotata,
 * il programma funzionerebbe esattamente come prima — gli elenchi ripiegherebbero sul valore delle
 * impostazioni generali. È una proprietà da mantenere: nessuna logica deve dipendere dal fatto che
 * una preferenza *esista*, solo dal valore risolto.
 */
class PreferenzaTabellaUtente extends Model
{
    use HasFactory;

    protected $table = 'preferenze_tabelle_utente';

    protected $fillable = ['user_id', 'chiave', 'per_page'];

    protected $casts = ['per_page' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
