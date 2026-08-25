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
 * Aggiunto in v1.10.0-beta.21: storno_pagamento_f24
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

    // ─── Prima nota diretta (Scrittura senza Fattura a monte) ────────────────
    /**
     * Registrazione a regolazione immediata: costo → banca/cassa in scrittura unica,
     * senza aprire una partita fornitore. Per i fatti amministrativi che nascono e si
     * estinguono nello stesso momento (bolli, commissioni bancarie, addebiti automatici).
     * Vietata dove serve la struttura del debito — vedi RegolazioneImmediataNonAmmessaException.
     */
    case REGOLAZIONE_IMMEDIATA       = 'regolazione_immediata';

    /** Scrittura inversa che annulla una regolazione immediata (giornale append-only). */
    case STORNO_REGOLAZIONE_IMMEDIATA = 'storno_regolazione_immediata';

    // ─── Adempimenti fiscali ──────────────────────────────────────────────────
    case PAGAMENTO_F24               = 'pagamento_f24';

    /** Scrittura inversa che annulla un pagamento F24 (giornale append-only). */
    case STORNO_PAGAMENTO_F24        = 'storno_pagamento_f24';

    // ─── Movimenti tecnici ────────────────────────────────────────────────────
    case APERTURA                    = 'apertura';
    case CHIUSURA                    = 'chiusura';
    case GIROCONTO                   = 'giroconto';

    /**
     * Scrittura inversa che annulla un giroconto (giornale append-only).
     * Come il giroconto, NON è entrata né uscita di cassa: sposta liquidità
     * fra partizioni dell'unico c/c, entrata e uscita si elidono.
     */
    case STORNO_GIROCONTO            = 'storno_giroconto';

    case RETTIFICA                   = 'rettifica';

    // Attivato in beta.27: RegistraContributoInCassaAction — porta a giornale
    // un "già versato" (beta.26) che l'amministratore dichiara ancora fermo su
    // una cassa/fondo del condominio. DARE cassa / AVERE Fondo Passate Gestioni,
    // sullo stesso schema di APERTURA ma senza toccare saldo_iniziale né essere
    // soggetto alla sua guardia "una volta sola per cassa".
    case ACCANTONAMENTO              = 'accantonamento';

    // ─── Futuri (v1.10+, non ancora in DB) ───────────────────────────────────
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
            self::REGOLAZIONE_IMMEDIATA      => 'Regolazione immediata',
            self::STORNO_REGOLAZIONE_IMMEDIATA => 'Storno regolazione immediata',
            self::PAGAMENTO_F24              => 'Pagamento F24',
            self::STORNO_PAGAMENTO_F24       => 'Storno pagamento F24',
            self::APERTURA                   => 'Apertura esercizio',
            self::CHIUSURA                   => 'Chiusura esercizio',
            self::GIROCONTO                  => 'Giroconto',
            self::STORNO_GIROCONTO           => 'Storno giroconto',
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
            self::REGOLAZIONE_IMMEDIATA,
        ]);
    }

    public function isEntrataCassa(): bool
    {
        return in_array($this, [
            self::INCASSO_RATA,
            self::INCASSO_DIVERSO,
            self::RIMBORSO_ASSICURATIVO,
            self::STORNO_PAGAMENTO_FORNITORE,
            // Lo storno di un'uscita di cassa è, per definizione, un rientro.
            self::STORNO_REGOLAZIONE_IMMEDIATA,
            self::STORNO_PAGAMENTO_F24,
            self::ACCANTONAMENTO,
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