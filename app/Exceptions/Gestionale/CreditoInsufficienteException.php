<?php

namespace App\Exceptions\Gestionale;

use App\Helpers\MoneyHelper;

/**
 * Si sta prelevando **più credito di quanto ce ne sia**.
 *
 * Come `CreditoNonPiuDisponibileException`, il caso normale non è un errore di chi compila ma una
 * schermata invecchiata: il credito era di € 300 quando la pagina è stata aperta, nel frattempo
 * ne sono stati usati € 120, e la riga ne chiede ancora 300.
 *
 * Il messaggio porta **i due numeri**. È la lezione della beta.43 sulla guardia di quadratura: un
 * rifiuto senza la cifra sembra un guasto, un rifiuto con la cifra è un conto da correggere — e
 * qui la cifra dice anche esattamente quanto si può prelevare, quindi la correzione è immediata.
 */
class CreditoInsufficienteException extends IncassoNonRegistrabileException
{
    /**
     * @param  int  $disponibileCents  Credito effettivamente residuo, in centesimi.
     * @param  int  $richiestoCents    Credito che la riga vorrebbe prelevare, in centesimi.
     */
    public function __construct(
        protected int $disponibileCents,
        protected int $richiestoCents,
    ) {
        parent::__construct(sprintf(
            'Il credito disponibile non basta per questa compensazione: puoi utilizzarne al '
            . 'massimo %s, mentre la riga ne preleva %s. Riduci l\'importo prelevato dal credito, '
            . 'oppure ricarica la pagina se il credito è stato usato altrove nel frattempo.',
            MoneyHelper::format($this->disponibileCents),
            MoneyHelper::format($this->richiestoCents),
        ));
    }

    public function campo(): string
    {
        return 'dettaglio_pagamenti';
    }
}
