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

    /**
     * Le assemblee (Coda 679, §4.6 e R10 del progetto della tappa 2). Il memo «Da portare in assemblea» con «Ricordamelo dal …»: un compito solo, con il memo
     * come `eventable`, e le risposte passano dal memo. La convocazione da spedire entro il giorno, le ricevute mancanti, il nuovo titolare che non ha
     * ricevuto la convocazione: li scrive la convocazione.
     */
    case MEMO_ASSEMBLEA = 'memo_assemblea';
    case SPEDIRE_CONVOCAZIONE = 'spedire_convocazione';
    case RICEVUTE_MANCANTI = 'ricevute_mancanti';
    case NUOVO_TITOLARE_NON_CONVOCATO = 'nuovo_titolare_non_convocato';

    /**
     * Il verbale e la sala (la tappa del verbale; decisioni 595 (1) e 604 (3)): il verbale da registrare dalla chiusura della riunione, da trasmettere dalla
     * registrazione; la sala da riaprire nel giorno della prosecuzione, l'assemblea rimasta in corso il giorno dopo, le due versioni da scegliere, la delega o
     * l'apertura da decidere. Li scrive e li chiude `CompitiDelVerbale`.
     */
    case REGISTRARE_VERBALE = 'registrare_verbale';
    case TRASMETTERE_VERBALE = 'trasmettere_verbale';
    case RIAPRIRE_SALA = 'riaprire_sala';
    case SALA_IN_CORSO = 'sala_in_corso';
    case DUE_VERSIONI = 'due_versioni';
    case DA_DECIDERE_IN_SALA = 'da_decidere_in_sala';

    /**
     * Il seguito delle delibere (decisioni 522 e 523): la cosa da fare dopo una delibera, con la sua data («Crea il piano straordinario entro il 15/01»), e la
     * delibera respinta che chiede una risposta («Riproponi in assemblea» o «Chiudi con il motivo»). Le scrive e le chiude `CoseDaFare`.
     */
    case DELIBERA_DA_ESEGUIRE = 'delibera_da_eseguire';
    case DELIBERA_RESPINTA = 'delibera_respinta';

    /**
     * L'avviso di un errore (1.12.0-beta.14, decisione 698 (4)): un condòmino o un collaboratore ha avvisato lo studio dalla pagina d'errore. Un compito per
     * errore, con l'errore come `eventable`; lo leggono solo quelli che hanno «Gestisci impostazioni generali». Lo scrive `CompitoDellErrore`.
     */
    case ERRORE_AVVISATO = 'errore_avvisato';
}
