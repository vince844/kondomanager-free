<?php

namespace App\Exceptions\Pagamenti;

/**
 * Il totale allocato (pagamenti + compensazioni) supera il netto_a_pagare
 * della fattura. Indica duplicazione, errore operatore, o NC mancante.
 *
 * Porta dati strutturati in centesimi per permettere al frontend di mostrare
 * un modale preciso senza fare parsing del messaggio testuale.
 *
 * HTTP: 422 Unprocessable Entity
 * Contesto: lanciata SOLO da validaInput(), mai da ricalcolaStatoFattura()
 * (che logga e marca flag inconsistenza_pagamento invece di bloccare il reconcile).
 */
class OverpaymentException extends PagamentoException
{
    /**
     * @param string $message            Messaggio human-readable
     * @param int    $allocatoCents      Importo allocato proposto (in centesimi)
     * @param int    $residuoCents       Residuo disponibile della fattura (in centesimi)
     * @param string $numFattura         Numero documento fattura coinvolta
     */
    public function __construct(
        string $message,
        public readonly int    $allocatoCents = 0,
        public readonly int    $residuoCents  = 0,
        public readonly string $numFattura    = '',
    ) {
        parent::__construct($message);
    }
}

/**
 * Tentativo di creare o stornare una scrittura contabile in un esercizio chiuso.
 *
 * IMPORTANTE — paradigma ledger-centric:
 * Questa eccezione viene lanciata sull'esercizio DELLA SCRITTURA ($data['esercizio_id']),
 * NON sull'esercizio della fattura. Una fattura di competenza 2025 può essere
 * pagata legittimamente nel 2026 anche se l'esercizio 2025 è chiuso.
 *
 * HTTP: 403 Forbidden
 */
class FiscalYearClosedException extends PagamentoException {}

/**
 * Il saldo del conto corrente selezionato è insufficiente a coprire il pagamento.
 *
 * Porta dati strutturati in centesimi per permettere al frontend di mostrare
 * un modale preciso (saldo attuale, necessario, scopertura) senza parsing testuale.
 * Bypassabile con flag allow_overdraft = true (richiede nota obbligatoria nell'audit trail).
 *
 * HTTP: 409 Conflict
 */
class InsufficientFundsException extends PagamentoException
{
    /**
     * @param string $message           Messaggio human-readable
     * @param int    $saldoCents        Saldo attuale del conto (in centesimi)
     * @param int    $necessarioCents   Importo necessario per il pagamento (in centesimi)
     * @param int    $scoperturaCents   Differenza negativa — quanto manca (in centesimi)
     */
    public function __construct(
        string $message,
        public readonly int $saldoCents      = 0,
        public readonly int $necessarioCents = 0,
        public readonly int $scoperturaCents = 0,
    ) {
        parent::__construct($message);
    }
}

/**
 * Violazione del limite contanti per normativa antiriciclaggio.
 * D.Lgs. 231/2007 — soglia corrente: 5.000 € (dal 01/01/2023).
 * Non bypassabile: il sistema non può essere aggirato su questo blocco
 * per proteggere l'amministratore da sanzioni amministrative.
 *
 * HTTP: 403 Forbidden
 */
class IllegalCashAmountException extends PagamentoException {}

/**
 * Tentativo di stornare un pagamento già stornato.
 * Il ledger è immutabile: uno storno già eseguito non può essere "de-stornato".
 * Per correggere: registrare un nuovo pagamento.
 *
 * HTTP: 409 Conflict
 */
class PagamentoGiaStornatoException extends PagamentoException {}

/**
 * Una o più fatture nelle allocazioni hanno stato_approvazione != 'approvata'.
 * Le fatture in stato di verifica/contestazione non sono pagabili.
 * Art. 1135 c.c. — l'assemblea deve deliberare le spese prima del pagamento.
 *
 * HTTP: 422 Unprocessable Entity
 */
class FatturaNonApprovataException extends PagamentoException {}

/**
 * L'IBAN di destinazione inserito differisce dall'IBAN storico nell'anagrafica
 * del fornitore (Sentinella Anti-Frode).
 *
 * Bypassabile con flag iban_confermato_manualmente = true, che registra
 * nell'audit log chi ha confermato la discrepanza e quando.
 *
 * ATTENZIONE: le truffe Man-in-the-Middle con sostituzione IBAN su fatture PDF
 * sono il principale vettore di frode nel settore condominiale italiano.
 *
 * HTTP: 409 Conflict (con istruzioni per override)
 */
class IbanDiscrepanzaException extends PagamentoException {}

/**
 * Rilevato possibile pagamento duplicato nella finestra temporale di 24 ore
 * (stessa fattura + stesso importo allocato + entro 24h).
 *
 * Bypassabile con flag conferma_duplicato_verificato = true.
 *
 * NOTA: la detection è volutamente conservativa in v1.9.1 (solo 24h, stesso importo)
 * per evitare falsi positivi su pagamenti parziali successivi legittimi.
 * Il sistema di scoring completo (multi-segnale) arriva in v1.16 (Treasury).
 *
 * HTTP: 409 Conflict (con istruzioni per override)
 */
class PossibilePagamentoDuplicatoException extends PagamentoException {}

/**
 * Le allocazioni nella stessa operazione di pagamento puntano a fatture
 * di fornitori o condomini diversi. Un pagamento deve sempre riguardare
 * un unico fornitore per un unico condominio.
 *
 * HTTP: 422 Unprocessable Entity
 */
class AllocazioniInconsistentiException extends PagamentoException {}

/**
 * Nessun esercizio aperto disponibile per registrare lo storno cross-esercizio.
 * Si verifica quando l'esercizio del pagamento originale è chiuso E non esiste
 * un esercizio corrente aperto per quel condominio.
 *
 * HTTP: 409 Conflict
 */
class NessunEsercizioApertoException extends PagamentoException {}