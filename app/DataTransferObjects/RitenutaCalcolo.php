<?php

namespace App\DataTransferObjects;

use App\Enums\Fiscale\MotivoEsclusioneRitenuta;
use App\Enums\Fiscale\TipoRitenuta;
use App\Enums\Fiscale\TitoloRitenuta;

/**
 * Esito del calcolo di RitenutaService. Importi in centesimi.
 *
 * Fase 1 (docs/design/f24_ritenute_design.md §5): oggetto di sola lettura,
 * nessun effetto collaterale — il chiamante decide cosa farne (riga di
 * scrittura, dati_extra, messaggio UI).
 */
class RitenutaCalcolo
{
    public function __construct(
        public readonly bool $applicata,
        public readonly int $importo,
        public readonly int $baseImponibile,
        public readonly float $aliquota,
        public readonly float $percentualeBase,
        public readonly ?string $codiceTributo,
        public readonly ?TipoRitenuta $tipoRitenuta,
        public readonly ?TitoloRitenuta $titolo,
        public readonly ?string $riferimentoNormativo,
        public readonly ?MotivoEsclusioneRitenuta $motivoEsclusione,
        public readonly ?string $nota,
    ) {
    }

    public static function nonApplicata(int $baseImponibile): self
    {
        return new self(
            applicata: false,
            importo: 0,
            baseImponibile: $baseImponibile,
            aliquota: 0.0,
            percentualeBase: 100.0,
            codiceTributo: null,
            tipoRitenuta: null,
            titolo: null,
            riferimentoNormativo: null,
            motivoEsclusione: null,
            nota: null,
        );
    }

    public static function esclusa(int $baseImponibile, MotivoEsclusioneRitenuta $motivo): self
    {
        $dto = self::nonApplicata($baseImponibile);

        return new self(
            applicata: false,
            importo: 0,
            baseImponibile: $dto->baseImponibile,
            aliquota: 0.0,
            percentualeBase: 100.0,
            codiceTributo: null,
            tipoRitenuta: null,
            titolo: null,
            riferimentoNormativo: null,
            motivoEsclusione: $motivo,
            nota: null,
        );
    }

    /**
     * Importo fissato dall'esterno, MAI ricalcolato da RitenutaService.
     *
     * Uso: lo storno di una fattura (design §8 punto 2 — revisione avversariale
     * beta.21). RitenutaService legge sempre lo stato ATTUALE del fornitore
     * (`regime_forfetario`, `soggetto_ritenuta`, `tipo_ritenuta`): se l'anagrafica
     * cambia fra la registrazione e lo storno, ricalcolare produce un importo
     * diverso da quello effettivamente registrato — esattamente il residuo
     * "fantasma" su 2201/2202 che il punto 2 doveva chiudere, riaperto da
     * un'altra porta. Lo storno deve annullare l'importo REALE, non un importo
     * "corretto secondo le regole di oggi".
     */
    public static function override(int $importoCents, ?float $aliquota, ?string $codiceTributo, ?int $baseImponibile = null): self
    {
        if ($importoCents <= 0) {
            return self::nonApplicata($baseImponibile ?? 0);
        }

        $nota = $aliquota !== null
            ? sprintf("Ritenuta d'acconto %s%% fattura fornitore (storno — importo originale)", rtrim(rtrim(number_format($aliquota, 2, ',', ''), '0'), ','))
            : "Ritenuta d'acconto fattura fornitore (storno — importo originale)";

        return new self(
            applicata: true,
            importo: $importoCents,
            baseImponibile: $baseImponibile ?? $importoCents,
            aliquota: $aliquota ?? 0.0,
            percentualeBase: 100.0,
            codiceTributo: $codiceTributo,
            tipoRitenuta: null,
            titolo: null,
            riferimentoNormativo: null,
            motivoEsclusione: null,
            nota: $nota,
        );
    }

    /**
     * Struttura storica di `dati_extra.fiscal.ritenuta_details`: FatturaShow.vue
     * la legge ancora così (aliquota, codice_tributo). Fase 1 non tocca il
     * frontend di visualizzazione, quindi la forma resta invariata.
     */
    public function toLegacyDatiExtra(): ?array
    {
        if (! $this->applicata) {
            return null;
        }

        return [
            'imponibile_calcolo' => $this->baseImponibile,
            'aliquota' => $this->aliquota,
            'codice_tributo' => $this->codiceTributo,
        ];
    }
}
