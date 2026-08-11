<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Si sta allocando su una rata il cui debito è intestato a un altro soggetto.
 *
 * Nasce nella beta.48 con la guardia della coda ⑧(a), e nasce **subito come eccezione dedicata**
 * per una ragione trovata dalla revisione avversariale della beta stessa: la prima versione
 * della guardia sollevava un `RuntimeException` generico, e `IncassoRateController::store()`
 * cattura solo `TotaleIncassoNonCorrispondenteException`. Sarebbe arrivata all'amministratore
 * come **pagina 500**, con la distribuzione appena fatta a mano buttata via.
 *
 * È esattamente il difetto che la beta.43 aveva corretto per la guardia della quadratura, e che
 * il commento in `IncassoRateController:165-168` descrive: *«torna indietro con il modulo
 * compilato e il motivo, invece della pagina 500 che l'amministratore si prendeva finora»*.
 * Ripeterlo una release dopo, su una guardia nuova, sarebbe stato il caso più puro di lezione
 * scritta e non applicata.
 *
 * ⚠️ **Le altre `RuntimeException` di `StoreIncassoRateAction` hanno lo stesso problema** — il
 * credito di un soggetto non collegato all'unità (`:281-297`), la quota di credito non trovata,
 * il credito insufficiente. Sono preesistenti e restano fuori da questa beta, ma vanno con la
 * stessa medicina: vedi la coda in roadmap.
 *
 * ## Perché è un conflitto di dominio e non un guasto
 *
 * La guardia scatta **prima** di aprire la transazione, quindi non c'è niente di scritto da
 * recuperare: si torna al modulo con il motivo, e l'amministratore sceglie l'intestatario
 * giusto senza ricompilare.
 */
class DebitoNonDelPaganteException extends Exception
{
    /**
     * @param  int|string  $numeroRata     Il numero della rata su cui si stava allocando.
     * @param  string      $intestatari    Chi ha davvero il debito; stringa vuota se non ricavabile.
     */
    public function __construct(
        protected int|string $numeroRata,
        protected string $intestatari = '',
    ) {
        // Il messaggio nomina chi ha il debito e dice cosa fare. Senza il nome, un rifiuto su
        // una riga che a schermo sembra la propria sposta il vicolo cieco di un passo invece di
        // toglierlo — è la lezione della beta.45 sugli esiti che descrivono un ostacolo e tacciono
        // sulla via d'uscita.
        parent::__construct(sprintf(
            'Non puoi incassare la rata n.%s a nome di chi hai scelto come pagante: quel debito '
            . 'è intestato %s. Cercando per unità immobiliare la riga raccoglie le quote di tutti '
            . 'i comproprietari: scegli come pagante l\'intestatario del debito, oppure registra '
            . 'l\'incasso separatamente per ciascuno.',
            $this->numeroRata,
            $this->intestatari !== '' ? "a {$this->intestatari}" : 'a un altro soggetto',
        ));
    }
}
