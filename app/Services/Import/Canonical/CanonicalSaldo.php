<?php

namespace App\Services\Import\Canonical;

/**
 * Il saldo di apertura di un condòmino su un'unità — il **ponte** fra il vecchio gestionale e
 * Kondomanager (§2, piano 2).
 *
 * È l'unico dato importato che ha conseguenze legali: da qui nascono le morosità, i solleciti
 * e, in fondo alla catena, i decreti ingiuntivi. Un saldo sbagliato non si nota subito — si
 * trascina in ogni riparto successivo, ed è per questo che il §11 impone la quadratura sul
 * totale scritto nel file invece di fidarsi della somma delle righe.
 *
 * ## Il segno è già quello di Kondomanager
 *
 * Danea usa **negativo = debito**; qui vale la convenzione del progetto — **positivo = debito,
 * negativo = credito**. L'inversione avviene una volta sola, nel parser.
 *
 * ## Perché il soggetto può essere nullo
 *
 * I titolari **cessati** (`ex Pr`, `ex Co`) compaiono nel riparto con un saldo residuo che fa
 * parte del totale. Il §3 esclude i ruoli storici, ma il loro debito non sparisce: diventa un
 * **saldo solidale** sull'unità — `anagrafica_id` a NULL — che è la forma con cui Kondomanager
 * rappresenta un debito che segue la casa e non la persona (art. 63 disp. att. c.c.).
 */
final readonly class CanonicalSaldo
{
    public function __construct(
        public string $immobileRef,          // il progressivo come lo stampa il riparto: `01`, `016`
        public ?string $soggettoNome,        // NULL = saldo solidale sull'unità
        public int $importoCents,            // convenzione Kondomanager: positivo = debito
        public bool $daTitolareCessato = false,
        public ?int $rigaSorgente = null,
        /**
         * Vero quando questa posizione era intestata a un nome doppio che l'amministratore ha
         * scelto di dividere. Il saldo **non** si spezza: il file non dice in che proporzione,
         * e inventarla sarebbe decidere su denaro altrui. Resta sull'unità, in solido.
         */
        public bool $daNomeDiviso = false,
    ) {}

    public function isSolidale(): bool
    {
        return $this->soggettoNome === null;
    }

    public function toArray(): array
    {
        return [
            'immobile' => $this->immobileRef,
            'soggetto' => $this->soggettoNome,
            'importo_cents' => $this->importoCents,
            'solidale' => $this->isSolidale(),
            'da_titolare_cessato' => $this->daTitolareCessato,
            'riga' => $this->rigaSorgente,
        ];
    }
}
