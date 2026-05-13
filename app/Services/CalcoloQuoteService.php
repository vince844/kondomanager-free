<?php

namespace App\Services;

use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servizio per il calcolo delle quote di spesa/entrata per ogni gestione.
 *
 * VERSION: 1.9.5-hotfix1 (DIAGNOSTIC LOGGING)
 *
 * =========================================================================
 * ARCHITETTURA DEL RULE ENGINE (Motore di Ripartizione)
 * =========================================================================
 * Livello 1: OVERRIDE STRUTTURALE (immobile_id NOT NULL su riga_fattura).
 * Livello 2: REGOLA NATURA SPESA (conto_id NOT NULL su riga_fattura).
 * Livello 3: FALLBACK LEGALE (Soggetto Pagatore).
 * Livello 4: PENNY-PERFECT ALGORITHM.
 */
class CalcoloQuoteService
{
    private ?Gestione $gestioneCorrente = null;
    private array $pivotOverrides = [];
    private ?\Carbon\Carbon $pianoRateCreatedAt = null;

    // =========================================================================
    // MOTORE ORDINARIO
    // =========================================================================

    public function calcolaPerGestione(Gestione $gestione, ?PianoRate $pianoRate = null): array
    {
        $this->gestioneCorrente = $gestione;
        $this->pivotOverrides   = [];
        $this->pianoRateCreatedAt = $pianoRate?->created_at;
        $totali = [];
        $pianoConto = $gestione->pianoConto;

        if (!$pianoConto) {
            Log::error("calcolaPerGestione: gestione ID={$gestione->id} non ha un piano dei conti associato.");
            return [];
        }

        $capitoliIds = [];
        if ($pianoRate) {
            $pianoRate->load('capitoli');
            foreach ($pianoRate->capitoli as $capitolo) {
                $capitoliIds[] = $capitolo->id;
                if (!is_null($capitolo->pivot->importo)) {
                    $this->pivotOverrides[$capitolo->id] = (int) $capitolo->pivot->importo;
                }
            }

            // [DIAG] Log capitoli senza override (importo pivot NULL)
            $senzaOverride = $pianoRate->capitoli->filter(fn($c) => is_null($c->pivot->importo));
            if ($senzaOverride->isNotEmpty()) {
                Log::debug("calcolaPerGestione: capitoli senza override (pivot.importo NULL)", [
                    'piano_rate_id' => $pianoRate->id,
                    'capitoli_senza_override' => $senzaOverride->pluck('nome', 'id')->toArray(),
                ]);
            }
        }

        $query = $pianoConto->conti()
            ->with([
                'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'tabelleMillesimali.ripartizioni',
                'sottoconti.tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'sottoconti.tabelleMillesimali.ripartizioni',
                'sottoconti.sottoconti',
            ]);

        if (!empty($capitoliIds)) {
            $query->whereIn('id', $capitoliIds);
        } else {
            $query->whereNull('parent_id');
        }

        $conti = $query->get();

        // [DIAG] Log se non vengono trovati conti
        if ($conti->isEmpty()) {
            Log::warning("calcolaPerGestione: nessun conto trovato per il piano dei conti ID={$pianoConto->id}", [
                'piano_rate_id' => $pianoRate?->id,
                'capitoli_cercati' => $capitoliIds,
            ]);
            return [];
        }

        Log::info("=== INIZIO CALCOLO QUOTE ORDINARIO V1.9.5 ===", [
            'piano_rate_id' => $pianoRate?->id,
            'overrides'     => count($this->pivotOverrides),
            'conti_caricati' => $conti->count(),
        ]);

        $this->processaConti($conti, $totali);

        // [DIAG] Riepilogo delta: importo pianificato vs importo effettivamente distribuito
        $totaleOverrides   = array_sum($this->pivotOverrides);
        $totaleDistribuito = 0;
        foreach ($totali as $immobili) {
            foreach ($immobili as $importo) {
                $totaleDistribuito += $importo;
            }
        }
        $delta = $totaleOverrides - $totaleDistribuito;

        Log::info("Riepilogo Ripartizione", [
            'piano_rate_id'              => $pianoRate?->id,
            'importo_override_pianificato' => $totaleOverrides,
            'importo_effettivamente_distribuito' => $totaleDistribuito,
            'delta_non_ripartito'        => $delta,
            'soggetti_trovati'           => count($totali),
        ]);

        if ($delta !== 0) {
            Log::warning("calcolaPerGestione: delta non ripartito = {$delta} centesimi. Verificare tabelle millesimali.", [
                'piano_rate_id' => $pianoRate?->id,
            ]);
        }

        return $totali;
    }

    // =========================================================================
    // MOTORE STRAORDINARIO
    // =========================================================================

