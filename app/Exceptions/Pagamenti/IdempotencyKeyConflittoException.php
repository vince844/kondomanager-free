<?php

namespace App\Exceptions\Pagamenti;

/**
 * La chiave di idempotenza inviata con un pagamento è già in uso, ma NON da un replay
 * di quel pagamento.
 *
 * `scritture_contabili.idempotency_key` è dichiarata `unique()` su **tutta** la tabella,
 * e ci scrivono anche i giroconti (`RegistraGirocontoAction`). La ricerca del replay può
 * quindi trovare due cose che replay non sono:
 *
 *  - una scrittura che non è un pagamento — non ha un `PagamentoFornitore` da restituire;
 *  - il pagamento di un **altro condominio** — restituirlo farebbe credere al chiamante
 *    di aver registrato qualcosa che nel proprio condominio non esiste.
 *
 * Prima che questa eccezione esistesse, il primo caso usciva con `null` da un metodo che
 * dichiara `: PagamentoFornitore`, cioè con un `TypeError`; il secondo non usciva affatto
 * e restituiva in silenzio il documento sbagliato.
 *
 * HTTP: 409 Conflict — la richiesta è legittima, lo è anche lo stato del sistema, ma le
 * due cose non possono coesistere. Si risolve rigenerando la chiave.
 */
class IdempotencyKeyConflittoException extends PagamentoException {}
