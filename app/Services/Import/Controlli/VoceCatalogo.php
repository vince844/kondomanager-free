<?php

namespace App\Services\Import\Controlli;

/**
 * Cosa sappiamo su un codice di avviso: dove si va a sistemarlo, e chi risponde alla domanda
 * «è a posto?».
 *
 * ## La regola di forma
 *
 * **O un verificatore, o un `perche` scritto.** Un codice che non ha né l'uno né l'altro fa
 * fallire il test di esaustività — non passa in silenzio nel ramo di default. È l'unica difesa
 * contro il decadimento: senza, il prossimo livello aggiunge un avviso e la lista ricomincia a
 * mentire.
 */
final readonly class VoceCatalogo
{
    /**
     * @param  class-string<VerificatoreControllo>|null  $verificatore
     * @param  string|null  $rotta  nome della rotta di destinazione, o null se non c'è un posto dove andare
     * @param  string|null  $perche  obbligatorio quando `$verificatore` è null: perché la spunta è a mano
     */
    public function __construct(
        public ?string $verificatore = null,
        public ?string $rotta = null,
        public ?string $etichettaAzione = null,
        public ?string $perche = null,
    ) {}

    public function verificabile(): bool
    {
        return $this->verificatore !== null;
    }
}
