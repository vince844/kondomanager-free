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
 * VERSION: 1.9.6 (CASCADE RESOLUTION + SCOPERTO BUCKET)
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
    
    /** @var array Accumulatore per le quote non assegnabili per mancanza di anagrafiche attive */
    private array $scopertiAccumulati = [];

    // =========================================================================
    // MOTORE ORDINARIO
    // =========================================================================

    /**
     * Calcola le quote per un'intera gestione (preventivo o rateizzazione).
     *
     * @param Gestione $gestione La gestione di riferimento
     * @param PianoRate|null $pianoRate Opzionale piano rate per determinare il momento di validità degli overrides
     * @return array Quote calcolate, raggruppate per anagrafica_id e immobile_id
     */
    public function calcolaPerGestione(Gestione $gestione, ?PianoRate $pianoRate = null): array
    {
        $this->gestioneCorrente = $gestione;
        $this->pivotOverrides   = [];
        $this->pianoRateCreatedAt = $pianoRate?->created_at;
        $this->scopertiAccumulati = [];
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

        $contiImpegnatiIds = [];
        if (!empty($capitoliIds)) {
            $query->whereIn('id', $capitoliIds);
        } else {
            $query->whereNull('parent_id');
            if ($pianoRate) {
                $contiImpegnatiIds = \Illuminate\Support\Facades\DB::table('piano_rate_capitoli')
                    ->join('piani_rate', 'piano_rate_capitoli.piano_rate_id', '=', 'piani_rate.id')
                    ->where('piani_rate.gestione_id', $gestione->id)
                    ->where('piani_rate.attivo', true)
                    ->where('piani_rate.id', '!=', $pianoRate->id)
                    ->pluck('conto_id')
                    ->toArray();
            }
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

        Log::info("=== INIZIO CALCOLO QUOTE ORDINARIO V1.9.6 ===", [
            'piano_rate_id' => $pianoRate?->id,
            'overrides'     => count($this->pivotOverrides),
            'conti_caricati' => $conti->count(),
        ]);

        $processatiIds = [];
        $this->processaConti($conti, $totali, $contiImpegnatiIds, $processatiIds);

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

    /**
     * Calcola le quote per una rateizzazione di fatture straordinarie.
     *
     * @param PianoRate $pianoRate Il piano rate contenente le fatture straordinarie
     * @return array Quote calcolate, raggruppate per anagrafica_id e immobile_id
     */
    public function calcolaDaFattureStraordinarie(PianoRate $pianoRate): array
    {
        $this->scopertiAccumulati = [];
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

    /**
     * @return array Dati relativi agli importi scoperti durante il calcolo.
     */
    public function getScoperti(): array
    {
        return $this->scopertiAccumulati;
    }

    // =========================================================================
    // METODI PRIVATI
    // =========================================================================

    /**
     * Esegue un addebito diretto forzato su un singolo immobile (es. addebito personale).
     *
     * @param int $immobileId L'ID dell'immobile
     * @param int $importoCents L'importo in centesimi
     * @param array &$totali Array in cui accumulare la quota
     */
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

    /**
     * Processa iterativamente una collezione di conti e calcola la ripartizione su ognuno.
     *
     * @param Collection $conti La collezione di conti da elaborare
     * @param array &$totali Array in cui accumulare i risultati
     */
    private function processaConti(Collection $conti, array &$totali, array $contiImpegnatiIds = [], array &$processatiIds = []): void
    {
        foreach ($conti as $conto) {
            if (in_array($conto->id, $contiImpegnatiIds)) continue;
            if (in_array($conto->id, $processatiIds)) continue;
            $processatiIds[] = $conto->id;

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

                    $this->processaConti($sottocontiFiltrati, $totali, $contiImpegnatiIds, $processatiIds);
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
                $this->processaConti($conto->sottoconti, $totali, $contiImpegnatiIds, $processatiIds);
            }
        }
    }

    /**
     * Algoritmo core: Ripartisce l'importo di un conto sugli immobili collegati alle tabelle,
     * risolvendo la cascata del ruolo e mantenendo traccia degli scoperti.
     *
     * @param Conto $conto Conto di spesa da ripartire
     * @param int $importoConto Importo in centesimi
     * @param array &$totali Array in cui accumulare i risultati
     */
    private function distribuisciSuTabelle(Conto $conto, int $importoConto, array &$totali): void
    {
        $weights      = [];
        $pesiScoperti = [];

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

                    // Rule Engine Livello 3: Risoluzione a cascata del ruolo (catena per natura)
                    if ($anagrafiche->isEmpty() && $rip->soggetto !== 'proprietario') {
                        $catenaGodimento = ['inquilino', 'usufruttuario', 'proprietario'];
                        $catenaCapitale  = ['nuda_proprietario', 'proprietario'];

                        $catena = in_array($rip->soggetto, $catenaCapitale, true)
                            ? $catenaCapitale
                            : $catenaGodimento;

                        $start     = array_search($rip->soggetto, $catena, true);
                        $candidati = $start === false ? $catena : array_slice($catena, $start + 1);

                        foreach ($candidati as $ruoloFallback) {
                            $anagrafiche = $immobile->anagrafiche
                                ->where('pivot.attivo', true)
                                ->where('pivot.tipologia', $ruoloFallback);

                            if ($anagrafiche->isNotEmpty()) {
                                Log::debug("distribuisciSuTabelle: ruolo '{$rip->soggetto}' assente su immobile "
                                    . "ID={$immobile->id}, risolto a cascata su '{$ruoloFallback}'.");
                                break;
                            }
                        }
                    }

                    // Tracciamento e bucket dello scoperto se cascata esaurita
                    if ($anagrafiche->isEmpty()) {
                        $pesiScoperti[] = [
                            'immobile_id'     => $immobile->id,
                            'tabella_id'      => $tabella->id,
                            'ruolo_richiesto' => $rip->soggetto,
                            'peso'            => $weightRip,
                        ];
                        Log::warning("distribuisciSuTabelle: cascata esaurita — nessun soggetto "
                            . "per ruolo '{$rip->soggetto}' su immobile ID={$immobile->id}. "
                            . "Peso {$weightRip} tracciato come scoperto.", [
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

        // Pesi finali vuoti: nessuna quota sarà generata per questo conto (se neanche pesiScoperti è popolato)
        if (empty($weights) && empty($pesiScoperti)) {
            Log::warning("distribuisciSuTabelle: nessun peso calcolato per conto ID={$conto->id} ('{$conto->nome}'). Importo {$importoConto} centesimi NON distribuito. Causa probabile: tabelle millesimali vuote o anagrafiche mancanti.", [
                'conto_id'    => $conto->id,
                'conto_nome'  => $conto->nome,
                'importo'     => $importoConto,
            ]);
            return;
        }

        $pesoSoggetti = array_sum($weights);
        $pesoScopertoTotale = array_sum(array_column($pesiScoperti, 'peso'));
        
        $pesoTotaleInclScoperto = $pesoSoggetti + $pesoScopertoTotale;
        
        if ($pesoTotaleInclScoperto <= 0.0) return;

        // Tracciatura degli importi scoperti
        if (!empty($pesiScoperti)) {
            foreach ($pesiScoperti as $ps) {
                $importoScoperto = (int) round(abs($importoConto) * ($ps['peso'] / $pesoTotaleInclScoperto));
                if ($importoScoperto > 0) {
                    $this->scopertiAccumulati[] = [
                        'immobile_id'     => $ps['immobile_id'],
                        'conto_id'        => $conto->id,
                        'tabella_id'      => $ps['tabella_id'],
                        'ruolo_richiesto' => $ps['ruolo_richiesto'],
                        'importo'         => $importoScoperto,
                    ];
                }
            }
        }

        if (empty($weights)) return; // Se tutto è scoperto, non procediamo con la distribuzione penny-perfect

        // Peso normalizzato solo sui soggetti reali; l'importo da distribuire è già al netto dello scoperto,
        // garantendo che la somma dei pesi dia un risultato congruo con l'importo decurtato.
        
        $importoDaDistribuirePennyPerfect = abs($importoConto);
        if ($pesoScopertoTotale > 0.0) {
            $totaleScopertoInt = (int) round(abs($importoConto) * ($pesoScopertoTotale / $pesoTotaleInclScoperto));
            $importoDaDistribuirePennyPerfect = abs($importoConto) - $totaleScopertoInt;
        }

        $importoContoSegno = $importoConto < 0 ? -$importoDaDistribuirePennyPerfect : $importoDaDistribuirePennyPerfect;

        foreach ($weights as $key => $w) {
            $weights[$key] = $w / $pesoSoggetti; // Qui normalizziamo a 1 per il penny-perfect sull'importo decurtato
        }

        $importiDistributi = $this->distribuisciImporto($weights, $importoContoSegno);

        foreach ($importiDistributi as $key => $importoCentesimi) {
            [$aid, $iid] = array_map('intval', explode('|', $key));

            if (!isset($totali[$aid])) $totali[$aid] = [];
            if (!isset($totali[$aid][$iid])) $totali[$aid][$iid] = 0;

            $totali[$aid][$iid] += $importoCentesimi;
        }
    }

    /**
     * Distribuisce un importo totale basandosi su un array di pesi normalizzati,
     * garantendo una ripartizione "penny-perfect" senza perdita o creazione di centesimi.
     *
     * @param array $weights Array di pesi normalizzati (somma = 1)
     * @param int $importoTotale Importo totale da distribuire in centesimi
     * @return array Importi penny-perfect calcolati
     */
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
