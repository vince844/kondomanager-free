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
     *
     * - Calcolo quote per gestione (CalcoloQuoteService)
     * - Saldi applicati solo per gestione ordinaria
     * - Saldi applicati solo alla prima rata e per immobile/anagrafica
     * - Ripartizione precisa dei centesimi con intdiv + resto
     */
    public function genera(PianoRate $pianoRate): array
    {
        Log::info("=== GENERAZIONE PIANO RATE INIZIATA ===", [
            'piano_rate_id' => $pianoRate->id,
            'nome'          => $pianoRate->nome,
            'condominio_id' => $pianoRate->condominio_id,
        ]);

        $gestione = $pianoRate->gestione;
        if (!$gestione || !$gestione->pianoConto) {
            throw new \RuntimeException("Gestione incompleta o mancante.");
        }

        // Esercizio collegato alla gestione (pivot esercizio_gestione)
        $esercizio = $gestione->esercizi()->wherePivot('attiva', true)->first()
            ?? $gestione->esercizi()->first();

        if (!$esercizio) {
            Log::error("Esercizio non trovato per la gestione", [
                'gestione_id'  => $gestione->id,
                'pivot_count'  => $gestione->esercizi()->count(),
            ]);
            throw new \RuntimeException("Esercizio non trovato per la gestione.");
        }

        // Calcolo quote per gestione (ogni valore è in centesimi)
        $totaliPerImmobile = $this->calcolatore->calcolaPerGestione($gestione);

        // Recupera saldi (solo gestione ordinaria)
        $saldi = $this->recuperaSaldi($pianoRate, $gestione, $esercizio);

        // Genera le date delle rate
        $dateRate = $this->generaDateRate($pianoRate, $gestione);
        Log::info('DATE RATE', array_map(fn($d) => $d->format('Y-m-d'), $dateRate));

        // Crea rate e quote
        $statisticheRate = $this->generaRateEQuote($pianoRate, $totaliPerImmobile, $dateRate, $saldi);

        // Statistiche finali
        return array_merge([
            'piano_rate_id'           => $pianoRate->id,
            'rate_create'             => count($dateRate),
            'importo_totale_generato' => array_sum(array_map('array_sum', $totaliPerImmobile)),
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
                'esercizio_id'  => $esercizio->id,
                'gestione_id'   => $gestione->id,
                'tipo_gestione' => $gestione->tipo,
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
            $aid = (int) $s->anagrafica_id;
            $iid = (int) $s->immobile_id;
            // saldo_iniziale in centesimi
            // >0 = credito verso il condomino (riduce la rata)
            // <0 = debito (aumenta la rata)
            $result[$aid][$iid] = (int) $s->saldo_iniziale;
        }

        Log::info("SALDI RECUPERATI", $result);

        return $result;
    }

    /**
     * Genera rate e relative quote:
     * - Per ogni (anagrafica, immobile) divide il totale in N rate
     *   usando intdiv + resto → nessun centesimo perso
     * - Applica il saldo solo alla prima rata (se metodo_distribuzione = 'prima_rata')
     * - Distribuisce il saldo su tutte le rate (se metodo_distribuzione = 'tutte_rate')
     */
   protected function generaRateEQuote(
        PianoRate $pianoRate,
        array $totaliPerImmobile,
        array $dateRate,
        array $saldi = []
    ): array {
        $numeroRate = count($dateRate);
        $rateCreate = 0;
        $quoteCreate = 0;
        $importoTotaleGenerato = 0;

        foreach ($dateRate as $index => $dataScadenza) {
            $numeroRata = $index + 1;

            $rata = Rata::create([
                'piano_rate_id'  => $pianoRate->id,
                'numero_rata'    => $numeroRata,
                'data_scadenza'  => $dataScadenza,
                'data_emissione' => now(),
                'descrizione'    => "Rata n.{$numeroRata} - {$pianoRate->nome}",
                'importo_totale' => 0,
                'stato'          => 'bozza',
            ]);

            $importoRataTotale = 0;

            foreach ($totaliPerImmobile as $aid => $immobili) {
                foreach ($immobili as $iid => $totaleImmobile) {

                    $totaleImmobile = (int) $totaleImmobile;
                    if ($totaleImmobile === 0) continue;

                    // Suddivisione base delle spese in rate
                    $segno = $totaleImmobile < 0 ? -1 : 1;
                    $absTot = abs($totaleImmobile);

                    $base = intdiv($absTot, $numeroRate);
                    $resto = $absTot % $numeroRate;

                    $importoRata = $base;
                    if ($numeroRata <= $resto) {
                        $importoRata += 1;
                    }

                    $importoRata *= $segno;

                    // Gestione saldo
                    $saldoDaApplicare = $saldi[$aid][$iid] ?? 0;

                    if ($saldoDaApplicare !== 0) {

                        // Caso A → tutto in prima rata
                        if ($pianoRate->metodo_distribuzione === 'prima_rata') {
                            if ($numeroRata === 1) {
                                // saldo>0 = credito → diminuisce la rata
                                // saldo<0 = debito → aumenta la rata
                                $importoRata -= $saldoDaApplicare;
                            }
                        }

                        // Caso B → saldo distribuito su tutte le rate
                        elseif ($pianoRate->metodo_distribuzione === 'tutte_rate') {

                            $segnoSaldo = $saldoDaApplicare < 0 ? -1 : 1;
                            $absSaldo   = abs($saldoDaApplicare);

                            $baseSaldo = intdiv($absSaldo, $numeroRate);
                            $restoSaldo = $absSaldo % $numeroRate;

                            $quotaSaldo = $baseSaldo;
                            if ($numeroRata <= $restoSaldo) {
                                $quotaSaldo += 1;
                            }

                            $quotaSaldo *= $segnoSaldo;

                            // credito>0 → riduce la rata
                            // debito<0 → aumenta
                            $importoRata -= $quotaSaldo;
                        }
                    }

                    // Stato della quota
                    $statoQuota = $importoRata < 0 ? 'credito' : 'da_pagare';

                    // Creazione della quota
                    RataQuote::create([
                        'rata_id'        => $rata->id,
                        'anagrafica_id'  => $aid,
                        'immobile_id'    => $iid,
                        'importo'        => $importoRata,
                        'importo_pagato' => 0,
                        'stato'          => $statoQuota,
                        'data_scadenza'  => $dataScadenza,
                    ]);

                    $importoRataTotale += $importoRata;
                    $quoteCreate++;
                }
            }

            // Aggiorna il totale della rata
            $rata->update(['importo_totale' => $importoRataTotale]);

            $importoTotaleGenerato += $importoRataTotale;
            $rateCreate++;
        }

        return [
            'rate_create'         => $rateCreate,
            'quote_create'        => $quoteCreate,
            'importo_totale_rate' => $importoTotaleGenerato,
        ];
    }

    /**
     * Genera le date delle rate (mensili) a partire dalla data inizio gestione.
     */
    protected function generaDateRate(PianoRate $pianoRate, $gestione): array
    {
        $start  = Carbon::parse($gestione->data_inizio);
        $giorno = $pianoRate->giorno_scadenza ?? 5;

        return collect(range(0, $pianoRate->numero_rate - 1))
            ->map(fn ($i) => $start->copy()
                ->addMonths($i)
                ->setDay(min($giorno, $start->copy()->addMonths($i)->daysInMonth))
            )
            ->toArray();
    }
}
