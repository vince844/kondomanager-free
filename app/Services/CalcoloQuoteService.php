<?php

namespace App\Services;

use App\Models\Gestione;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Servizio per il calcolo delle quote di spesa/entrata per ogni gestione.
 * 
 * Logica aggiornata novembre 2025:
 * - Itera ogni conto e sottoconto
 * - Per ogni tabella millesimale associata, applica il coefficiente
 * - Divide l’importo tra immobili in base ai millesimi
 * - Divide la quota tra proprietari / inquilini / usufruttuari secondo ripartizione
 * - Se mancano inquilini o usufruttuari, attribuisce la quota ai proprietari
 * - Restituisce array [anagrafica_id][immobile_id] => importo_centesimi
 */
class CalcoloQuoteService
{
    private ?Gestione $gestioneCorrente = null;

    public function calcolaPerGestione(Gestione $gestione): array
    {
        $this->gestioneCorrente = $gestione;
        $totali = [];

        $pianoConto = $gestione->pianoConto;
        if (!$pianoConto) {
            Log::warning("Nessun piano conti trovato per la gestione", [
                'gestione_id' => $gestione->id
            ]);
            return [];
        }

        Log::info("=== INIZIO CALCOLO QUOTE ===", [
            'gestione_id' => $gestione->id,
            'tipo_gestione' => $gestione->tipo
        ]);

        $conti = $pianoConto->conti()
            ->with([
                'tabelleMillesimali.tabella.quote.immobile.anagrafiche',
                'tabelleMillesimali.ripartizioni',
                'sottoconti.sottoconti'
            ])
            ->get();

        $this->processaConti($conti, $totali);

        // Calcolo totale finale
        $totaleCentesimi = array_sum(array_map('array_sum', $totali));

        Log::info("=== FINE CALCOLO QUOTE ===", [
            'gestione_id' => $gestione->id,
            'importo_totale_centesimi' => $totaleCentesimi,
            'importo_totale_euro' => number_format($totaleCentesimi / 100, 2, ',', '.')
        ]);

        return $totali;
    }

    /**
     * Itera ricorsivamente conti e sottoconti e distribuisce le quote.
     */
    private function processaConti(Collection $conti, array &$totali): void
    {
        foreach ($conti as $conto) {
            if ($conto->importo <= 0) continue;

            // Tipo: 'uscita' (spesa, negativa) o 'entrata' (credito, positiva)
            // Si mantiene il segno coerente: spesa -> negativo, entrata -> positivo
            $importoConto = $conto->tipo === 'uscita'
                ? -abs($conto->importo)
                : abs($conto->importo);

            foreach ($conto->tabelleMillesimali as $ctm) {
                $tabella = $ctm->tabella;
                if (!$tabella) continue;

                // Importo allocato su questa tabella (applica coefficiente %)
                $importoTabella = (int) round($importoConto * ($ctm->coefficiente / 100));

                $quote = $tabella->quote;
                if ($quote->isEmpty()) continue;

                $sommaValori = $quote->sum('valore') ?: 1;

                // 🔁 Per ogni immobile nella tabella millesimale
                foreach ($quote as $quota) {
                    $immobile = $quota->immobile;
                    if (!$immobile) continue;

                    $importoImmobile = (int) round($importoTabella * ($quota->valore / $sommaValori));

                    // Ripartizioni (proprietario, inquilino, usufruttuario)
                    $ripartizioni = $ctm->ripartizioni->isNotEmpty()
                        ? $ctm->ripartizioni
                        : collect([(object)['soggetto' => 'proprietario', 'percentuale' => 100]]);

                    foreach ($ripartizioni as $rip) {
                        $importoRip = (int) round($importoImmobile * ($rip->percentuale / 100));

                        // Recupera anagrafiche attive per tipo soggetto
                        $anagrafiche = $immobile->anagrafiche
                            ->where('pivot.tipologia', $rip->soggetto)
                            ->where('pivot.attivo', true);

                        // Se mancano inquilini/usufruttuari → passa ai proprietari
                        if ($anagrafiche->isEmpty() && in_array($rip->soggetto, ['inquilino', 'usufruttuario'])) {
                            $anagrafiche = $immobile->anagrafiche
                                ->where('pivot.tipologia', 'proprietario')
                                ->where('pivot.attivo', true);
                        }

                        if ($anagrafiche->isEmpty()) continue;

                        $sommaQuote = $anagrafiche->sum('pivot.quota') ?: 1;

                        foreach ($anagrafiche as $anag) {
                            $importoAnagrafica = (int) round($importoRip * ($anag->pivot->quota / $sommaQuote));

                            $aid = $anag->id;
                            $iid = $immobile->id;

                            // Somma importo per anagrafica e immobile
                            $totali[$aid][$iid] = ($totali[$aid][$iid] ?? 0) + $importoAnagrafica;

                            Log::debug("Quota aggiunta", [
                                'conto_id' => $conto->id,
                                'conto_nome' => $conto->nome ?? null,
                                'anagrafica_id' => $aid,
                                'immobile_id' => $iid,
                                'importo_centesimi' => $importoAnagrafica,
                                'tipo_conto' => $conto->tipo,
                                'coefficiente' => $ctm->coefficiente,
                            ]);
                        }
                    }
                }
            }

            // Ricorsione su sottoconti
            if ($conto->sottoconti && $conto->sottoconti->count() > 0) {
                $this->processaConti($conto->sottoconti, $totali);
            }
        }
    }
}
