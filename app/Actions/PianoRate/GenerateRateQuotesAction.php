<?php

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;

class GenerateRateQuotesAction
{
    /**
     * Generate Rata and RataQuote records.
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

            $importoTotaleRata = 0;

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

                    RataQuote::create([
                        'rata_id'        => $rata->id,
                        'anagrafica_id'  => $aid,
                        'immobile_id'    => $iid,
                        'importo'        => $amount,
                        'importo_pagato' => 0,
                        'stato'          => $statoQuota,
                        'data_scadenza'  => $dataScadenza,
                    ]);

                    $importoTotaleRata += $amount;
                    $quoteCreate++;
                }
            }

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
     * Core calculation logic for a single rata.
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

        $base = intdiv($absTot, $numeroRate);
        $resto = $absTot % $numeroRate;

        $importo = $base + ($numeroRata <= $resto ? 1 : 0);
        $importo *= $segno;

        if ($saldo !== 0) {
            if ($metodoDistribuzione === 'prima_rata' && $numeroRata === 1) {
                $importo -= $saldo;
            }

            if ($metodoDistribuzione === 'tutte_rate') {
                $segnoSaldo = $saldo < 0 ? -1 : 1;
                $absSaldo   = abs($saldo);

                $baseSaldo = intdiv($absSaldo, $numeroRate);
                $restoSaldo = $absSaldo % $numeroRate;

                $quotaSaldo = $baseSaldo + ($numeroRata <= $restoSaldo ? 1 : 0);
                $quotaSaldo *= $segnoSaldo;

                $importo -= $quotaSaldo;
            }
        }

        return $importo;
    }
}
