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
    /**
     * @param  bool  $bloccante  falso quando la condizione mancante è **un file che non c'è**
     *
     * ⚠️ **Non tutti i prerequisiti mancanti sono un problema, e trattarli uguale costava
     * l'importazione.** «Il condominio non è in archivio» e «nessun file porta i saldi» finivano
     * nella stessa classe, e `ImportRunner` si fermava su entrambi. Ma il primo è un'incoerenza —
     * scrivere sarebbe sbagliato — mentre il secondo è **una scelta di chi importa**: non ho quel
     * file, o non l'ho compilato.
     *
     * Misurato prima della correzione, con l'ordine dei livelli
     * `condominio → esercizi → capitoli → soggetti → unità → titolarità → tabelle → saldi`:
     * un lotto senza il foglio delle persone si fermava al **quarto** livello e lasciava fuori
     * unità, tabelle e saldi — che dalle persone non dipendono affatto. Venivano scartati solo
     * perché stavano più in basso nella catena.
     */
    public function __construct(
        public string $codice,
        public string $cosaManca,
        public string $rimedio,
        public bool $bloccante = true,
    ) {}

    public function toArray(): array
    {
        return [
            'codice' => $this->codice,
            'cosa_manca' => $this->cosaManca,
            'rimedio' => $this->rimedio,
            'bloccante' => $this->bloccante,
        ];
    }

    /**
     * Il rilievo corrispondente, per mostrarlo nella stessa lista degli altri.
     *
     * La severità segue `bloccante`: un'incoerenza è un **errore**, un file che non c'è è un
     * **avviso**. Mostrare in rosso «nessun file porta i saldi» a chi i saldi non li ha è la
     * stessa cosa che dirgli che ha sbagliato — e non ha sbagliato niente.
     */
    public function comeRilievo(): Rilievo
    {
        return $this->bloccante
            ? Rilievo::errore($this->codice, $this->cosaManca, $this->rimedio)
            : Rilievo::avviso($this->codice, $this->cosaManca, $this->rimedio);
    }
}
