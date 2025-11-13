<?php

namespace App\Services;

use App\Models\Gestione;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Servizio per il calcolo delle quote di spesa/entrata per ogni gestione.
 *
 * Logica aggiornata:
 * - Somma finale delle quote = importo totale della gestione (centesimi) SENZA differenze
 * - Distribuzione tramite pesi (coefficiente, millesimi, ripartizioni, quote anagrafiche)
 * - Un SOLO passaggio di arrotondamento per conto (no round stratificati)
 * - Output finale: [anagrafica_id][immobile_id] => importo_centesimi (int)
 */
class CalcoloQuoteService
{
    private ?Gestione $gestioneCorrente = null;

    /**
     * Calcola le quote per una gestione.
     *
     * @param  Gestione  $gestione
     * @return array [anagrafica_id => [immobile_id => importo_centesimi]]
     */
    public function calcolaPerGestione(Gestione $gestione): array
    {
        $this->gestioneCorrente = $gestione;
        $totali = [];

        $pianoConto = $gestione->pianoConto;
        if (!$pianoConto) {
            Log::warning("Nessun piano conti trovato per la gestione", [
                'gestione_id' => $gestione->id,
            ]);
            return [];
        }

        Log::info("=== INIZIO CALCOLO QUOTE ===", [
            'gestione_id'   => $gestione->id,
            'tipo_gestione' => $gestione->tipo,
        ]);

        $conti = $pianoConto->conti()
            ->with([
                'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'tabelleMillesimali.ripartizioni',
                'sottoconti.sottoconti',
            ])
            ->get();

        $this->processaConti($conti, $totali);

        $totaleCentesimi = array_sum(array_map('array_sum', $totali));

        Log::info("=== FINE CALCOLO QUOTE ===", [
            'gestione_id'             => $gestione->id,
            'importo_totale_centesimi'=> $totaleCentesimi,
            'importo_totale_euro'     => number_format($totaleCentesimi / 100, 2, ',', '.'),
        ]);

        return $totali;
    }