    public function calcolaDaFattureStraordinarie(PianoRate $pianoRate): array
    {
        $fattureIds = $pianoRate->fatture->pluck('id')->toArray();

        if (empty($fattureIds)) {
            throw new \RuntimeException(
                "Piano straordinario ID {$pianoRate->id} non ha fatture collegate. Impossibile calcolare le quote."
            );
        }

        $righe = DB::table('righe_fattura')
            ->whereIn('fattura_passiva_id', $fattureIds)
            ->get();

        $totali = [];

        foreach ($righe as $riga) {
            $importoCents = abs($riga->importo_imponibile + $riga->importo_iva);

            if ($importoCents === 0) continue;

            if (!is_null($riga->immobile_id)) {
                $this->addebitaDiretto((int) $riga->immobile_id, $importoCents, $totali);
            } else {
                if (is_null($riga->conto_id)) {
                    Log::warning("calcolaDaFattureStraordinarie: riga senza conto_id e senza immobile_id, saltata.", [
                        'riga_id'            => $riga->id,
                        'fattura_passiva_id' => $riga->fattura_passiva_id,
                    ]);
                    continue;
                }

                $conto = Conto::with([
                    'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                    'tabelleMillesimali.ripartizioni',
                ])->find($riga->conto_id);

                if (!$conto) {
                    Log::warning("calcolaDaFattureStraordinarie: conto_id={$riga->conto_id} non trovato, riga saltata.");
                    continue;
                }

                $importoConto = in_array($conto->tipo, ['spesa', 'uscita'])
                    ? $importoCents
                    : -$importoCents;

                $this->distribuisciSuTabelle($conto, $importoConto, $totali);
            }
        }

        Log::info("=== CALCOLO STRAORDINARIO COMPLETATO ===", [
            'piano_rate_id'    => $pianoRate->id,
            'fatture_ids'      => $fattureIds,
            'righe_elaborate'  => $righe->count(),
            'soggetti_trovati' => count($totali),
        ]);

        return $totali;
    }

    // =========================================================================
    // METODI PRIVATI
    // =========================================================================

    private function addebitaDiretto(int $immobileId, int $importoCents, array &$totali): void
    {
        $proprietari = DB::table('anagrafica_immobile')
            ->where('immobile_id', $immobileId)
            ->where('attivo', true)
            ->where('tipologia', 'proprietario')
            ->get();

        if ($proprietari->isEmpty()) {
            $proprietari = DB::table('anagrafica_immobile')
                ->where('immobile_id', $immobileId)
                ->where('attivo', true)
                ->get();
        }

        if ($proprietari->isEmpty()) {
            Log::warning("addebitaDiretto: nessun occupante attivo per immobile_id={$immobileId}. Importo {$importoCents} centesimi non assegnato.");
            return;
        }

        $totaleQuoteMillesimali = (float) $proprietari->sum('quota');
        if ($totaleQuoteMillesimali <= 0) $totaleQuoteMillesimali = 1.0;

        $assegnato = 0;
        $count     = $proprietari->count();
        $i         = 0;

        foreach ($proprietari as $prop) {
            $i++;
            if ($i === $count) {
                $quotaDaPagare = $importoCents - $assegnato;
            } else {
                $quotaDaPagare = (int) round($importoCents * ($prop->quota / $totaleQuoteMillesimali));
                $assegnato += $quotaDaPagare;
            }

            if ($quotaDaPagare === 0) continue;

            if (!isset($totali[$prop->anagrafica_id])) $totali[$prop->anagrafica_id] = [];
            if (!isset($totali[$prop->anagrafica_id][$immobileId])) $totali[$prop->anagrafica_id][$immobileId] = 0;

            $totali[$prop->anagrafica_id][$immobileId] += $quotaDaPagare;
        }
    }

