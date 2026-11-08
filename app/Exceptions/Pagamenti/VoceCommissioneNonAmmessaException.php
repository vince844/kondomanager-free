<?php

namespace App\Exceptions\Pagamenti;

/**
 * La commissione di un pagamento al fornitore è più di zero e la sua voce manca, o non è una voce di spesa della gestione del pagamento.
 *
 * La commissione va sul mastro della voce, con la voce, come la regolazione immediata: senza una voce resterebbe fuori dal consuntivo e dal
 * riparto. Il messaggio è sempre quello di `VoceDellaCommissione`, e il controller lo mette sul campo della voce.
 *
 * HTTP: 422 Unprocessable Entity — la richiesta è ben formata ma incoerente con il piano dei conti.
 */
class VoceCommissioneNonAmmessaException extends PagamentoException {}
