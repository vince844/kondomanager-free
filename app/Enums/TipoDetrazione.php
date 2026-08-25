<?php

namespace App\Enums;

/**
 * Tipologia di detrazione fiscale per il Bonifico Parlante.
 *
 * Persistito come VARCHAR(50) su pagamenti_fornitori.tipo_detrazione.
 * NULL se il pagamento non è un bonifico parlante.
 *
 * Ogni case include il riferimento normativo ufficiale da inserire
 * nella causale del bonifico, evitando switch sparsi nel codice.
 *
 * NOTA: La banca trattiene automaticamente l'**11%** sul bonifico parlante e lo versa
 * all'A.d.E. (ritenuta ex art. 25 DL 78/2010, diversa dalla ritenuta d'acconto del
 * condominio). Era scritto 8%: l'aliquota è salita all'11% per i bonifici disposti dal
 * 1° marzo 2024 (L. 213/2023), e la pagina divulgativa dell'Agenzia riporta ancora il valore
 * vecchio — corretto qui il 04/08/2026, allineandolo a `MotivoEsclusioneRitenuta`, che l'11%
 * lo diceva già.
 *
 * Questo enum NON gestisce tale ritenuta — solo il riferimento normativo per la causale. Il
 * condominio non la versa e non la certifica: la opera la banca sul fornitore. Quel che il
 * condominio deve fare è **non applicare la propria**, ed è ciò che accade dal momento in cui
 * un pagamento è marcato come bonifico parlante (vedi `PagamentoFornitoreService`).
 */
enum TipoDetrazione: string
{
    case RISTRUTTURAZIONE = 'ristrutturazione';
    case ECOBONUS         = 'ecobonus';
    case SISMABONUS       = 'sismabonus';
    case SUPERBONUS       = 'superbonus';
    case ALTRO            = 'altro';

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function label(): string
    {
        return match($this) {
            self::RISTRUTTURAZIONE => 'Ristrutturazione edilizia',
            self::ECOBONUS         => 'Ecobonus / Riqualificazione energetica',
            self::SISMABONUS       => 'Sismabonus',
            self::SUPERBONUS       => 'Superbonus',
            self::ALTRO            => 'Altra detrazione',
        };
    }

    /**
     * Riferimento normativo ufficiale per la causale del bonifico parlante.
     * Inserito verbatim nella causale inviata alla banca.
     */
    public function riferimentoNormativo(): string
    {
        return match($this) {
            self::RISTRUTTURAZIONE => 'art. 16-bis DPR 917/1986',
            self::ECOBONUS         => 'art. 14 DL 63/2013',
            self::SISMABONUS       => 'art. 16 DL 63/2013',
            self::SUPERBONUS       => 'art. 119 DL 34/2020',
            self::ALTRO            => 'Normativa specifica',
        };
    }

    /**
     * Percentuale di detrazione standard (indicativa, può variare per anno/soglia).
     * NON usare per calcoli fiscali definitivi — sempre verificare normativa aggiornata.
     */
    public function percentualeDetrazione(): string
    {
        return match($this) {
            self::RISTRUTTURAZIONE => '50%',
            self::ECOBONUS         => '50%-65%-90%',
            self::SISMABONUS       => '50%-70%-80%',
            self::SUPERBONUS       => '110%-90%',
            self::ALTRO            => 'Variabile',
        };
    }

    /**
     * Genera la causale completa per il bonifico parlante.
     * Formato A.d.E.: tipo + riferimento normativo + CF beneficiari + P.IVA fornitore + FT.
     *
     * @param  array  $cfBeneficiari   Lista di codici fiscali condòmini beneficiari
     * @param  string $partitaIvaFornitore
     * @param  string $numeroFattura
     * @param  string $dataFattura
     */
    public function generaCausale(
        array $cfBeneficiari,
        string $partitaIvaFornitore,
        string $numeroFattura,
        string $dataFattura
    ): string {
        $cfString = implode(', ', $cfBeneficiari);
        $tipo = $this->label();
        $normativa = $this->riferimentoNormativo();

        $causale = "Bonifico relativo a {$tipo} - {$normativa} - "
            . "Beneficiari CF: {$cfString} - "
            . "P.IVA: {$partitaIvaFornitore} - "
            . "FT {$numeroFattura} del {$dataFattura}";

        // Le causali bancarie SEPA hanno limite ~140 caratteri in RmtInf.
        // Se supera la soglia, il chiamante deve generare PDF allegato e
        // usare causale abbreviata con riferimento al PDF (es. "vedi distinta prot. XXX").
        return $causale;
    }

    /** Verifica se la causale generata rientra nel limite SEPA (~140 caratteri). */
    public function causaleEntraNelLimiteSEPA(
        array $cfBeneficiari,
        string $partitaIvaFornitore,
        string $numeroFattura,
        string $dataFattura
    ): bool {
        return strlen($this->generaCausale(
            $cfBeneficiari,
            $partitaIvaFornitore,
            $numeroFattura,
            $dataFattura
        )) <= 140;
    }
}