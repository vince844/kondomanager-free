<?php

namespace App\Enums;

enum EventoTipo: string
{
    case COMMENTO = 'commento';
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
}
