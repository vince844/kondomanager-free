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
}
