<?php

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Saldo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateSaldiAction
{
    /**
     * @return array Formato: [ anagrafica_id => [ immobile_id => [ 'importo' => X, 'meta' => [...] ] ] ]
     */
    public function execute(PianoRate $pianoRate, Gestione $gestione, array $saldiConfig = []): array
    {
        $saldiDaApplicare = Saldo::where('gestione_id', $gestione->id)
            ->where('is_applicato', false)
            ->where('saldo_iniziale', '!=', 0)
            ->get();

        if ($saldiDaApplicare->isEmpty()) {
            return [];
        }

        $distribuzione = [];
        $saldiConfigMap = collect($saldiConfig)->keyBy('saldo_id');

        foreach ($saldiDaApplicare as $saldo) {
            
            // CASO A: Saldo Nominale (Ha già un'anagrafica assegnata)
            if ($saldo->anagrafica_id !== null) {
                $this->assegnaQuota(
                    $distribuzione,
                    $saldo->anagrafica_id,
                    $saldo->immobile_id,
                    $saldo->saldo_iniziale,
                    $this->creaMeta($saldo, 'nominale')
                );
                continue;
            }

            // CASO B: Saldo Solidale (Art. 63) - anagrafica_id è NULL
            $configCustom = $saldiConfigMap->get($saldo->id);

            if ($configCustom) {
                // B1. Riparto Manuale (L'utente ha forzato le quote da Vue)
                foreach ($configCustom['ripartizioni'] as $rip) {
                    $this->assegnaQuota(
                        $distribuzione,
                        $rip['anagrafica_id'],
                        $saldo->immobile_id,
                        (int) ($rip['importo'] * 100), // Assumendo che il frontend mandi Euro, convertiamo in centesimi
                        $this->creaMeta($saldo, 'solidale_manuale')
                    );
                }
            } else {
                // B2. Riparto Automatico (Pro-Quota sui proprietari attuali)
                $proprietari = DB::table('anagrafica_immobile')
                    ->where('immobile_id', $saldo->immobile_id)
                    ->where('attivo', true)
                    // Filtra per tipologia se necessario (es. solo proprietari, non inquilini)
                    // ->whereIn('tipologia', ['proprietario', 'comproprietario'])
                    ->get();

                $totaleQuote = $proprietari->sum('quota') ?: 100;

                foreach ($proprietari as $prop) {
                    $importoProQuota = (int) round(($saldo->saldo_iniziale * $prop->quota) / $totaleQuote);
                    
                    if ($importoProQuota !== 0) {
                        $this->assegnaQuota(
                            $distribuzione,
                            $prop->anagrafica_id,
                            $saldo->immobile_id,
                            $importoProQuota,
                            $this->creaMeta($saldo, 'solidale_automatico', $prop->quota)
                        );
                    }
                }
            }
        }

        Log::info("Saldi elaborati e distribuiti su " . count($distribuzione) . " anagrafiche.");
        return $distribuzione;
    }

    private function assegnaQuota(array &$distribuzione, int $anagraficaId, ?int $immobileId, int $importo, array $meta): void
    {
        $immobileKey = $immobileId ?? 0; // Usiamo 0 come fallback se il saldo non è legato a un immobile

        if (!isset($distribuzione[$anagraficaId])) {
            $distribuzione[$anagraficaId] = [];
        }
        
        if (!isset($distribuzione[$anagraficaId][$immobileKey])) {
            $distribuzione[$anagraficaId][$immobileKey] = [
                'importo' => 0,
                'meta_storico' => []
            ];
        }

        $distribuzione[$anagraficaId][$immobileKey]['importo'] += $importo;
        $distribuzione[$anagraficaId][$immobileKey]['meta_storico'][] = $meta;
    }

    private function creaMeta(Saldo $saldo, string $tipoRiparto, ?float $quotaUsata = null): array
    {
        return [
            'saldo_origine_id' => $saldo->id,
            'gestione_origine_id' => $saldo->gestione_id,
            'tipo_riparto' => $tipoRiparto,
            'importo_originale' => $saldo->saldo_iniziale,
            'quota_applicata' => $quotaUsata
        ];
    }
}