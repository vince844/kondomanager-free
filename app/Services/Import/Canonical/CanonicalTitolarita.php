<?php

namespace App\Services\Import\Canonical;

use App\Enums\RuoloAnagraficaImmobile;

/**
 * Chi possiede cosa, e con che ruolo. **È la cosa che vale di più di tutta la migrazione.**
 *
 * Un'unità senza titolare non riceve rate, non compare in morosità e non entra in nessun
 * riparto: l'importazione risulta tecnicamente riuscita e operativamente inutile. È il punto
 * su cui il concorrente si ferma — nel suo video dimostrativo, dopo dodici livelli importati,
 * le unità hanno proprietario e inquilino vuoti e le rate sono intestate all'etichetta
 * «Proprietario» invece che a una persona (§0.3-D).
 *
 * ## Il saldo precedente viaggia qui, ma con il segno girato
 *
 * `Saldo prec` di Danea segue la convenzione **negativo = debito**. Kondomanager usa
 * l'opposta: **positivo = debito, negativo = credito**. L'inversione avviene **una volta
 * sola**, nel parser, esattamente come la conversione in centesimi — e per la stessa ragione
 * per cui il progetto vieta i `* 100` difensivi a valle.
 */
final readonly class CanonicalTitolarita
{
    public function __construct(
        public string $immobileRef,       // la chiave `1-A-3`
        public string $soggettoRef,       // CF o `nome:…`
        public RuoloAnagraficaImmobile $ruolo,
        public ?int $saldoPrecedenteCents = null,   // già nella convenzione di Kondomanager
        public ?float $quota = null,                // null = 100%, che è il default dello schema
        public ?int $rigaSorgente = null,           // 1-based, per i messaggi
    ) {}

    public function toArray(): array
    {
        return [
            'immobile' => $this->immobileRef,
            'soggetto' => $this->soggettoRef,
            'ruolo' => $this->ruolo->value,
            'saldo_precedente_cents' => $this->saldoPrecedenteCents,
            'quota' => $this->quota,
            'riga' => $this->rigaSorgente,
        ];
    }
}
