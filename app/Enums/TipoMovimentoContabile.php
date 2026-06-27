<?php

namespace App\Enums;

/**
 * Vocabolario di dominio per i movimenti contabili del libro giornale.
 *
 * Persistito come VARCHAR(50) su scritture_contabili.tipo_movimento.
 * Cast Eloquent: protected $casts = ['tipo_movimento' => TipoMovimentoContabile::class];
 *
 * IMPORTANTE: tutti i valori string devono corrispondere esattamente ai valori
 * presenti nel DB (case-sensitive). L'aggiunta di nuovi case non richiede
 * migration — basta aggiungere il case PHP e il valore stringa.
 *
 * Valori esistenti pre-v1.9.1 (presenti in DB, non modificabili):
 *   fattura_acquisto, nota_credito_fornitore, pagamento_fornitore,
 *   storno_fattura, emissione_rata, incasso_rata, storno_credito,
 *   stralcio_credito, rimborso_condomino, apertura, chiusura, giroconto,
 *   rettifica, pagamento_f24, incasso_diverso, rimborso_assicurativo
 *
 * Aggiunto in v1.9.1: storno_pagamento_fornitore
 */
enum TipoMovimentoContabile: string
{
    // ─── Ciclo passivo ────────────────────────────────────────────────────────
    case FATTURA_ACQUISTO            = 'fattura_acquisto';
    case NOTA_CREDITO_FORNITORE      = 'nota_credito_fornitore';
    case PAGAMENTO_FORNITORE         = 'pagamento_fornitore';
    case STORNO_FATTURA              = 'storno_fattura';
    case STORNO_PAGAMENTO_FORNITORE  = 'storno_pagamento_fornitore';  // nuovo v1.9.1

    // ─── Ciclo attivo ─────────────────────────────────────────────────────────
    case EMISSIONE_RATA              = 'emissione_rata';
    case INCASSO_RATA                = 'incasso_rata';
    case STORNO_CREDITO              = 'storno_credito';
    case STRALCIO_CREDITO            = 'stralcio_credito';
    case RIMBORSO_CONDOMINO          = 'rimborso_condomino';
    case INCASSO_DIVERSO             = 'incasso_diverso';
    case RIMBORSO_ASSICURATIVO       = 'rimborso_assicurativo';

    // ─── Adempimenti fiscali ──────────────────────────────────────────────────
    case PAGAMENTO_F24               = 'pagamento_f24';

    // ─── Movimenti tecnici ────────────────────────────────────────────────────
    case APERTURA                    = 'apertura';
    case CHIUSURA                    = 'chiusura';
    case GIROCONTO                   = 'giroconto';
    case RETTIFICA                   = 'rettifica';

    // ─── Futuri (v1.10+, non ancora in DB) ───────────────────────────────────
    case ACCANTONAMENTO              = 'accantonamento';
    case RIPARTO                     = 'riparto';
    case RICONCILIAZIONE_BANCARIA    = 'riconciliazione_bancaria';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function label(): string
    {
        return match($this) {
            self::FATTURA_ACQUISTO           => 'Fattura acquisto',
            self::NOTA_CREDITO_FORNITORE     => 'Nota di credito fornitore',
            self::PAGAMENTO_FORNITORE        => 'Pagamento fornitore',
            self::STORNO_FATTURA             => 'Storno fattura',
            self::STORNO_PAGAMENTO_FORNITORE => 'Storno pagamento fornitore',
            self::EMISSIONE_RATA             => 'Emissione rata',
            self::INCASSO_RATA               => 'Incasso rata',
            self::STORNO_CREDITO             => 'Storno credito',
            self::STRALCIO_CREDITO           => 'Stralcio credito',
            self::RIMBORSO_CONDOMINO         => 'Rimborso condòmino',
            self::INCASSO_DIVERSO            => 'Incasso diverso',
            self::RIMBORSO_ASSICURATIVO      => 'Rimborso assicurativo',
            self::PAGAMENTO_F24              => 'Pagamento F24',
            self::APERTURA                   => 'Apertura esercizio',
            self::CHIUSURA                   => 'Chiusura esercizio',
            self::GIROCONTO                  => 'Giroconto',
            self::RETTIFICA                  => 'Rettifica',
            self::ACCANTONAMENTO             => 'Accantonamento',
            self::RIPARTO                    => 'Riparto',
            self::RICONCILIAZIONE_BANCARIA   => 'Riconciliazione bancaria',
        };
    }

    public function isUscitaCassa(): bool
    {
        return in_array($this, [
            self::PAGAMENTO_FORNITORE,
            self::PAGAMENTO_F24,
        ]);
    }

    public function isEntrataCassa(): bool
    {
        return in_array($this, [
            self::INCASSO_RATA,
            self::INCASSO_DIVERSO,
            self::RIMBORSO_ASSICURATIVO,
            self::STORNO_PAGAMENTO_FORNITORE,
        ]);
    }

    public function isCicloPassivo(): bool
    {
        return in_array($this, [
            self::FATTURA_ACQUISTO,
            self::NOTA_CREDITO_FORNITORE,
            self::PAGAMENTO_FORNITORE,
            self::STORNO_FATTURA,
            self::STORNO_PAGAMENTO_FORNITORE,
        ]);
    }

    public static function cicloPassivo(): array
    {
        return array_filter(self::cases(), fn($c) => $c->isCicloPassivo());
    }
}