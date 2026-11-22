<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Models\Assemblee\Delibera;
use App\Models\Condominio;
use App\Models\User;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\SchedaDelCondominio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * «Scegli la delibera» nelle pagine di oggi (§1.4 e §3 P11 del progetto della tappa 2): la delibera che arriva con la richiesta (`delibera_id`), la delibera
 * già collegata da mostrare nella pagina, il collegamento dopo il salvataggio.
 *
 * - La delibera è **del condominio della rotta** (R18): una di un altro condominio è 404.
 * - Collegare una delibera chiede «Gestisce le assemblee» (`AccessoAlleAssemblee`); senza delibera la pagina lavora come prima, con la data scritta a mano.
 * - Le colonne di oggi restano la verità dei motori (R1): la pagina le scrive con `deliberata_il` e il numero del verbale della riunione, per la sua strada;
 *   il collegamento aggiunge la provenienza, dopo il clic su «Salva» (R3).
 */
final class DeliberaNelleSchede
{
    /** La delibera della richiesta, risolta nel condominio; `null` se non c'è. */
    public static function dallaRichiesta(Request $request, Condominio $condominio, string $campo = 'delibera_id'): ?Delibera
    {
        $valore = $request->input($campo);
        if ($valore === null || $valore === '') {
            return null;
        }
        if (filter_var($valore, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages([$campo => 'La delibera scelta non è valida: scegline una dal menu.']);
        }
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));

        /** @var Delibera $delibera */
        $delibera = SchedaDelCondominio::risolvi($condominio, 'delibera', (int) $valore);

        return $delibera;
    }

    /** Il giorno della riunione (Y-m-d). */
    public static function giorno(Delibera $delibera): ?string
    {
        return $delibera->deliberata_il?->format('Y-m-d');
    }

    /** Il numero del verbale della riunione della delibera: quello del suo giorno (una riunione sospesa e proseguita ne ha due), come dice la frase. */
    public static function numeroDelVerbale(Delibera $delibera): ?string
    {
        return DeliberaInParole::numeroDelVerbale($delibera);
    }

    /** Lega la delibera alla scheda con il ruolo, dopo il salvataggio (una riga del registro sulla scheda). Senza delibera non fa niente. */
    public static function collega(Model $scheda, string $ruolo, ?Delibera $delibera, ?User $utente, ?string $nota = null): void
    {
        if ($delibera === null || $utente === null) {
            return;
        }
        CollegamentiDelleDelibere::collega($scheda, $ruolo, $delibera, $nota, $utente);
    }

    /** La delibera già collegata, nella forma del menu di «Scegli la delibera», per una pagina di modifica. @return array<string, mixed>|null */
    public static function perLaPagina(?Model $scheda, string $ruolo): ?array
    {
        $delibera = $scheda === null ? null : CollegamentiDelleDelibere::delibera($scheda, $ruolo);
        if ($delibera === null) {
            return null;
        }
        [$si, $perche] = DelibereDelCondominio::siSceglie($delibera, $ruolo);

        return DelibereDelCondominio::riga($delibera, $si, $perche) + ['livello' => 1, 'perche_proposta' => null, 'conseguenza' => null];
    }
}
