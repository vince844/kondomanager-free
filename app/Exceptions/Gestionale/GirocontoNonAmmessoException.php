<?php

namespace App\Exceptions\Gestionale;

/**
 * Il giroconto richiesto viola una regola di dominio.
 *
 * Casi vietati per costruzione:
 *  1. Capienza — la cassa di origine non ha saldo sufficiente. Un fondo negativo
 *     è denaro accantonato che non esiste; una banca negativa per un accantonamento
 *     è un non-senso (a differenza del pagamento, che è un obbligo verso terzi e
 *     ammette lo scoperto autorizzato). Nessun override qui.
 *  2. Fondo vincolato — se l'origine è un fondo con sottotipo diverso da 'generico'
 *     e senza deroga assembleare (is_override_assemblea), il vincolo di destinazione
 *     prevale: serve la delibera prima del giroconto.
 *  3. Coppie di casse incoerenti — la liquidità di un fondo vive sul conto corrente:
 *     un giroconto fondo ↔ contanti/virtuale non ha significato fisico (il prelievo
 *     contante passa da fondo → banca → contanti, in due movimenti espliciti).
 *  4. Copertura collegata non coerente — stato diverso da 'pianificata', importo
 *     diverso da quello della copertura, fondo diverso dall'origine del giroconto.
 *
 * Lo STORNO di un giroconto non passa mai da questi controlli: il giornale è
 * append-only e lo storno è l'operazione sempre ammessa.
 *
 * HTTP: 422 Unprocessable Entity (violazione di regola di dominio, non di autorizzazione)
 */
class GirocontoNonAmmessoException extends \DomainException {}