    private function processaConti(Collection $conti, array &$totali): void
    {
        foreach ($conti as $conto) {

            $hasOverride = isset($this->pivotOverrides[$conto->id]);

            if ($hasOverride) {
                $importoOverride = $this->pivotOverrides[$conto->id];

                if ($conto->tabelleMillesimali->isNotEmpty()) {
                    $importoConto = in_array($conto->tipo, ['spesa', 'uscita'])
                        ? abs($importoOverride)
                        : -abs($importoOverride);

                    $this->distribuisciSuTabelle($conto, $importoConto, $totali);
                    continue;
                }

                elseif ($conto->sottoconti->isNotEmpty()) {

                    $sottocontiFiltrati = $this->pianoRateCreatedAt
                        ? $conto->sottoconti->filter(
                            fn($s) => $s->created_at->lte($this->pianoRateCreatedAt)
                        )
                        : $conto->sottoconti;

                    if ($sottocontiFiltrati->isEmpty() && $importoOverride > 0) {
                        Log::warning("processaConti: snapshot vuoto per conto ID={$conto->id} ('{$conto->nome}'), fallback su tutti i sottoconti correnti.", [
                            'importo_override' => $importoOverride,
                        ]);
                        $sottocontiFiltrati = $conto->sottoconti;
                    }

                    if ($sottocontiFiltrati->isEmpty()) continue;

                    $totaleOriginaleFigli = (int) $sottocontiFiltrati->sum('importo');
                    $totaleFigli  = $sottocontiFiltrati->count();
                    $sommaAssegnata = 0;
                    $counter = 0;

                    foreach ($sottocontiFiltrati as $figlio) {
                        $counter++;
                        if ($counter === $totaleFigli) {
                            $quotaFiglio = $importoOverride - $sommaAssegnata;
                        } elseif ($totaleOriginaleFigli > 0) {
                            $quotaFiglio = (int) round($importoOverride * ($figlio->importo / $totaleOriginaleFigli));
                        } else {
                            $quotaFiglio = (int) round($importoOverride / $totaleFigli);
                        }
                        $sommaAssegnata += $quotaFiglio;
                        $this->pivotOverrides[$figlio->id] = $quotaFiglio;
                    }

                    $this->processaConti($sottocontiFiltrati, $totali);
                    continue;
                }

                // [DIAG] Silent discard intercettato — questo è il bug che causa quote_create:0
                Log::warning("processaConti: SILENT DISCARD — conto ID={$conto->id} ('{$conto->nome}') ha override ma nessuna tabella millesimale e nessun sottoconto.", [
                    'importo_override_ignorato_cents' => $importoOverride,
                    'conto_tipo'  => $conto->tipo,
                    'conto_nome'  => $conto->nome,
                ]);
                continue;
            }

            // Branch senza override: usa importo live del conto
            $importoLordo = (int) $conto->importo;

            if ($importoLordo !== 0) {
                $tipo = $conto->tipo ?? 'spesa';
                $importoConto = in_array($tipo, ['spesa', 'uscita'])
                    ? abs($importoLordo)
                    : -abs($importoLordo);

                $this->distribuisciSuTabelle($conto, $importoConto, $totali);
            }

            if ($conto->sottoconti && $conto->sottoconti->count() > 0) {
                $this->processaConti($conto->sottoconti, $totali);
            }
        }
    }

