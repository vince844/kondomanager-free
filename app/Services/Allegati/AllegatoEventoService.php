<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Allegati;

use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Gestionale\Inbox\CompitoDaModerare;

/**
 * Gli allegati nell'Action inbox. Un allegato non ha più un compito suo («Allegati da approvare — …»): gli allegati in attesa confluiscono nel compito unico
 * dell'elemento ({@see CompitoDaModerare}), con i numeri letti dal database. Questi punti d'ingresso restano per i percorsi che cancellano gli allegati con una
 * sola query, senza gli eventi dei modelli ({@see \App\Services\Gestionale\Inbox\ModerazioneNellInbox}).
 */
final class AllegatoEventoService
{
    public function sincronizza(Segnalazione|Comunicazione $elemento, ?User $autore = null): void
    {
        app(CompitoDaModerare::class)->sincronizza($elemento, $autore);
    }

    /** L'elemento non ha più allegati in attesa (o non c'è più): il suo compito si porta a com'è. */
    public function chiudiPerElemento(Segnalazione|Comunicazione $elemento): void
    {
        $compito = app(CompitoDaModerare::class);
        // L'elemento tolto con la chiave esterna (l'utente o il condominio eliminati) non ha più una pagina: il suo compito sparisce.
        $elemento->newQuery()->whereKey($elemento->getKey())->exists() ? $compito->sincronizza($elemento) : $compito->togli($elemento);
    }
}
