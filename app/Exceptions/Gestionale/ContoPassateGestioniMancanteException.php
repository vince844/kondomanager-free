<?php

namespace App\Exceptions\Gestionale;

/**
 * La compensazione usa un credito riportato da un esercizio precedente, ancora fuori dal giornale, e il condominio non ha il conto
 * «Passate gestioni» da cui farlo uscire (1.11.0-beta.49, DC1, decisione 76.3).
 *
 * Il riporto a credito di una quota senza scrittura sta fra le «Passate gestioni» (`RataQuote::creditoSuPassate()`): l'emissione non
 * l'ha ancora portato fra i crediti verso condomini. Il credito delle entrate dell'anno (rilievo K2) no: quello sta fra i crediti
 * verso condomini, e la sua compensazione non ha bisogno di questo conto. Prelevarlo da «Crediti verso condomini», come facevano la 1.9 e la 1.10,
 * toglieva da quel conto un credito che non c'era mai entrato. Senza il conto giusto la compensazione si rifiuta, con la stessa
 * frase del rimborso dello stesso credito (`RimborsaCreditoAction`): ricadere su «Crediti» in silenzio sarebbe DC1 di nuovo.
 *
 * Sale dentro la transazione dell'incasso, al primo prelievo, quindi niente resta scritto. La frase nomina il conto con il nome e il
 * codice che gli dà il programma (`CondominioService`), un nome solo, e dice la strada vera (giro stretto sulle correzioni della
 * Fase 1-bis, LT-2): è un conto di sistema, e dalle pagine non si ricrea. La pagina «Piani dei conti» è il preventivo, e una voce
 * con quel nome creata lì è una spesa da ripartire, non il conto; `ensureDefaultConti` non lo ricrea, perché esce appena trova i
 * crediti verso condomini.
 */
final class ContoPassateGestioniMancanteException extends IncassoNonRegistrabileException
{
    /** Il fatto, uguale per chi lo dice nelle pagine e per il comando di riallineamento. */
    public const FATTO = 'Manca il conto di sistema «Fondo Passate Gestioni» (2301): senza, il credito riportato da un esercizio precedente non ha una contropartita da cui uscire.';

    /** Nelle pagine: chi lo legge non può ricrearlo da lì. */
    public const MESSAGGIO = self::FATTO . ' Dalle pagine del programma non si ricrea: va ripristinato da chi gestisce l\'installazione.';

    /** Nel comando `kondomanager:riallinea-crediti`: chi lo legge è chi gestisce l'installazione. */
    public const MESSAGGIO_COMANDO = self::FATTO . ' Va ripristinato prima di rilanciare il comando.';

    public function __construct()
    {
        parent::__construct(self::MESSAGGIO);
    }

    public function campo(): string
    {
        return 'dettaglio_pagamenti';
    }
}
