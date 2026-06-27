<?php

namespace App\Enums;

/**
 * Stato del saldo di pagamento di una fattura passiva (o nota di credito).
 *
 * Persistito come VARCHAR(50) su fatture_passive.stato_pagamento.
 * Questo è un READ MODEL materializzato: il valore viene ricalcolato
 * da PagamentoFornitoreService::ricalcolaStatoFattura() ogni volta che
 * viene inserito o stornato un record nella pivot fattura_scrittura.
 *
 * INVARIANTE: il valore qui è sempre derivabile come:
 *   totale = SUM(fattura_scrittura.importo_allocato)
 *            WHERE fattura_passiva_id = X
 *            AND tipo IN ('pagamento', 'compensazione')
 *
 * SEMANTICA SPECIALE per Note di Credito:
 *   - stato = PAGATA su una NC significa "interamente compensata/utilizzata"
 *   - La UI deve renderizzare "Compensata" anziché "Pagata" per le NC
 *
 * VOLUTAMENTE MINIMALE: stati di workflow (scaduta, in_contenzioso, sospesa)
 * vivono in campi separati, non in questo enum.
 */
enum StatoPagamentoFattura: string
{
    /** Nessun pagamento/compensazione registrata (totale allocato = 0). */
    case APERTA   = 'aperta';

    /** Pagamento/compensazione parziale (0 < totale < netto_a_pagare). */
    case PARZIALE = 'parziale';

    /** Interamente saldato/compensato (totale = netto_a_pagare). */
    case PAGATA   = 'pagata';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function label(): string
    {
        return match($this) {
            self::APERTA   => 'Aperta',
            self::PARZIALE => 'Parzialmente pagata',
            self::PAGATA   => 'Pagata',
        };
    }

    /**
     * Label contestualizzata per le Note di Credito.
     * Le NC non vengono "pagate" ma "compensate/utilizzate".
     */
    public function labelPerNC(): string
    {
        return match($this) {
            self::APERTA   => 'Disponibile',
            self::PARZIALE => 'Parzialmente utilizzata',
            self::PAGATA   => 'Compensata',
        };
    }

    /** La fattura ha ancora residuo da saldare. */
    public function hasResiduo(): bool
    {
        return in_array($this, [self::APERTA, self::PARZIALE]);
    }

    /** Badge color per la UI (Tailwind CSS class). */
    public function badgeColor(): string
    {
        return match($this) {
            self::APERTA   => 'red',
            self::PARZIALE => 'yellow',
            self::PAGATA   => 'green',
        };
    }

    /**
     * Calcola lo stato corretto a partire dagli importi.
     * Unica funzione che implementa la logica di calcolo — usata in test e service.
     */
    public static function fromImporti(int $totaleAllocato, int $nettoDaPagare): self
    {
        return match(true) {
            $totaleAllocato <= 0              => self::APERTA,
            $totaleAllocato < $nettoDaPagare  => self::PARZIALE,
            default                           => self::PAGATA,
        };
    }
}