<?php

namespace App\Services;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Saldo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PianoRateGenerator
{
    protected CalcoloQuoteService $calcolatore;

    public function __construct(CalcoloQuoteService $calcolatore)
    {
        $this->calcolatore = $calcolatore;
    }

    /**
     * Genera il piano rate per la gestione.
     * - Calcolo quote per gestione
     * - Saldi applicati solo per gestione ORDINARIA
     * - Saldi applicati solo alla prima rata e per immobile
     */
    public function genera(PianoRate $pianoRate): array
    {
        Log::info("=== GENERAZIONE PIANO RATE INIZIATA ===", [
            'piano_rate_id' => $pianoRate->id,
            'nome' => $pianoRate->nome,
            'condominio_id' => $pianoRate->condominio_id
        ]);

        $gestione = $pianoRate->gestione;
        if (!$gestione || !$gestione->pianoConto) {
            throw new \RuntimeException("Gestione incompleta o mancante.");
        }

        // 🔍 Ricava l'esercizio associato alla gestione (pivot esercizio_gestione)
        $esercizio = $gestione->esercizi()->wherePivot('attiva', true)->first()
            ?? $gestione->esercizi()->first();

        if (!$esercizio) {
            Log::error("Esercizio non trovato per la gestione", [
                'gestione_id' => $gestione->id,
                'pivot_count' => $gestione->esercizi()->count()
            ]);
            throw new \RuntimeException("Esercizio non trovato per la gestione.");
        }

        // 1️⃣ Calcolo quote per gestione
        $totaliPerImmobile = $this->calcolatore->calcolaPerGestione($gestione);

        // 2️⃣ Recupera saldi (solo gestione ordinaria)
        $saldi = $this->recuperaSaldi($pianoRate, $gestione, $esercizio);

        // 3️⃣ Genera le date rate
        $dateRate = $this->generaDateRate($pianoRate, $gestione);
        Log::info('DATE RATE', array_map(fn($d) => $d->format('Y-m-d'), $dateRate));

        // 4️⃣ Crea rate e quote
        $statisticheRate = $this->generaRateEQuote($pianoRate, $totaliPerImmobile, $dateRate, $saldi);

        // 5️⃣ Statistiche finali
        return array_merge([
            'piano_rate_id' => $pianoRate->id,
            'rate_create' => count($dateRate),
            'importo_totale_generato' => array_sum(array_map('array_sum', $totaliPerImmobile))
        ], $statisticheRate);
    }

    /**
     * Recupera i saldi iniziali:
     * - Solo per la gestione ORDINARIA
     * - Mappati per anagrafica_id e immobile_id
     */
    protected function recuperaSaldi(PianoRate $pianoRate, $gestione, $esercizio): array
    {
        $gestioneOrdinaria = $gestione->tipo === 'ordinaria'
            ? $gestione
            : $esercizio->gestioni()->where('tipo', 'ordinaria')->first();

        if (!$gestioneOrdinaria) {
            Log::warning("Nessuna gestione ordinaria trovata per i saldi", [
                'esercizio_id' => $esercizio->id,
                'gestione_id' => $gestione->id,
                'tipo_gestione' => $gestione->tipo
            ]);
            return [];
        }

        $saldi = Saldo::where('condominio_id', $pianoRate->condominio_id)
            ->where('esercizio_id', $esercizio->id)
            ->where('origine', 'manuale')
            ->select('anagrafica_id', 'immobile_id', 'saldo_iniziale')
            ->get();

        $result = [];
        foreach ($saldi as $s) {
            $aid = (int)$s->anagrafica_id;
            $iid = (int)$s->immobile_id;
            $result[$aid][$iid] = (int)$s->saldo_iniziale;
        }

        Log::info("SALDI RECUPERATI", $result);
        return $result;
    }

    /**
     * Genera rate e quote, applicando i saldi solo alla prima rata per immobile
     */
    protected function generaRateEQuote(PianoRate $pianoRate, array $totaliPerImmobile, array $dateRate, array $saldi = []): array
    {
        $numeroRate = count($dateRate);
        $rateCreate = 0;
        $quoteCreate = 0;
        $importoTotaleGenerato = 0;

        foreach ($dateRate as $index => $dataScadenza) {
            $numeroRata = $index + 1;
            $rata = Rata::create([
                'piano_rate_id' => $pianoRate->id,
                'numero_rata' => $numeroRata,
                'data_scadenza' => $dataScadenza,
                'data_emissione' => now(),
                'descrizione' => "Rata n.{$numeroRata} - {$pianoRate->nome}",
                'importo_totale' => 0,
                'stato' => 'bozza'
            ]);

            $importoRataTotale = 0;

            foreach ($totaliPerImmobile as $aid => $immobili) {
                foreach ($immobili as $iid => $totaleImmobile) {
                    // se zero, non rateizzare
                    if ($totaleImmobile == 0) {
                        continue;
                    }

                    // ripartizione su valore assoluto
                    $totaleAssoluto = abs($totaleImmobile);
                    $base = intdiv($totaleAssoluto, $numeroRate);
                    $resto = $totaleAssoluto % $numeroRate;
                    $importoRata = ($numeroRata <= $resto) ? ($base + 1) : $base;

                    // ripristina segno originale (spesa = negativo)
                    $importoRata = ($totaleImmobile < 0) ? -$importoRata : $importoRata;

                    // applica saldo solo nella prima rata e per immobile
                    $saldoDaApplicare = $saldi[$aid][$iid] ?? 0;
                    if ($numeroRata === 1 && $saldoDaApplicare != 0) {
                        $importoRata -= $saldoDaApplicare;
                        Log::info("APPLICO SALDO", [
                            'aid' => $aid,
                            'iid' => $iid,
                            'saldo' => $saldoDaApplicare,
                            'rata' => $numeroRata
                        ]);
                    }

                    // ⚠️ Non scartare rate negative
                    RataQuote::create([
                        'rata_id'        => $rata->id,
                        'anagrafica_id'  => $aid,
                        'immobile_id'    => $iid,
                        'importo'        => $importoRata,
                        'importo_pagato' => 0,
                        'stato'          => 'da_pagare',
                        'data_scadenza'  => $dataScadenza,
                    ]);

                    $importoRataTotale += $importoRata;
                    $quoteCreate++;
                }
            }

            $rata->update(['importo_totale' => $importoRataTotale]);
            Log::info("RATA CREATA", [
                'rata_id' => $rata->id,
                'numero' => $numeroRata,
                'scadenza' => $dataScadenza->format('Y-m-d'),
                'totale_rata' => $importoRataTotale,
                'quote_create_fino_ad_ora' => $quoteCreate,
            ]);

            $importoTotaleGenerato += $importoRataTotale;
            $rateCreate++;
        }

        return [
            'rate_create' => $rateCreate,
            'quote_create' => $quoteCreate,
            'importo_totale_rate' => $importoTotaleGenerato
        ];
    }

    /**
     * Genera le date delle rate (mensili)
     */
    protected function generaDateRate(PianoRate $pianoRate, $gestione): array
    {
        $start = Carbon::parse($gestione->data_inizio);
        $giorno = $pianoRate->giorno_scadenza ?? 5;

        return collect(range(0, $pianoRate->numero_rate - 1))
            ->map(fn($i) => $start->copy()
                ->addMonths($i)
                ->setDay(min($giorno, $start->copy()->addMonths($i)->daysInMonth))
            )
            ->toArray();
    }
}
