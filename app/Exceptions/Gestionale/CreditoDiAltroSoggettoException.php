<?php

namespace App\Exceptions\Gestionale;

/**
 * Si sta usando il credito di una persona che **non condivide l'unità** con il pagante.
 *
 * ## Cosa impedisce, e perché non è un divieto assoluto
 *
 * Compensare il credito di Tizio su un debito di Caio sposta denaro fra due patrimoni. Fra
 * comproprietari della **stessa** unità è normale e il gestionale lo consente (il ramo subito
 * sopra il lancio): la ricerca per immobile raccoglie le quote di tutti, e nessuno si stupisce
 * che il marito paghi con il credito della moglie sull'appartamento che possiedono insieme.
 *
 * Fuori da quell'unità non c'è niente che leghi i due soggetti, e il gestionale non ha modo di
 * sapere se l'operazione è voluta o è un clic sulla riga sbagliata in un elenco lungo. Rifiuta e
 * indica la via: registrare il rimborso fra i due come movimento a sé.
 */
class CreditoDiAltroSoggettoException extends IncassoNonRegistrabileException
{
    public function __construct()
    {
        parent::__construct(
            'Il credito selezionato è intestato a un altro soggetto, che non risulta collegato '
            . "a quell'unità immobiliare: non può essere usato per pagare questo debito. "
            . 'Scegli un credito del pagante, oppure registra separatamente il rimborso fra i due soggetti.'
        );
    }

    public function campo(): string
    {
        return 'dettaglio_pagamenti';
    }
}
