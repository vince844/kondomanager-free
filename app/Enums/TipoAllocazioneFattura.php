<?php

namespace App\Enums;

/**
 * Semantica di ogni riga nella pivot fattura_scrittura.
 *
 * Persistito come VARCHAR(50) su fattura_scrittura.tipo.
 *
 * INVARIANTE CRITICA:
 *   Σ(importo_allocato WHERE tipo IN [PAGAMENTO, COMPENSAZIONE]) = residuo chiuso
 *   Σ(importo_allocato WHERE tipo = PAGAMENTO) = uscita di cassa della scrittura
 *
 * Il tipo COMPETENZA non partecipa mai al calcolo dello stato_pagamento.
 * Rappresenta la registrazione iniziale del debito, non la sua chiusura.
 *
 * Future espansioni (v1.9.2+): ACCONTO, ABBUONO, RITENUTA
 */
enum TipoAllocazioneFattura: string
{
    /**
     * Registrazione iniziale della fattura nel ledger (scrittura di competenza).
     * NON viene sommato per calcolare totale_allocato / stato_pagamento.
     * È il debito che si sta generando, non quello che si sta chiudendo.
     */
    case COMPETENZA    = 'competenza';

    /**
     * Movimento di cassa reale verso il fornitore (uscita bancaria).
     * PARTECIPA al calcolo dello stato_pagamento.
     * INVARIANTE: Σ(alloc tipo=PAGAMENTO per scrittura) = uscita_cassa_scrittura.
     */
    case PAGAMENTO     = 'pagamento';

    /**
     * Chiusura via nota di credito o compensazione senza cassa.
     * PARTECIPA al calcolo dello stato_pagamento.
     * Non genera movimento bancario diretto.
     */
    case COMPENSAZIONE = 'compensazione';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function label(): string
    {
        return match($this) {
            self::COMPETENZA    => 'Competenza (registrazione)',
            self::PAGAMENTO     => 'Pagamento',
            self::COMPENSAZIONE => 'Compensazione',
        };
    }

    /**
     * Indica se questo tipo partecipa al calcolo di totale_allocato / stato_pagamento.
     * COMPETENZA è escluso: è il debito che nasce, non quello che si chiude.
     */
    public function partecipaAlCalcoloSaldo(): bool
    {
        return in_array($this, [self::PAGAMENTO, self::COMPENSAZIONE]);
    }

    /** Tipi che partecipano al calcolo — usato nei WHERE delle query aggregate. */
    public static function perCalcoloSaldo(): array
    {
        return [self::PAGAMENTO->value, self::COMPENSAZIONE->value];
    }

    /** Il tipo genera un'uscita di cassa reale. */
    public function isMovimentoCassa(): bool
    {
        return $this === self::PAGAMENTO;
    }
}