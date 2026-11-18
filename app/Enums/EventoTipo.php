<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums;

enum EventoTipo: string
{
    case COMMENTO = 'commento';
    case ALLEGATO = 'allegato';
    case VERIFICA_PAGAMENTO = 'verifica_pagamento';
    case SCADENZA_RATA_CONDOMINO = 'scadenza_rata_condomino';
    case EMISSIONE_RATA = 'emissione_rata';
    case CONTROLLO_INCASSI = 'controllo_incassi';
    case SEGNALAZIONE_GUASTO = 'segnalazione_guasto';
    case AGENDA = 'agenda';
    case SCADENZA = 'scadenza';
    case F24 = 'f24';
    case PAGAMENTO_FORNITORE = 'pagamento_fornitore';
    case EMISSIONE_RATA_SOPRAVVENIENZA = 'emissione_rata_sopravvenienza';
    case RATIFICA_SFORO = 'ratifica_sforo';
    case CONVOCAZIONE_URGENZA = 'convocazione_urgenza';
    case PIANIFICA_RIPIANAMENTO_DEFICIT = 'pianifica_ripianamento_deficit';
    case SCOPERTO_DOCUMENTATO = 'scoperto_documentato';

    /**
     * L'amministratore dichiara che un "già versato" (beta.26) è già stato
     * speso come acconto al fornitore prima di Kondomanager: nessuna
     * liquidità da registrare, ma resta un debito NON ancora riflesso quando
     * arriverà la fattura reale — vedi docs/fondo_accantonato_e_quadratura_sp.md D8-bis.
     */
    case GIA_VERSATO_ACCONTO_DICHIARATO = 'gia_versato_acconto_dichiarato';

    /**
     * Il netting del già-versato (beta.26) ha rilevato che una o più unità
     * hanno versato più del dovuto per una voce di spesa: la quota non va
     * sotto zero (CalcoloQuoteService::getEccedenzeCopertura()), ma prima di
     * questo case l'eccedenza finiva solo nei log del server — invisibile
     * all'amministratore. È denaro dei condòmini, da restituire o
     * conguagliare, non un errore.
     */
    case ECCEDENZA_GIA_VERSATO_RILEVATA = 'eccedenza_gia_versato_rilevata';

    /**
     * Un promemoria nell'agenda scritto dall'amministratore con «Ricordamelo» o «Mettilo in agenda» (i sei mesi per agire, i 180 giorni del rendiconto): non è
     * un compito (`meta.requires_action` falso), riporta al compito o alla riga da cui è nato.
     */
    case PROMEMORIA = 'promemoria';

    /**
     * Ciò che aspetta un'approvazione (decisioni 445 e 449): per ogni elemento un compito solo, con i commenti e gli allegati in attesa di una segnalazione o
     * di una comunicazione, o l'elemento stesso (la comunicazione, il documento dell'archivio, l'evento dell'agenda). Lo scrive `CompitoDaModerare`.
     */
    case DA_MODERARE = 'da_moderare';
}
