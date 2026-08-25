<?php

namespace App\Services\Gestionale;

use App\DataTransferObjects\RitenutaCalcolo;
use App\Enums\Fiscale\MotivoEsclusioneRitenuta;
use App\Models\Fornitore;
use Carbon\CarbonInterface;

/**
 * Calcolo puro della ritenuta d'acconto su una fattura passiva. Nessun
 * effetto sul ledger, nessuna scrittura, nessuna query oltre a quelle già
 * caricate sul model — solo "quanto" e "perché".
 *
 * Fase 1 (docs/design/f24_ritenute_design.md §5): il fatto generatore resta
 * quello odierno (registrazione). Sposta il calcolo dell'aliquota da un
 * campo fisso per fornitore (perc_ritenuta) a un motore basato su
 * TipoRitenuta + NaturaPercipiente, MA resta compatibile con i fornitori
 * legacy che non sono ancora stati migrati al nuovo regime.
 *
 * Esclusioni gestite qui: SOLO il regime forfetario, perché è un fatto di
 * legge sul fornitore (mai una scelta per singolo documento). Bonifico
 * parlante, fuori campo e posa accessoria sono scelte per documento/riga:
 * il chiamante le traduce in `$richiestaApplicazione = false` (con
 * `motivo_esclusione_ritenuta` audit-ato a parte) o in righe con
 * `concorre_base_ritenuta = false` — questo servizio si limita a rispettarle.
 */
class RitenutaService
{
    /**
     * @param  array<int, array{importo_imponibile:int, concorre_base_ritenuta?:bool}>  $righe
     *         Importi in centesimi. Vuoto per le fatture pregresse (nessuna riga
     *         di dettaglio): in quel caso $imponibileTotaleFallback fa da base intera.
     * @param  bool  $richiestaApplicazione  `fornitore->soggetto_ritenuta && (applica_ritenuta ?? default)`,
     *         già risolto dal chiamante (che conosce le regole su nota di credito e storno).
     */
    public function calcola(
        Fornitore $fornitore,
        array $righe,
        int $imponibileTotaleFallback,
        bool $richiestaApplicazione,
        CarbonInterface $dataDocumento,
    ): RitenutaCalcolo {
        $base = $this->baseImponibile($righe, $imponibileTotaleFallback);

        if ($fornitore->regime_forfetario) {
            return RitenutaCalcolo::esclusa($base, MotivoEsclusioneRitenuta::FORFETARIO);
        }

        if (! $fornitore->soggetto_ritenuta || ! $richiestaApplicazione) {
            return RitenutaCalcolo::nonApplicata($base);
        }

        return $fornitore->tipo_ritenuta
            ? $this->calcolaRegimeNuovo($fornitore, $base, $dataDocumento)
            : $this->calcolaRegimeLegacy($fornitore, $base);
    }

    /**
     * Somma la base ritenuta escludendo le righe con `concorre_base_ritenuta = false`
     * (contributo cassa professionale, rimborsi art. 15, posa accessoria — fix
     * del difetto per cui oggi ogni riga entra indiscriminatamente in base,
     * design §8 punto 9). Righe senza il flag sono trattate come concorrenti,
     * per non alterare il comportamento di fatture registrate prima di M3.
     */
    private function baseImponibile(array $righe, int $fallback): int
    {
        if (empty($righe)) {
            return $fallback;
        }

        return array_sum(array_map(
            fn (array $r) => ($r['concorre_base_ritenuta'] ?? true) ? (int) $r['importo_imponibile'] : 0,
            $righe
        ));
    }

    private function calcolaRegimeNuovo(Fornitore $fornitore, int $base, CarbonInterface $dataDocumento): RitenutaCalcolo
    {
        $tipo = $fornitore->tipo_ritenuta;
        $aliquota = $tipo->aliquota($dataDocumento);
        $percBase = $tipo->percentualeBase();

        $baseCalcolo = (int) round($base * $percBase / 100);
        $importo = (int) round($baseCalcolo * $aliquota / 100);

        // natura_percipiente assente: warning bloccante con override è compito
        // della UI (v1.10, design §2.4 M2); qui non blocchiamo il calcolo,
        // torniamo al codice tributo legacy se presente come override motivato.
        $codiceTributo = $fornitore->natura_percipiente
            ? $tipo->codiceTributo($fornitore->natura_percipiente)
            : ($fornitore->codice_tributo ?: null);

        return new RitenutaCalcolo(
            applicata: true,
            importo: $importo,
            baseImponibile: $baseCalcolo,
            aliquota: $aliquota,
            percentualeBase: $percBase,
            codiceTributo: $codiceTributo,
            tipoRitenuta: $tipo,
            titolo: $tipo->titolo(),
            riferimentoNormativo: $tipo->riferimentoNormativo($dataDocumento),
            motivoEsclusione: null,
            nota: $this->nota($aliquota),
        );
    }

    private function calcolaRegimeLegacy(Fornitore $fornitore, int $base): RitenutaCalcolo
    {
        // Stessa disciplina di TipoRitenuta::aliquota(): un fornitore soggetto
        // a ritenuta senza percentuale configurata non deve MAI calcolare uno
        // zero silenzioso — sembrerebbe "nessuna ritenuta dovuta" invece di
        // "anagrafica incompleta". In pratica CreateFornitoreRequest/
        // UpdateFornitoreRequest rendono perc_ritenuta obbligatorio quando
        // soggetto_ritenuta è true, quindi qui arriva solo da dati corrotti
        // o inseriti fuori dal flusso UI (import, seeding, ecc.).
        if ($fornitore->perc_ritenuta === null) {
            throw new \DomainException(
                "Fornitore #{$fornitore->id} ({$fornitore->ragione_sociale}) è soggetto a ritenuta ma non ha una percentuale configurata (né il regime nuovo né perc_ritenuta legacy). Impossibile calcolare la ritenuta senza inventare un'aliquota."
            );
        }

        $percBase = (float) ($fornitore->perc_imponibile_ritenuta ?? 100);
        $aliquota = (float) $fornitore->perc_ritenuta;

        $baseCalcolo = (int) round($base * $percBase / 100);
        $importo = (int) round($baseCalcolo * $aliquota / 100);

        return new RitenutaCalcolo(
            applicata: true,
            importo: $importo,
            baseImponibile: $baseCalcolo,
            aliquota: $aliquota,
            percentualeBase: $percBase,
            codiceTributo: $fornitore->codice_tributo,
            tipoRitenuta: null,
            titolo: null,
            riferimentoNormativo: null,
            motivoEsclusione: null,
            nota: $this->nota($aliquota),
        );
    }

    /** Nota della riga AVERE 2202: aliquota reale, mai "4%" fisso (design §8 punto 6). */
    private function nota(float $aliquota): string
    {
        $formattata = rtrim(rtrim(number_format($aliquota, 2, ',', ''), '0'), ',');

        return "Ritenuta d'acconto {$formattata}% fattura fornitore";
    }
}
