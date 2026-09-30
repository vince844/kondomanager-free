<?php

namespace App\Traits;

use App\Models\User;
use App\Rules\CondominioDellUtente;
use Illuminate\Database\Eloquent\Model;

/**
 * Fin dove arriva un permesso largo (`EDIT_…`, `DELETE_…`) concesso **fuori dal pannello**, cioè a
 * un utente senza «Accesso pannello amministratore».
 *
 * Nei ruoli di serie i permessi larghi vanno sempre insieme al pannello (amministratore,
 * collaboratore), che gestisce l'intera installazione per disegno. Ma l'amministratore può
 * concederli da soli, con un ruolo su misura o un permesso diretto, e le rotte `user.*` hanno solo
 * il login: senza questo limite un condòmino con `EDIT_EVENTS` riscriveva la rata di un altro
 * palazzo, uno con `DELETE_ARCHIVE_DOCUMENTS` cancellava i documenti di tutti. Giro di sicurezza
 * della 1.11.0-beta.39: Coda 185, nata sulle segnalazioni e allargata a eventi, comunicazioni e
 * documenti. `SegnalazionePolicy` ne ha una copia propria, perché la segnalazione ha un solo
 * `condominio_id`; le policy aggiungono i loro filtri (gli eventi escludono quelli di sistema, i
 * documenti gli allegati del gestionale).
 *
 * Il record è nel perimetro solo se:
 * - l'utente ha un'anagrafica;
 * - il record non è indirizzato ad altre persone (anche nel suo palazzo: non lo vede in elenco, ma
 *   lo raggiunge per id);
 * - il record ha almeno un condominio, e **tutti** i suoi condomìni sono dell'utente.
 *
 * Non guarda visibilità, pubblicazione né autore: un record nascosto o in moderazione del proprio
 * palazzo resta nel perimetro. È una scelta rimandata al lavoro sui permessi dei ruoli su misura
 * (decisione di Vincenzo del 30/09/2026, in roadmap).
 *
 * Il modello deve avere le relazioni `anagrafiche()` e `condomini()`.
 *
 * @since v1.11.0-beta.39
 */
trait PerimetroFuoriPannello
{
    protected function nelPerimetroDellUtente(User $user, Model $record): bool
    {
        $anagraficaId = $user->anagrafica?->id;

        if ($anagraficaId === null) {
            return false;
        }

        if ($record->anagrafiche()->where('anagrafiche.id', '!=', $anagraficaId)->exists()) {
            return false;
        }

        $condominiIds = $record->condomini()->pluck('condomini.id');

        return $condominiIds->isNotEmpty()
            && $condominiIds->every(fn ($id) => CondominioDellUtente::appartiene($user, $id));
    }
}
