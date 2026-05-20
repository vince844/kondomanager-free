<?php

namespace App\Enums;

/**
 * Stato del record PagamentoFornitore (la disposizione di pagamento).
 *
 * Persistito come VARCHAR(50) su pagamenti_fornitori.stato.
 *
 * MINIMALISTA per design: un pagamento nasce CONFERMATO e può solo diventare
 * STORNATO. Non esiste "modifica" — gli errori si correggono con storno + nuovo pagamento.
 * Questo preserva l'immutabilità del libro giornale.
 */
enum StatoPagamentoFornitore: string
{
    /** Il pagamento è registrato, le scritture contabili sono attive. */
    case CONFERMATO = 'confermato';

    /**
     * Il pagamento è stato stornato (scrittura inversa + pivot negativi).
     * Le fatture toccate sono tornate al loro stato precedente.
     */
    case STORNATO = 'stornato';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function label(): string
    {
        return match($this) {
            self::CONFERMATO => 'Confermato',
            self::STORNATO   => 'Stornato',
        };
    }

    public function badgeColor(): string
    {
        return match($this) {
            self::CONFERMATO => 'green',
            self::STORNATO   => 'slate',
        };
    }

    public function isStornabile(): bool
    {
        return $this === self::CONFERMATO;
    }
}