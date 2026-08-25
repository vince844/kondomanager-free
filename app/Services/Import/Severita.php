<?php

namespace App\Services\Import;

/**
 * Le tre severità di un rilievo — §10.1 di `docs/import_migrazione_dati.md`.
 *
 * **Tre e non due, ed è la differenza fra un validatore usabile e uno inutile.** Con una
 * classificazione binaria bisogna scegliere fra bloccare su un codice fiscale mancante
 * (inaccettabile: metà degli export non ce l'hanno) e ignorarlo (pericoloso: la de-duplicazione
 * ci si appoggia). Nessuna delle due è giusta, perché sono due domande diverse: *questo dato è
 * sbagliato?* e *questa decisione è mia o tua?*
 */
enum Severita: string
{
    /**
     * La riga non entra, e il livello non si conferma finché ce n'è almeno uno.
     *
     * Sono i casi in cui il dato è **oggettivamente illeggibile o incoerente**: una data che non
     * si interpreta, un importo non numerico, un ruolo fuori vocabolario, una chiave di join che
     * non risolve.
     */
    case Errore = 'errore';

    /**
     * Il livello non si conferma finché l'utente non sceglie — ma non c'è niente di sbagliato.
     *
     * Il caso principale è il duplicato: unisci, salta o crea nuovo (§10.3). Non è un errore, è
     * una decisione che **non possiamo prendere noi**, e prenderla di nascosto sarebbe la
     * sovrascrittura silenziosa che rimproveriamo agli altri.
     */
    case DaDecidere = 'da_decidere';

    /**
     * Entra, ma resta scritto nel rapporto e consultabile dopo l'import.
     *
     * CF mancante, quota diversa dal 100%, tabella parziale, unità senza titolari. Cose che
     * l'amministratore deve **sapere**, non cose che deve risolvere adesso.
     */
    case Avviso = 'avviso';

    public function bloccaConferma(): bool
    {
        return $this !== self::Avviso;
    }

    /** L'etichetta della schermata S3, al plurale come nei contatori. */
    public function etichetta(): string
    {
        return match ($this) {
            self::Errore => 'Errori',
            self::DaDecidere => 'Da decidere',
            self::Avviso => 'Avvisi',
        };
    }
}
