<?php

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use Illuminate\Support\Carbon;

class GenerateRateQuotesAction
{
    /**
     * Genera Rate (Testate) e RataQuote (Dettagli) ottimizzando le performance.
     *
     * @return array{rate_create:int, quote_create:int, importo_totale_rate:int}
     */
    public function execute(
        PianoRate $pianoRate,
        array $totaliPerImmobile,
        array $dateRate,
        array $saldi = []
    ): array {
        $numeroRate = count($dateRate);
        $rateCreate = 0;
        $quoteCreate = 0;
        $importoTotaleGenerato = 0;
        
        // Timestamp unico per tutte le righe (ottimizzazione)
        $now = now(); 

        foreach ($dateRate as $index => $dataScadenza) {
            $numeroRata = $index + 1;

            // 1. Creiamo la Rata (Testata) - Una per scadenza
            $rata = Rata::create([
                'piano_rate_id'  => $pianoRate->id,
                'numero_rata'    => $numeroRata,
                'data_scadenza'  => $dataScadenza,
                'data_emissione' => $now,
                'descrizione'    => "Rata n.{$numeroRata} - {$pianoRate->nome}",
                'importo_totale' => 0, // Lo aggiorneremo alla fine del ciclo
                'stato'          => 'bozza',
            ]);

            $importoTotaleRata = 0;
            $quotesToInsert = []; // Array per accumulare i dati e inserirli in un colpo solo

            // 2. Calcoliamo le quote per ogni immobile
            foreach ($totaliPerImmobile as $aid => $immobili) {
                foreach ($immobili as $iid => $totaleImmobile) {
                    if ($totaleImmobile == 0) continue;

                    $amount = $this->calcolaImportoRata(
                        $totaleImmobile,
                        $numeroRate,
                        $numeroRata,
                        $pianoRate->metodo_distribuzione,
                        $saldi[$aid][$iid] ?? 0
                    );

                    $statoQuota = $amount < 0 ? 'credito' : 'da_pagare';

                    // Invece di creare subito su DB, salviamo in array
                    $quotesToInsert[] = [
                        'rata_id'        => $rata->id,
                        'anagrafica_id'  => $aid,
                        'immobile_id'    => $iid,
                        'importo'        => $amount,
                        'importo_pagato' => 0,
                        'stato'          => $statoQuota,
                        'data_scadenza'  => $dataScadenza instanceof Carbon ? $dataScadenza->format('Y-m-d') : $dataScadenza,
                        'created_at'     => $now, // Necessario per insert massivo
                        'updated_at'     => $now, // Necessario per insert massivo
                    ];

                    $importoTotaleRata += $amount;
                    $quoteCreate++;
                }
            }

            // 3. Inserimento Massivo (Bulk Insert) per questa rata
            if (!empty($quotesToInsert)) {
                // Inseriamo in blocchi da 500 per sicurezza (se hai condomini enormi)
                foreach (array_chunk($quotesToInsert, 500) as $chunk) {
                    RataQuote::insert($chunk);
                }
            }

            // 4. Aggiorniamo il totale della rata header
            $rata->update(['importo_totale' => $importoTotaleRata]);

            $importoTotaleGenerato += $importoTotaleRata;
            $rateCreate++;
        }

        return [
            'rate_create' => $rateCreate,
            'quote_create' => $quoteCreate,
            'importo_totale_rate' => $importoTotaleGenerato,
        ];
    }

    /**
     * Logica di calcolo matematica
     */
    protected function calcolaImportoRata(
        int $totaleImmobile,
        int $numeroRate,
        int $numeroRata,
        string $metodoDistribuzione,
        int $saldo
    ): int {
        $segno = $totaleImmobile < 0 ? -1 : 1;
        $absTot = abs($totaleImmobile);

        // Divisione intera per non perdere centesimi
        $base = intdiv($absTot, $numeroRate);
        $resto = $absTot % $numeroRate;

        // Distribuzione del resto sulle prime rate
        $importo = $base + ($numeroRata <= $resto ? 1 : 0);
        $importo *= $segno;

        // Gestione Saldo Iniziale
        if ($saldo !== 0) {
            // Caso 1: Tutto sulla prima rata
            if ($metodoDistribuzione === 'prima_rata' && $numeroRata === 1) {
                $importo += $saldo; // Corretto: Aggiunge debito (+) o toglie credito (-)
            }

            // Caso 2: Spalmato su tutte le rate
            if ($metodoDistribuzione === 'tutte_rate') {
                $segnoSaldo = $saldo < 0 ? -1 : 1;
                $absSaldo   = abs($saldo);

                $baseSaldo = intdiv($absSaldo, $numeroRate);
                $restoSaldo = $absSaldo % $numeroRate;

                $quotaSaldo = $baseSaldo + ($numeroRata <= $restoSaldo ? 1 : 0);
                $quotaSaldo *= $segnoSaldo;

                $importo += $quotaSaldo;
            }
        }

        return $importo;
    }
}