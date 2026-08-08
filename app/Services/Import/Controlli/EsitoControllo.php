<?php

namespace App\Services\Import\Controlli;

/**
 * La risposta di un verificatore: lo stato, quante cose restano, e la frase da mostrare.
 *
 * `frase` è viva e non congelata: «restano 3 tabelle su 6 senza capitolo» oggi, «tutte collegate»
 * domani. Il messaggio originale dell'avviso resta accanto come registrazione di com'era il
 * giorno dell'importazione, ma non è quello che l'amministratore deve leggere per sapere cosa
 * gli manca adesso.
 */
final readonly class EsitoControllo
{
    public function __construct(
        public StatoControllo $stato,
        public int $rimaste,
        public string $frase,
    ) {}

    public static function aperto(int $rimaste, string $frase): self
    {
        return new self(StatoControllo::Aperto, $rimaste, $frase);
    }

    public static function risolto(string $frase): self
    {
        return new self(StatoControllo::Risolto, 0, $frase);
    }

    public static function superato(int $rimaste, string $frase): self
    {
        return new self(StatoControllo::Superato, $rimaste, $frase);
    }
}
