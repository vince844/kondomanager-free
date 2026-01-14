<?php

namespace App\Services;

use App\Models\Gestione;
use App\Models\Gestionale\PianoRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Servizio per il calcolo delle quote di spesa/entrata per ogni gestione.
 * VERSION: 1.8.1 (FIXED)
 */
class CalcoloQuoteService
{
    private ?Gestione $gestioneCorrente = null;

    /**
     * @param Gestione $gestione
     * @param PianoRate|null $pianoRate
     * @return array<int, array<int, int>>
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
            // Assicurati che la relazione sia caricata o la carichiamo ora
            $capitoliIds = $pianoRate->capitoli()->pluck('conto_id')->toArray();
        }

        // 2. QUERY CONTI
        $query = $pianoConto->conti()
            ->with([
                'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'tabelleMillesimali.ripartizioni',
                'sottoconti.sottoconti', 
            ]);

        // 🔥 FIX LOGICO QUI SOTTO 🔥
        if (!empty($capitoliIds)) {
            // CASO A: Filtro Attivo (Piano Rate parziale)
            // Cerchiamo ESATTAMENTE i conti selezionati, che siano radici o figli.
            // NON mettiamo whereNull('parent_id') altrimenti i figli vengono esclusi.
            $query->whereIn('id', $capitoliIds);
        } else {
            // CASO B: Nessun Filtro (Piano Rate completo)
            // Comportamento standard: prendiamo le Radici e scendiamo ricorsivamente.
            $query->whereNull('parent_id');
        }

        $conti = $query->get();

        Log::info("=== INIZIO CALCOLO QUOTE (FIXED) ===", [
            'gestione_id' => $gestione->id,
            'piano_rate_id' => $pianoRate?->id,
            'capitoli_filtrati' => count($capitoliIds),
            'conti_trovati' => $conti->count() // Se questo è > 0, il fix funziona
        ]);

        $this->processaConti($conti, $totali);

        $totaleCentesimi = array_sum(array_map('array_sum', $totali));
        
        // Log di verifica finale
        Log::info("Quote generate", ['totale_centesimi' => $totaleCentesimi]);

        return $totali;
    }

    /**
     * @param Collection<int, object> $conti
     * @param array<int, array<int, int>> $totali
     */
    private function processaConti(Collection $conti, array &$totali): void
    {
        foreach ($conti as $conto) {
            $importoLordo = (int) $conto->importo;
            
            // --- INIZIO LOGICA DI CALCOLO ---
            // Se l'importo è 0, controlliamo comunque i sottoconti se ci sono,
            // perché potrebbero avere importi loro.
            if ($importoLordo !== 0) {
                $tipo = $conto->tipo ?? 'spesa';
                $importoConto = in_array($tipo, ['spesa', 'uscita'])
                    ? abs($importoLordo)
                    : -abs($importoLordo);

                $this->distribuisciSuTabelle($conto, $importoConto, $totali);
            }

            // Ricorsione sui figli
            // Nota: Se abbiamo selezionato un figlio direttamente nel filtro iniziale,
            // $conto->sottoconti sarà vuoto (o non caricato) e la ricorsione si ferma giustamente qui.
            // Se abbiamo selezionato una radice, scenderà nei figli.
            if ($conto->sottoconti && $conto->sottoconti->count() > 0) {
                $this->processaConti($conto->sottoconti, $totali);
            }
        }
    }

    /**
     * Logica estratta per pulizia, identica alla tua v1.8
     */
    private function distribuisciSuTabelle($conto, $importoConto, array &$totali)
    {
        $tipo = $conto->tipo ?? 'spesa';
        $weights = [];

        foreach ($conto->tabelleMillesimali as $ctm) {
            $tabella = $ctm->tabella ?? null;
            if (!$tabella) continue;

            $coeff = (float) $ctm->coefficiente;
            if ($coeff <= 0) continue;

            $weightCoeff = $coeff / 100.0;
            $quote = $tabella->quote;
            if ($quote->isEmpty()) continue;

            $sommaValori = (float) $quote->sum('valore');
            if ($sommaValori <= 0.0) continue;

            foreach ($quote as $quota) {
                $immobile = $quota->immobile ?? null;
                if (!$immobile) continue;

                $valore = (float) $quota->valore;
                if ($valore <= 0.0) continue;

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

                    if ($anagrafiche->isEmpty() && in_array($rip->soggetto, ['inquilino', 'usufruttuario'])) {
                        $anagrafiche = $immobile->anagrafiche
                            ->where('pivot.attivo', true)
                            ->where('pivot.tipologia', 'proprietario');
                    }

                    if ($anagrafiche->isEmpty()) continue;

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

        if (empty($weights)) return;

        $pesoTotale = array_sum($weights);
        if ($pesoTotale <= 0.0) return;

        // Normalizzazione pesi
        foreach ($weights as $key => $w) {
            $weights[$key] = $w / $pesoTotale;
        }

        $importiDistributi = $this->distribuisciImporto($weights, $importoConto);

        foreach ($importiDistributi as $key => $importoCentesimi) {
            [$aid, $iid] = array_map('intval', explode('|', $key));
            $totali[$aid][$iid] = ($totali[$aid][$iid] ?? 0) + $importoCentesimi;
        }
    }

    private function distribuisciImporto(array $weights, int $importoTotale): array
    {
        // ... (Il tuo codice di distribuzione esistente è corretto) ...
        // Lo copio per completezza
        $result = [];
        if ($importoTotale === 0) {
            foreach ($weights as $key => $_) { $result[$key] = 0; }
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
            $bases[$key] = $base;
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