    /**
     * Itera ricorsivamente i conti e sottoconti,
     * distribuendo le quote per anagrafica e immobile tramite pesi.
     */
    private function processaConti(Collection $conti, array &$totali): void
    {
        foreach ($conti as $conto) {
            $importoLordo = (int) $conto->importo;

            if ($importoLordo === 0) {
                continue;
            }

            // Tipo: gestiamo sia "spesa"/"uscita" che "entrata"
            $tipo = $conto->tipo ?? 'spesa';

            // Spese / Uscite => importo positivo (debito)
            // Entrate / Fondi / Crediti => importo negativo (riduzione del debito)
            if (in_array($tipo, ['spesa', 'uscita'])) {
                $importoConto = abs($importoLordo);
            } else {
                $importoConto = -abs($importoLordo);
            }

            // Se per qualche motivo importoConto è 0, saltiamo
            if ($importoConto === 0) {
                continue;
            }

            // Matrice pesi per questo conto: "anagrafica_id|immobile_id" => peso (float)
            $weights = [];

            foreach ($conto->tabelleMillesimali as $ctm) {
                $tabella = $ctm->tabella;
                if (!$tabella) {
                    continue;
                }

                $coeff = (float) $ctm->coefficiente; // es. "100.00"
                if ($coeff <= 0) {
                    continue;
                }
                // Peso del coefficiente (es. 100% = 1.0)
                $weightCoeff = $coeff / 100.0;

                $quote = $tabella->quote;
                if ($quote->isEmpty()) {
                    continue;
                }

                $sommaValori = (float) $quote->sum('valore');
                if ($sommaValori <= 0.0) {
                    continue;
                }

                // Per ogni quota (immobile)
                foreach ($quote as $quota) {
                    $immobile = $quota->immobile;
                    if (!$immobile) {
                        continue;
                    }

                    $valore = (float) $quota->valore;
                    if ($valore <= 0.0) {
                        continue;
                    }

                    // Peso dell'immobile rispetto alla tabella
                    $weightImmobile = $weightCoeff * ($valore / $sommaValori);

                    // Ripartizioni (proprietario / inquilino / usufruttuario)
                    $ripartizioni = $ctm->ripartizioni->isNotEmpty()
                        ? $ctm->ripartizioni
                        : collect([(object) ['soggetto' => 'proprietario', 'percentuale' => 100]]);

                    foreach ($ripartizioni as $rip) {
                        $percent = (float) $rip->percentuale;
                        if ($percent <= 0.0) {
                            continue;
                        }

                        $weightRip = $weightImmobile * ($percent / 100.0);

                        // Anagrafiche per tipologia
                        $anagrafiche = $immobile->anagrafiche
                            ->where('pivot.attivo', true)
                            ->where('pivot.tipologia', $rip->soggetto);

                        // Fallback inquilino / usufruttuario → proprietari
                        if ($anagrafiche->isEmpty() && in_array($rip->soggetto, ['inquilino', 'usufruttuario'])) {
                            $anagrafiche = $immobile->anagrafiche
                                ->where('pivot.attivo', true)
                                ->where('pivot.tipologia', 'proprietario');
                        }

                        if ($anagrafiche->isEmpty()) {
                            continue;
                        }

                        $sommaQuote = (float) $anagrafiche->sum('pivot.quota');
                        if ($sommaQuote <= 0.0) {
                            $sommaQuote = 1.0;
                        }

                        foreach ($anagrafiche as $anag) {
                            $quotaAnag = (float) $anag->pivot->quota;
                            if ($quotaAnag <= 0.0) {
                                continue;
                            }

                            // Peso finale per (anagrafica, immobile)
                            $weightAnagrafica = $weightRip * ($quotaAnag / $sommaQuote);

                            $aid = $anag->id;
                            $iid = $immobile->id;
                            $key = $aid . '|' . $iid;

                            $weights[$key] = ($weights[$key] ?? 0.0) + $weightAnagrafica;
                        }
                    }
                }
            }

            if (empty($weights)) {
                continue;
            }

            // Normalizziamo i pesi a 1.0
            $pesoTotale = array_sum($weights);
            if ($pesoTotale <= 0.0) {
                continue;
            }

            foreach ($weights as $key => $w) {
                $weights[$key] = $w / $pesoTotale;
            }

            // Distribuzione esatta dell'importo del conto sui pesi (in centesimi)
            $importiDistributi = $this->distribuisciImporto($weights, $importoConto);

            foreach ($importiDistributi as $key => $importoCentesimi) {
                [$aid, $iid] = array_map('intval', explode('|', $key));

                $totali[$aid][$iid] = ($totali[$aid][$iid] ?? 0) + $importoCentesimi;

                Log::debug('Quota aggiunta', [
                    'conto_id'          => $conto->id,
                    'conto_nome'        => $conto->nome ?? null,
                    'anagrafica_id'     => $aid,
                    'immobile_id'       => $iid,
                    'importo_centesimi' => $importoCentesimi,
                    'tipo_conto'        => $tipo,
                ]);
            }

            // Ricorsione su eventuali sottoconti
            if ($conto->sottoconti && $conto->sottoconti->count() > 0) {
                $this->processaConti($conto->sottoconti, $totali);
            }
        }
    }

    /**
     * Converte una matrice di pesi in importi in centesimi
     * in modo che la somma degli importi sia ESATTAMENTE $importoTotale.
     *
     * @param  array  $weights  [key => peso_normalizzato]
     * @param  int    $importoTotale  importo in centesimi (può essere negativo)
     * @return array [key => importo_centesimi]
     */
    private function distribuisciImporto(array $weights, int $importoTotale): array
    {
        $result = [];

        if ($importoTotale === 0) {
            foreach ($weights as $key => $_) {
                $result[$key] = 0;
            }
            return $result;
        }

        $sign   = $importoTotale < 0 ? -1 : 1;
        $totAbs = abs($importoTotale);

        $bases      = [];
        $remainders = [];
        $sumBase    = 0;

        // 1) Calcolo base (floor) e resto decimale
        foreach ($weights as $key => $w) {
            $raw  = $totAbs * $w;           // es: 52300 * 0.25 = 13075.0
            $base = (int) floor($raw);      // parte intera in centesimi
            $rem  = $raw - $base;           // resto < 1

            $bases[$key]      = $base;
            $remainders[$key] = $rem;
            $sumBase         += $base;
        }

        // 2) Differenza da distribuire (in centesimi)
        $diff = $totAbs - $sumBase; // 0 <= diff < count($weights)

        if ($diff > 0) {
            // Ordiniamo i resti dal più grande al più piccolo
            arsort($remainders);
            $keys = array_keys($remainders);

            // Aggiungiamo +1 cent ai primi $diff elementi
            $countKeys = count($keys);
            for ($i = 0; $i < $diff && $i < $countKeys; $i++) {
                $bases[$keys[$i]]++;
            }
        }

        // 3) Applichiamo il segno
        foreach ($bases as $key => $b) {
            $result[$key] = $b * $sign;
        }

        return $result;
    }
}
