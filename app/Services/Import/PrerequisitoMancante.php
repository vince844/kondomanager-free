<?php

namespace App\Services\Import;

/**
 * Una condizione che manca perché un livello possa entrare in archivio.
 *
 * ## Perché esiste questa classe
 *
 * È la contromisura al difetto che il concorrente mostra nel proprio video dimostrativo
 * (§0.3-C): dopo un'importazione «riuscita», la pagina delle fatture avvisa che nel piano dei
 * conti mancano due conti che quelle fatture richiedono — **con le fatture già dentro**. La
 * verifica riga per riga aveva approvato ogni file; nessuna aveva chiesto *«dopo aver scritto
 * queste righe, il sistema sarà coerente?»*.
 *
 * Un banner rosso mostrato dopo non è una rete di sicurezza: è la constatazione che la rete non
 * c'era.
 *
 * Ogni prerequisito porta con sé il **rimedio**, per la stessa ragione per cui lo porta un
 * `Rilievo`: dire a un amministratore che manca qualcosa, senza dirgli cosa fare, lo lascia
 * peggio di prima.
 */
final readonly class PrerequisitoMancante
{
    public function __construct(
        public string $codice,
        public string $cosaManca,
        public string $rimedio,
    ) {}

    public function toArray(): array
    {
        return [
            'codice' => $this->codice,
            'cosa_manca' => $this->cosaManca,
            'rimedio' => $this->rimedio,
        ];
    }

    /**
     * Il rilievo corrispondente, per mostrarlo nella stessa lista degli altri.
     *
     * Un prerequisito mancante è sempre **bloccante**: non è un'opinione sulla qualità del
     * dato, è l'impossibilità di scriverlo senza lasciare il sistema incoerente.
     */
    public function comeRilievo(): Rilievo
    {
        return Rilievo::errore($this->codice, $this->cosaManca, $this->rimedio);
    }
}
