<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services;

use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Services\Gestionale\Inbox\CompitoDaModerare;

/**
 * I commenti nell'Action inbox. Un commento non ha più un compito suo («Nuovo commento — …»): ciò che aspetta una moderazione confluisce nel compito unico
 * dell'elemento ({@see CompitoDaModerare}), e un commento pubblicato senza moderazione non crea niente (decisione 445, punto 2: restano le notifiche e la
 * pagina «Commenti»). Il compito segue anche i modelli da solo ({@see \App\Services\Gestionale\Inbox\ModerazioneNellInbox}); questo punto d'ingresso resta per
 * chi lo chiama dopo aver creato o riportato in attesa un commento.
 */
class CommentoEventoService
{
    /** Porta il compito dell'elemento commentato a com'è adesso: i commenti in attesa, letti dal database. */
    public function sincronizza(Commento $commento): void
    {
        $elemento = $commento->commentable;
        if ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione) {
            app(CompitoDaModerare::class)->sincronizza($elemento, $commento->autore);
        }
    }
}
