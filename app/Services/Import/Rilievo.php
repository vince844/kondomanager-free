<?php

namespace App\Services\Import;

/**
 * Una cosa da dire all'amministratore su una riga del suo file.
 *
 * ## Perché c'è il rimedio, e perché non è opzionale nei fatti
 *
 * «Riga 14: ruolo non valido» è una diagnosi. La lezione della beta.45 — *«una diagnosi senza
 * cura lascia l'amministratore peggio di prima, perché ora sa di avere un problema e non ha
 * niente da farne»* — vale qui più che altrove: chi sta migrando ha in mano un file che non ha
 * scritto lui, esportato da un programma che sta abbandonando. Se non gli diciamo **cosa fare**,
 * l'unica strada che gli resta è rinunciare.
 *
 * ## Perché la riga è 1-based
 *
 * È il numero che l'amministratore legge aprendo il file in Excel. Un messaggio che dice «riga
 * 14» riferendosi all'indice interno indica la riga 15 del suo foglio, e il rimedio diventa
 * ineseguibile proprio mentre sembra preciso.
 */
final readonly class Rilievo
{
    /**
     * @param  string  $codice  stabile, per i test e per un domani le traduzioni
     * @param  int|null  $riga  1-based, come in Excel; null se il rilievo è sul file nel suo insieme
     * @param  string|null  $colonna  l'etichetta come compare nel file, non il campo canonico
     * @param  string|null  $chiaveDecisione  **quale** decisione questo rilievo sta aspettando
     * @param  string|null  $foglio  il nome del foglio, quando il file ne ha più d'uno
     *
     * `foglio` esiste perché il modello compilabile a mano è **un file solo con cinque fogli**:
     * là «Riga 14, colonna «indirizzo»» esiste cinque volte, e il numero di riga — che questa
     * classe si è data la regola di rendere sempre uguale a quello che l'amministratore legge in
     * Excel — torna a essere ambiguo proprio mentre sembra preciso. Resta `null` per i file di
     * Danea, che un foglio utile ce l'hanno sempre e uno solo.
     *
     * `chiaveDecisione` esiste perché un rilievo `da_decidere` senza di essa è irrisolvibile: la
     * decisione dell'utente arriva con una chiave, e senza il collegamento niente può segnare
     * quel rilievo come risposto. Il risultato — visto a video — è un pulsante di conferma che
     * resta disabilitato anche dopo che l'amministratore ha deciso tutto, senza dire perché.
     */
    public function __construct(
        public Severita $severita,
        public string $codice,
        public string $messaggio,
        public ?string $rimedio = null,
        public ?int $riga = null,
        public ?string $colonna = null,
        public ?string $chiaveDecisione = null,
        public ?string $foglio = null,
    ) {}

    public static function errore(string $codice, string $messaggio, ?string $rimedio = null, ?int $riga = null, ?string $colonna = null, ?string $foglio = null): self
    {
        return new self(Severita::Errore, $codice, $messaggio, $rimedio, $riga, $colonna, foglio: $foglio);
    }

    public static function daDecidere(string $codice, string $messaggio, ?string $rimedio = null, ?int $riga = null, ?string $colonna = null, ?string $chiaveDecisione = null, ?string $foglio = null): self
    {
        return new self(Severita::DaDecidere, $codice, $messaggio, $rimedio, $riga, $colonna, $chiaveDecisione, $foglio);
    }

    public static function avviso(string $codice, string $messaggio, ?string $rimedio = null, ?int $riga = null, ?string $colonna = null, ?string $foglio = null): self
    {
        return new self(Severita::Avviso, $codice, $messaggio, $rimedio, $riga, $colonna, foglio: $foglio);
    }

    public function toArray(): array
    {
        return [
            'severita' => $this->severita->value,
            'codice' => $this->codice,
            'messaggio' => $this->messaggio,
            'rimedio' => $this->rimedio,
            'riga' => $this->riga,
            'colonna' => $this->colonna,
            'chiave_decisione' => $this->chiaveDecisione,
            'foglio' => $this->foglio,
        ];
    }
}
