<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote; // Importante per la query
use App\Helpers\MoneyHelper;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Illuminate\Http\Request;

class EstrattoContoAnagraficaController extends Controller
{
    use HasEsercizio;

    public function show(Request $request, Condominio $condominio, Anagrafica $anagrafica)
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        $anagrafica->load(['immobili' => function($q) use ($condominio) {
            $q->where('condominio_id', $condominio->id);
        }]);

        // Saldo Iniziale
        $saldoInizialeCents = $anagrafica->saldi()
            ->where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->sum('saldo_iniziale'); 

        // Movimenti
        $movimenti = $anagrafica->movimenti()
            ->whereHas('scrittura', function($q) use ($condominio) {
                $q->where('condominio_id', $condominio->id);
            })
            ->whereNull('cassa_id')
            ->with(['scrittura.gestione', 'rata', 'immobile']) 
            ->orderBy('created_at', 'asc') 
            ->orderBy('id', 'asc')
            ->get();

        $saldoProgressivo = $saldoInizialeCents;
        
        $timeline = $movimenti->map(function ($riga) use (&$saldoProgressivo, $anagrafica) {
            $importo = $riga->importo;
            
            // Calcolo DARE/AVERE
            if ($riga->tipo_riga === 'dare') {
                $saldoProgressivo += $importo;
                $dare = $importo; $avere = 0;
            } else {
                $saldoProgressivo -= $importo;
                $dare = 0; $avere = $importo;
            }

            // Icone
            $tipoMovimento = $riga->scrittura->tipo_movimento ?? 'generico';
            $icona = 'file'; 
            if ($tipoMovimento === 'emissione_rata') $icona = 'bill';
            if ($tipoMovimento === 'incasso_rata') $icona = 'payment';
            if ($tipoMovimento === 'saldo_iniziale') $icona = 'landmark';

            // --- DETTAGLI STRUTTURATI ---
            $dettagli = [];

            // 1. Rata specifica (con STATO)
            if ($riga->rata) {
                // Cerchiamo lo stato della quota di QUESTA anagrafica per QUESTA rata
                $statoRata = null;
                $quota = RataQuote::where('rata_id', $riga->rata->id)
                            ->where('anagrafica_id', $anagrafica->id)
                            ->select('stato')
                            ->first();
                
                if ($quota) {
                    $statoRata = $quota->stato; // 'pagata', 'parzialmente_pagata', 'da_pagare'
                }

                // FIX: numero_rata invece di numero
                $label = "Rata n.{$riga->rata->numero_rata}" . ($riga->rata->data_scadenza ? " (Scad. " . $riga->rata->data_scadenza->format('d/m/Y') . ")" : "");
                
                $dettagli[] = [
                    'type'   => 'rata',
                    'text'   => $label,
                    'status' => $statoRata // Passiamo lo stato al frontend
                ];
            }

            // 2. Immobile specifico
            if ($riga->immobile) {
                $label = $riga->immobile->nome . ($riga->immobile->interno ? " (Int. {$riga->immobile->interno})" : "");
                $dettagli[] = [
                    'type' => 'immobile',
                    'text' => $label,
                    'status' => null
                ];
            }

            return [
                'id'          => $riga->id,
                'data'        => $riga->scrittura->data_registrazione ? $riga->scrittura->data_registrazione->format('d/m/Y') : '-',
                'protocollo'  => $riga->scrittura->numero_protocollo,
                'descrizione' => $riga->scrittura->causale ?: 'Movimento Contabile',
                'gestione'    => $riga->scrittura->gestione ? $riga->scrittura->gestione->nome : null,
                'dettagli'    => $dettagli, 
                'note'        => $riga->note, 
                'tipo_icona'  => $icona, 
                'dare'        => $dare, 
                'avere'       => $avere,
                'saldo'       => $saldoProgressivo 
            ];
        });

        // Stats (invariato)
        $stats = [
            'totale_addebiti'   => MoneyHelper::format($timeline->sum('dare')),
            'totale_versamenti' => MoneyHelper::format($timeline->sum('avere')),
            'saldo_finale'      => MoneyHelper::format($saldoProgressivo),
            'saldo_raw'         => $saldoProgressivo,
            'saldo_iniziale'    => MoneyHelper::format($saldoInizialeCents),
            'saldo_iniziale_raw'=> $saldoInizialeCents
        ];

        return Inertia::render('gestionale/pianiRate/EstrattoContoAnagrafica', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'anagrafica' => $anagrafica,
            'timeline'   => $timeline,
            'stats'      => $stats
        ]);
    }
}