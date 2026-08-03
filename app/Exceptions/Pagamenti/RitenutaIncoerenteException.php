<?php

namespace App\Exceptions\Pagamenti;

/**
 * L'importo della ritenuta d'acconto inviato con un pagamento non corrisponde alle fatture
 * che quel pagamento sta pagando.
 *
 * La ritenuta non è un campo libero: è una funzione delle fatture allocate e della quota di
 * ciascuna che si sta pagando. Il servizio la calcola pro-quota; un valore in ingresso che
 * non coincide è un errore di coerenza, non un override.
 *
 * Prima che questa eccezione esistesse, `importo_ritenuta_cents` era validato solo come
 * `nullable|integer|min:0` e il servizio lo accettava così com'era. Finché era uno snapshot
 * per la certificazione era un fastidio; con il modulo F24 quell'importo è **la cifra che
 * si versa all'Erario**, e un numero arbitrario diventa un versamento sbagliato.
 *
 * Un override manuale motivato è un'altra cosa, ed è una funzione che oggi non esiste:
 * sta fra le richieste non pianificate della roadmap.
 *
 * HTTP: 422 Unprocessable Entity — la richiesta è ben formata ma incoerente con lo stato.
 */
class RitenutaIncoerenteException extends PagamentoException {}
