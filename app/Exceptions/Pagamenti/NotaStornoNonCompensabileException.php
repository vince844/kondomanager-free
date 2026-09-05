<?php

namespace App\Exceptions\Pagamenti;

/**
 * Una delle allocazioni punta a una nota di credito generata internamente da uno storno
 * (`dati_extra->nota_storno`).
 *
 * Non è un documento che il fornitore conosca — il suo unico scopo è azzerare la fattura che
 * stornano — e consumarla altrove lascerebbe lo storno incompleto: si registrerebbe
 * l'estinzione di un debito verso un fornitore che continua a considerare quella fattura non
 * pagata, senza che un euro sia uscito dalla cassa (Coda 124).
 *
 * Non dovrebbe essere raggiungibile dalla schermata — la nota è già esclusa dalle pendenze e
 * dalle note compensabili — quindi questa è la seconda linea, per una richiesta costruita a
 * mano o per un elenco che in futuro dimenticasse il filtro.
 *
 * HTTP: 422 Unprocessable Entity
 */
class NotaStornoNonCompensabileException extends PagamentoException {}
