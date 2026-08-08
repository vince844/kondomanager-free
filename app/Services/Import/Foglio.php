<?php

namespace App\Services\Import;

/**
 * Un foglio letto da un file, con le sue righe grezze.
 *
 * **Grezze è una scelta, non una pigrizia.** I valori arrivano come stanno nel file: le date
 * come seriali Excel (`46036`), i decimali a volte come `float` e a volte come stringhe con la
 * virgola (`-827,88`), i codici unità come stringhe con lo zero davanti (`016`). Normalizzare
 * qui significherebbe decidere al posto del parser, e il parser è l'unico che sa **cosa** sta
 * leggendo: `016` è un codice, `46036` è una data, `-827,88` è denaro. Tre normalizzazioni
 * diverse per tre colonne dello stesso file.
 *
 * Vedi le trappole 2, 3 e 4 del §17.8 di `docs/import_migrazione_dati.md`.
 */
final readonly class Foglio
{
    /**
     * @param  list<list<mixed>>  $righe  0-based, sia per riga sia per colonna
     */
    public function __construct(
        public string $nome,
        public array $righe,
    ) {}

    public function numeroRighe(): int
    {
        return count($this->righe);
    }

    /**
     * @return list<mixed>
     */
    public function riga(int $indice): array
    {
        return $this->righe[$indice] ?? [];
    }

    /**
     * Vero se il foglio non ha una sola cella con del contenuto.
     *
     * Serve perché i file di Danea portano quasi sempre `Foglio2` e `Foglio3` vuoti, eredità
     * del modello Excel del 2004: aprire «il primo foglio» e sperare è il modo più rapido di
     * leggere niente (§17.1).
     */
    public function isVuoto(): bool
    {
        foreach ($this->righe as $riga) {
            foreach ($riga as $cella) {
                if ($cella !== null && trim((string) $cella) !== '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Il numero di riga come lo vede l'amministratore in Excel: 1-based.
     *
     * Ogni messaggio di errore deve usare questo, mai l'indice interno. «Riga 14» deve essere
     * la riga 14 del suo file, o il rimedio che gli stiamo dando non è eseguibile.
     */
    public static function rigaUtente(int $indice): int
    {
        return $indice + 1;
    }
}
