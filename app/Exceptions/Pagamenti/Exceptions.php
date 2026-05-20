<?php

namespace App\Exceptions\Pagamenti;

/**
 * Il totale allocato (pagamenti + compensazioni) supera il netto_a_pagare
 * della fattura. Indica duplicazione, errore operatore, o NC mancante.
 *
 * HTTP: 422 Unprocessable Entity
 * Contesto: lanciata SOLO da validaInput(), mai da ricalcolaStatoFattura()
 * (che logga e marca flag inconsistenza_pagamento invece di bloccare il reconcile).
 */
class OverpaymentException extends PagamentoException {}

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
 * Bypassabile con flag allow_overdraft = true (richiede conferma esplicita utente).
 *
 * HTTP: 409 Conflict
 */
class InsufficientFundsException extends PagamentoException {}

/**
 * Violazione del limite contanti per normativa antiriciclaggio.
 * D.Lgs. 231/2007 — soglia corrente: 5.000 € (dal 01/01/2023).
 * Non bypassabile.
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
 * Bypassabile con flag confermo_non_duplicato = true.
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