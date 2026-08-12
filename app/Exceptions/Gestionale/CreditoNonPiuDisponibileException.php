<?php

namespace App\Exceptions\Gestionale;

/**
 * La rata scelta come fonte di credito **non ha più credito** da prelevare.
 *
 * ## Perché succede a chi non ha sbagliato niente
 *
 * La schermata di incasso calcola i crediti disponibili quando la si apre. Se nel frattempo quel
 * credito è stato consumato — da un altro incasso, da un altro operatore, o dallo stesso
 * amministratore in un'altra scheda — la pagina resta con i numeri vecchi e propone un credito
 * che a database non c'è più. Non è un dato corrotto: è una pagina invecchiata.
 *
 * Per questo il messaggio dice di **ricaricare**, che è il rimedio, invece di limitarsi a negare.
 *
 * ## Il messaggio che c'era prima
 *
 * `'Quota credito non trovata per rata_id: 4127'`, come `RuntimeException` — quindi pagina 500.
 * All'amministratore arrivava un numero interno di riga, che non compare da nessuna parte a
 * schermo e non gli dice né cosa è successo né cosa fare. Il numero della rata resta utile a chi
 * legge i registri, e infatti è ancora qui: ma accanto a una frase che si capisce.
 */
class CreditoNonPiuDisponibileException extends IncassoNonRegistrabileException
{
    public function __construct(protected int|string $rataId)
    {
        parent::__construct(sprintf(
            'Il credito che hai scelto di utilizzare non è più disponibile: potrebbe essere stato '
            . 'consumato da un altro incasso dopo che hai aperto questa schermata. Ricarica la '
            . 'pagina per vedere i crediti aggiornati e ripeti la registrazione. (Riferimento '
            . 'interno: quota %s.)',
            $this->rataId
        ));
    }

    public function campo(): string
    {
        return 'dettaglio_pagamenti';
    }
}
