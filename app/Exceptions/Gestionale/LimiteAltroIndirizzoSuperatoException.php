<?php

namespace App\Exceptions\Gestionale;

use Exception;

/**
 * Il limite di dieci al minuto sulle ricevute mandate a indirizzi scritti a mano è scattato dentro l'invio (la ricevuta di un incasso annullato
 * rimandata all'indirizzo scritto a mano a cui era partita): il controller risponde 429 con la frase del limite, come per le altre strade.
 * Non è una `RicevutaNonEmettibileException`, che diventa un 422.
 */
class LimiteAltroIndirizzoSuperatoException extends Exception
{
}
