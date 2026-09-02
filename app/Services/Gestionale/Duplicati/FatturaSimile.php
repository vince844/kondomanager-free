<?php

namespace App\Services\Gestionale\Duplicati;

use App\Models\Gestionale\FatturaPassiva;

/**
 * Una fattura che somiglia a quella che si sta scrivendo, **e il motivo per cui somiglia**.
 *
 * ⚠️ Il motivo viaggia col risultato, non si ricava dopo. È la stessa scelta di
 * `App\Services\Import\RicercaEsistenti`, e il perché è scritto lì: *chi decide deve sapere su
 * cosa abbiamo trovato la somiglianza*. Un banner che dice «possibile duplicato» senza dire quale
 * documento e perché non lascia decidere niente — e la regola di casa vuole che un avviso dica il
 * perché con testo visibile.
 *
 * Porta i soli campi che servono a decidere, non il modello intero: questo oggetto finisce in una
 * risposta JSON letta dal modulo di registrazione, e una fattura completa esporrebbe a video
 * scritture, coperture e dati fiscali che nessuno ha chiesto.
 */
final readonly class FatturaSimile
{
    public function __construct(
        public int $id,
        public string $numeroDocumento,
        public string $dataDocumento,
        public int $totaleDocumentoCents,
        public string $motivo,
        public bool $isPregresso,
    ) {
    }

    public static function da(FatturaPassiva $fattura, string $motivo): self
    {
        return new self(
            id: $fattura->id,
            numeroDocumento: (string) $fattura->numero_documento,
            dataDocumento: $fattura->data_documento?->toDateString() ?? '',
            totaleDocumentoCents: (int) $fattura->totale_documento,
            motivo: $motivo,
            isPregresso: (bool) $fattura->is_pregresso,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'numero_documento' => $this->numeroDocumento,
            'data_documento' => $this->dataDocumento,
            'totale_documento' => $this->totaleDocumentoCents,
            'motivo' => $this->motivo,
            'is_pregresso' => $this->isPregresso,
        ];
    }
}
