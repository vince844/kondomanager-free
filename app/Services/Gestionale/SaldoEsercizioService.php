<?php

namespace App\Services\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Helpers\MoneyHelper; 
use Illuminate\Support\Facades\DB;

class SaldoEsercizioService
{
    /**
     * V1.9: Calcola i saldi basandosi esclusivamente sulla GESTIONE.
     */
    public function calcolaSaldoApplicabile(Gestione $gestione): array 
    {
        // 1. Verifica Blocco sulla gestione specifica
        if ($gestione->saldo_applicato) {
            return [
                'saldo' => 0,
                'has_movimenti' => false,
                'applicabile' => false,
                'motivo' => "I saldi pregressi sono già stati integrati nel piano rate della gestione \"{$gestione->nome}\".",
            ];
        }
        
        $saldoInCentesimi = 0;
        $haMovimenti = false; 
        $motivo = "";
        $isPrimoAnno = false;

        // 2. Interroga direttamente la nuova tabella saldi (Wallet) isolando la gestione_id
        $queryManuale = DB::table('saldi')
            ->where('gestione_id', $gestione->id)
            ->where('is_applicato', false);

        $saldoInCentesimi = (int) $queryManuale->sum('saldo_iniziale');
        
        // Verifica se esistono righe con importi reali
        $haMovimenti = (clone $queryManuale)->where('saldo_iniziale', '!=', 0)->exists();
        
        if ($haMovimenti) {
            $motivo = "Saldi pregressi rilevati nel wallet per questa gestione.";
        } else {
            $motivo = "Nessun saldo pregresso da elaborare per questa gestione.";
            $isPrimoAnno = true;
        }

        return [
            'saldo' => $saldoInCentesimi,
            'has_movimenti' => $haMovimenti,
            'applicabile' => true, 
            'motivo' => $motivo,
            'is_primo_anno' => $isPrimoAnno
        ];
    }
    
    public function marcaSaldoApplicato(Gestione $gestione, int $saldoApplicato): void
    {
        $importoFormattato = ($saldoApplicato > 0 ? '+' : '') . MoneyHelper::format($saldoApplicato);

        // 1. LOCK MACRO: Aggiorna lo stato della Gestione
        $gestione->update([
            'saldo_applicato' => true,
            'nota_saldo' => sprintf(
                "Saldo Netto %s processato il %s",
                $importoFormattato,
                now()->format('d/m/Y H:i')
            )
        ]);

        // 2. LOCK MICRO (IL FIX): Chiude le singole righe nella tabella Saldi
        Saldo::where('gestione_id', $gestione->id)
             ->where('is_applicato', false)
             ->update(['is_applicato' => true]);
    }
}