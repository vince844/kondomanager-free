<?php

namespace App\Services;

use App\Models\Gestionale\PianoRate;
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
     * @param Gestione $gestione
     * @param PianoRate|null $pianoRate (Opzionale) Se passato, filtra per i capitoli del piano
     * @return array<int, array<int, int>
     * @since v1.8.0
     */
    public function calcolaPerGestione(Gestione $gestione, ?PianoRate $pianoRate = null): array
    {
        $this->gestioneCorrente = $gestione;
        $totali = [];
        $pianoConto = $gestione->pianoConto;

        if (!$pianoConto) {
            Log::warning("Nessun piano conti trovato per la gestione", ['gestione_id' => $gestione->id]);
            return [];
        }

        // 1. RECUPERO ID CAPITOLI FILTRATI (SE ESISTONO)
        $capitoliIds = [];
        if ($pianoRate) {
            $capitoliIds = $pianoRate->capitoli()->pluck('conto_id')->toArray();
        }

        // 2. QUERY CONTI
        $query = $pianoConto->conti()
            ->with([
                'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'tabelleMillesimali.ripartizioni',
                'sottoconti.sottoconti', // Carichiamo la gerarchia per sommare i figli
            ])
            ->whereNull('parent_id'); // Partiamo sempre dai ROOT (Capitoli)

        // IL FILTRO MAGICO
        // Se il piano rate ha dei capitoli specifici, prendiamo solo quelli.
        // Altrimenti (array vuoto), la query prende TUTTI i capitoli (comportamento standard).
        if (!empty($capitoliIds)) {
            $query->whereIn('id', $capitoliIds);
        }

        $conti = $query->get();

        Log::info("=== INIZIO CALCOLO QUOTE ===", [
            'gestione_id' => $gestione->id,
            'piano_rate_id' => $pianoRate?->id,
            'capitoli_filtrati' => count($capitoliIds),
            'conti_processati' => $conti->count()
        ]);

        $this->processaConti($conti, $totali);

        // ... resto del metodo identico (somme e log) ...
        
        return $totali;
    }

    /**
     * @param Collection<int, object> $conti
     * @param array<int, array<int, int>> $totali
     */
    private function processaConti(Collection $conti, array &$totali): void
    {
        /** @var object{importo:int,tipo:string,nome?:string,tabelleMillesimali:Collection,sottoconti?:Collection} $conto */
        foreach ($conti as $conto) {
            $importoLordo = (int) $conto->importo;
            if ($importoLordo === 0) {
                continue;
            }

            $tipo = $conto->tipo ?? 'spesa';
            $importoConto = in_array($tipo, ['spesa', 'uscita'])
                ? abs($importoLordo)
                : -abs($importoLordo);

            if ($importoConto === 0) {
                continue;
            }

            $weights = [];
            /** @var object{tabella?:object,coefficiente:string,ripartizioni:Collection} $ctm */
            foreach ($conto->tabelleMillesimali as $ctm) {
                $tabella = $ctm->tabella ?? null;
                if (!$tabella) continue;

                $coeff = (float) $ctm->coefficiente;
                if ($coeff <= 0) continue;

                $weightCoeff = $coeff / 100.0;

                /** @var Collection<int, object{valore:float,immobile?:object}> $quote */
                $quote = $tabella->quote;
                if ($quote->isEmpty()) continue;

                $sommaValori = (float) $quote->sum('valore');
                if ($sommaValori <= 0.0) continue;

                /** @var object{valore:float,immobile?:object} $quota */
                foreach ($quote as $quota) {
                    $immobile = $quota->immobile ?? null;
                    if (!$immobile) continue;

                    $valore = (float) $quota->valore;
                    if ($valore <= 0.0) continue;

                    $weightImmobile = $weightCoeff * ($valore / $sommaValori);

                    // === RIPARTIZIONI CON TIPO ESPLICITO ===
                    /** @var Collection<int, object{soggetto:string,percentuale:float}> $ripartizioni */
                    $ripartizioni = $ctm->ripartizioni->isNotEmpty()
                        ? $ctm->ripartizioni
                        : collect([ (object) [
                            'soggetto' => 'proprietario',
                            'percentuale' => 100.0
                        ]]);

                    /** @var object{soggetto:string,percentuale:float} $rip */
                    foreach ($ripartizioni as $rip) {
                        $percent = (float) $rip->percentuale;
                        if ($percent <= 0.0) continue;

                        $weightRip = $weightImmobile * ($percent / 100.0);

                        /** @var Collection<int, object{pivot:object{attivo:bool,tipologia:string,quota:float}}> $anagrafiche */
                        $anagrafiche = $immobile->anagrafiche
                            ->where('pivot.attivo', true)
                            ->where('pivot.tipologia', $rip->soggetto);

                        if ($anagrafiche->isEmpty() && in_array($rip->soggetto, ['inquilino', 'usufruttuario'])) {
                            $anagrafiche = $immobile->anagrafiche
                                ->where('pivot.attivo', true)
                                ->where('pivot.tipologia', 'proprietario');
                        }

                        if ($anagrafiche->isEmpty()) continue;

                        $sommaQuote = (float) $anagrafiche->sum('pivot.quota');
                        if ($sommaQuote <= 0.0) $sommaQuote = 1.0;

                        /** @var object{id:int,pivot:object{quota:float}} $anag */
                        foreach ($anagrafiche as $anag) {
                            $quotaAnag = (float) $anag->pivot->quota;
                            if ($quotaAnag <= 0.0) continue;

                            $weightAnagrafica = $weightRip * ($quotaAnag / $sommaQuote);
                            $aid = $anag->id;
                            $iid = $immobile->id;
                            $key = $aid . '|' . $iid;
                            $weights[$key] = ($weights[$key] ?? 0.0) + $weightAnagrafica;
                        }
                    }
                }
            }

            if (empty($weights)) continue;

            $pesoTotale = array_sum($weights);
            if ($pesoTotale <= 0.0) continue;

            foreach ($weights as $key => $w) {
                $weights[$key] = $w / $pesoTotale;
            }

            $importiDistributi = $this->distribuisciImporto($weights, $importoConto);

            foreach ($importiDistributi as $key => $importoCentesimi) {
                [$aid, $iid] = array_map('intval', explode('|', $key));
                $totali[$aid][$iid] = ($totali[$aid][$iid] ?? 0) + $importoCentesimi;

                Log::debug('Quota aggiunta', [
                    'conto_id' => $conto->id,
                    'conto_nome' => $conto->nome ?? null,
                    'anagrafica_id' => $aid,
                    'immobile_id' => $iid,
                    'importo_centesimi' => $importoCentesimi,
                    'tipo_conto' => $tipo,
                ]);
            }

            if ($conto->sottoconti && $conto->sottoconti->count() > 0) {
                $this->processaConti($conto->sottoconti, $totali);
            }
        }
    }

    /**
     * @param array<string, float> $weights
     * @param int $importoTotale
     * @return array<string, int>
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

        $sign = $importoTotale < 0 ? -1 : 1;
        $totAbs = abs($importoTotale);
        $bases = [];
        $remainders = [];
        $sumBase = 0;

        foreach ($weights as $key => $w) {
            $raw = $totAbs * $w;
            $base = (int) floor($raw);
            $rem = $raw - $base;
            $bases[$key] = $base;
            $remainders[$key] = $rem;
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