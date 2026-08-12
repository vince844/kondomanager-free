<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Base delle guardie di dominio di `StoreIncassoRateAction`: l'incasso **non si può registrare**,
 * e non è un guasto.
 *
 * ## Perché una base e non l'ennesima eccezione dedicata
 *
 * `IncassoRateController::store()` cattura **per tipo**. Ogni guardia che solleva un tipo che il
 * controller non conosce arriva all'amministratore come **pagina 500**, con la distribuzione
 * appena fatta a mano buttata via.
 *
 * Questo progetto lo ha corretto due volte, ogni volta per la singola guardia che stava
 * scrivendo: la beta.43 con `TotaleIncassoNonCorrispondenteException`, la beta.48 con
 * `DebitoNonDelPaganteException`. Alla terza — le tre `RuntimeException` sulle compensazioni a
 * credito, coda ⑬ — la lezione è che **si chiude la classe, non il caso**: da qui in avanti una
 * guardia nuova estende questa base e il controller la cattura senza sapere che esiste.
 *
 * ## `campo()` non è un dettaglio di forma
 *
 * Dice **quale casella l'amministratore deve cambiare**, ed è l'informazione che trasforma un
 * rifiuto in un'istruzione: «l'importo non quadra» va sull'importo, «quel debito è di un altro»
 * va sul pagante. Sbagliare campo manda a correggere la cosa giusta nel posto sbagliato.
 */
abstract class IncassoNonRegistrabileException extends Exception
{
    /**
     * Il campo del modulo su cui mostrare il messaggio.
     *
     * ⚠️ Deve essere un campo che la schermata **rende davvero**. Fino alla beta.49
     * `IncassoRateNew.vue` mostrava gli errori solo per `cassa_id` e `data_pagamento`: i
     * messaggi delle guardie tornavano al browser e non comparivano da nessuna parte, e il
     * pulsante sembrava semplicemente non funzionare. La schermata ha ora una fascia che
     * raccoglie **tutti** gli errori, quindi qualunque campo arriva a destinazione — ma se un
     * giorno quella fascia sparisse, tornerebbe il silenzio.
     */
    abstract public function campo(): string;

    /**
     * Fuori dal registro degli errori.
     *
     * Un amministratore che sceglie il pagante sbagliato non è un guasto dell'applicazione, e
     * riempire il log di questi eventi rende più difficile trovarci dentro i guasti veri. Era
     * già così per la guardia di quadratura dalla beta.43; qui vale per tutta la famiglia.
     */
    public function report(): bool
    {
        return false;
    }
}