    private function distribuisciSuTabelle(Conto $conto, int $importoConto, array &$totali): void
    {
        $weights = [];

        // [DIAG] Nessuna tabella millesimale collegata al conto
        if ($conto->tabelleMillesimali->isEmpty()) {
            Log::warning("distribuisciSuTabelle: conto ID={$conto->id} ('{$conto->nome}') non ha tabelle millesimali collegate. Importo {$importoConto} non distribuito.");
            return;
        }

        foreach ($conto->tabelleMillesimali as $ctm) {
            $tabella = $ctm->tabella ?? null;

            if (!$tabella) {
                Log::warning("distribuisciSuTabelle: conto_tabella_millesimale ID={$ctm->id} non ha una tabella collegata.", [
                    'conto_id' => $conto->id,
                ]);
                continue;
            }

            $coeff = (float) $ctm->coefficiente;
            if ($coeff <= 0) {
                Log::debug("distribuisciSuTabelle: coefficiente <= 0 per tabella ID={$tabella->id}, conto ID={$conto->id}. Saltata.");
                continue;
            }

            $weightCoeff = $coeff / 100.0;
            $quote = $tabella->quote;

            // [DIAG] Tabella senza quote millesimali
            if ($quote->isEmpty()) {
                Log::warning("distribuisciSuTabelle: tabella millesimale ID={$tabella->id} ('{$tabella->nome}') non ha quote inserite. Importo non distribuito per conto ID={$conto->id}.", [
                    'conto_nome'   => $conto->nome,
                    'tabella_nome' => $tabella->nome,
                ]);
                continue;
            }

            $sommaValori = (float) $quote->sum('valore');

            // [DIAG] Somma millesimi = 0
            if ($sommaValori <= 0.0) {
                Log::warning("distribuisciSuTabelle: tabella ID={$tabella->id} ('{$tabella->nome}') ha quote ma la somma dei valori è zero. Verificare i millesimi inseriti.", [
                    'conto_id'    => $conto->id,
                    'conto_nome'  => $conto->nome,
                    'num_quote'   => $quote->count(),
                ]);
                continue;
            }

            foreach ($quote as $quota) {
                $immobile = $quota->immobile ?? null;

                if (!$immobile) {
                    Log::debug("distribuisciSuTabelle: quota ID={$quota->id} in tabella ID={$tabella->id} non ha immobile associato. Saltata.");
                    continue;
                }

                $valore = (float) $quota->valore;

                // [DIAG] Valore millesimale zero per immobile specifico
                if ($valore <= 0.0) {
                    Log::debug("distribuisciSuTabelle: immobile ID={$immobile->id} ha valore millesimale zero nella tabella ID={$tabella->id}. Saltato.");
                    continue;
                }

                $weightImmobile = $weightCoeff * ($valore / $sommaValori);

                $ripartizioni = $ctm->ripartizioni->isNotEmpty()
                    ? $ctm->ripartizioni
                    : collect([(object) ['soggetto' => 'proprietario', 'percentuale' => 100.0]]);

                foreach ($ripartizioni as $rip) {
                    $percent = (float) $rip->percentuale;
                    if ($percent <= 0.0) continue;

                    $weightRip = $weightImmobile * ($percent / 100.0);

                    $anagrafiche = $immobile->anagrafiche
                        ->where('pivot.attivo', true)
                        ->where('pivot.tipologia', $rip->soggetto);

                    // Rule Engine Livello 3: Fallback legale al proprietario
                    if ($anagrafiche->isEmpty() && in_array($rip->soggetto, ['inquilino', 'usufruttuario'])) {
                        Log::debug("distribuisciSuTabelle: nessun {$rip->soggetto} attivo per immobile ID={$immobile->id}, fallback al proprietario.");
                        $anagrafiche = $immobile->anagrafiche
                            ->where('pivot.attivo', true)
                            ->where('pivot.tipologia', 'proprietario');
                    }

                    // [DIAG] Nessuna anagrafica attiva per questo immobile e ruolo
                    if ($anagrafiche->isEmpty()) {
                        Log::warning("distribuisciSuTabelle: nessuna anagrafica attiva con ruolo '{$rip->soggetto}' (né fallback proprietario) per immobile ID={$immobile->id}. Quota non assegnata.", [
                            'conto_id'    => $conto->id,
                            'tabella_id'  => $tabella->id,
                            'immobile_id' => $immobile->id,
                        ]);
                        continue;
                    }

                    $sommaQuote = (float) $anagrafiche->sum('pivot.quota');
                    if ($sommaQuote <= 0.0) $sommaQuote = 1.0;

                    foreach ($anagrafiche as $anag) {
                        $quotaAnag = (float) $anag->pivot->quota;
                        if ($quotaAnag <= 0.0) continue;

                        $weightAnagrafica = $weightRip * ($quotaAnag / $sommaQuote);
                        $key = $anag->id . '|' . $immobile->id;
                        $weights[$key] = ($weights[$key] ?? 0.0) + $weightAnagrafica;
                    }
                }
            }
        }

        // [DIAG] Pesi finali vuoti: nessuna quota sarà generata per questo conto
        if (empty($weights)) {
            Log::warning("distribuisciSuTabelle: nessun peso calcolato per conto ID={$conto->id} ('{$conto->nome}'). Importo {$importoConto} centesimi NON distribuito. Causa probabile: tabelle millesimali vuote o anagrafiche mancanti.", [
                'conto_id'    => $conto->id,
                'conto_nome'  => $conto->nome,
                'importo'     => $importoConto,
            ]);
            return;
        }

        $pesoTotale = array_sum($weights);
        if ($pesoTotale <= 0.0) return;

        foreach ($weights as $key => $w) {
            $weights[$key] = $w / $pesoTotale;
        }

        $importiDistributi = $this->distribuisciImporto($weights, $importoConto);

        foreach ($importiDistributi as $key => $importoCentesimi) {
            [$aid, $iid] = array_map('intval', explode('|', $key));

            if (!isset($totali[$aid])) $totali[$aid] = [];
            if (!isset($totali[$aid][$iid])) $totali[$aid][$iid] = 0;

            $totali[$aid][$iid] += $importoCentesimi;
        }
    }

    private function distribuisciImporto(array $weights, int $importoTotale): array
    {
        $result = [];
        if ($importoTotale === 0) {
            foreach ($weights as $key => $_) { $result[$key] = 0; }
            return $result;
        }

        $sign    = $importoTotale < 0 ? -1 : 1;
        $totAbs  = abs($importoTotale);
        $bases      = [];
        $remainders = [];
        $sumBase    = 0;

        foreach ($weights as $key => $w) {
            $raw   = $totAbs * $w;
            $base  = (int) floor($raw);
            $bases[$key]      = $base;
            $remainders[$key] = $raw - $base;
            $sumBase += $base;
        }

        $diff = $totAbs - $sumBase;
        if ($diff > 0) {
            arsort($remainders);
            $keys = array_keys($remainders);
            for ($i = 0; $i < $diff && $i < count($keys); $i++) {
                $bases[$keys[$i]]++;
            }
        }

        foreach ($bases as $key => $b) {
            $result[$key] = $b * $sign;
        }

        return $result;
    }
}
