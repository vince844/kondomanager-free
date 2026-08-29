<?php

namespace App\Services\Import\Canonical;

/**
 * Una voce di spesa sotto un capitolo — «Compenso amministratore» dentro «AMMINISTRAZIONE».
 *
 * È il livello a cui il prodotto attacca la tabella millesimale: in Kondomanager la ripartizione
 * sta sulla **voce**, mai sul capitolo, e `CreateContoRequest` la pretende obbligatoria proprio
 * quando `is_capitolo` è falso. Il file di partenza però non dice quale tabella vada su quale
 * voce, quindi il canonico non porta quel campo: sarebbe un posto in cui mettere un'informazione
 * che non abbiamo, e prima o poi qualcuno la riempirebbe indovinando.
 */
final readonly class CanonicalVoceSpesa
{
    /**
     * @param  string  $nome  come lo stampa il file, senza normalizzazioni
     * @param  int  $importoCents  già in centesimi e **già di segno positivo**: la conversione e
     *                             l'inversione avvengono una volta sola, nel parser
     */
    public function __construct(
        public string $nome,
        public int $importoCents,
    ) {}

    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'importo_cents' => $this->importoCents,
        ];
    }
}
