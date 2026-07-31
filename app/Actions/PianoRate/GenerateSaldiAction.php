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
     * @return array Formato: [ anagrafica_id => [ immobile_id => [ 'importo' => X, 'meta_storico' => [...] ] ] ]
     */
    public function execute(PianoRate $pianoRate, Gestione $gestione, array $saldiConfig = []): array
    {
        // Un saldo è disponibile per questo piano se è libero, OPPURE se è già
        // intestato proprio a questo piano: senza il secondo caso, ricalcolare
        // un piano che aveva assorbito i pregressi li faceva sparire dalle
        // quote (il piano ripartiva dal solo preventivo) e il lucchetto restava
        // chiuso senza più nessuno in grado di riaprirlo.
        $saldiDaApplicare = Saldo::where('gestione_id', $gestione->id)
            // I debiti verso fornitori vivono nella stessa tabella e non devono
            // MAI essere candidati a un piano rate. Oggi ne restano fuori perché
            // nascono già bloccati: è un'invariante non scritta nello schema, e
            // quindi non è una difesa.
            ->whereNull('fornitore_id')
            ->where(function ($q) use ($pianoRate) {
                $q->where('is_applicato', false)
                  ->orWhere('piano_rate_id', $pianoRate->id);
            })
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
                    $saldo->saldo_iniziale, // Importo in centesimi dal DB
                    $this->creaMeta($saldo, 'nominale')
                );
                continue;
            }

            // CASO B: Saldo Solidale (Art. 63) - anagrafica_id è NULL
            $configCustom = $saldiConfigMap->get($saldo->id);

            if ($configCustom && !empty($configCustom['ripartizioni'])) {
                // B1. Riparto Manuale (L'utente ha forzato le quote da Vue)
                foreach ($configCustom['ripartizioni'] as $rip) {
                    $this->assegnaQuota(
                        $distribuzione,
                        $rip['anagrafica_id'],
                        $saldo->immobile_id,
                        // CENTESIMI, non euro. Il chiamante ha già convertito con
                        // MoneyHelper::toCents (PianoRateController::store), l'unico
                        // che sa leggere la stringa mascherata "1.200,50" del form.
                        // Il vecchio `* 100` qui la convertiva una seconda volta:
                        // un riparto manuale di 250,00 € addebitava 25.000,00 €.
                        (int) $rip['importo'],
                        $this->creaMeta($saldo, 'solidale_manuale')
                    );
                }
            } else {
                // B2. Riparto Automatico (Pro-Quota sui proprietari attuali)
                $proprietari = DB::table('anagrafica_immobile')
                    ->where('immobile_id', $saldo->immobile_id)
                    ->where('attivo', true)
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
                            $this->creaMeta($saldo, 'solidale_automatico', (float) $prop->quota)
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
            // FIX: Inizializziamo esplicitamente 'importo' a 0 prima di sommarlo
            $distribuzione[$anagraficaId][$immobileKey] = [
                'importo' => 0,
                'meta_storico' => []
            ];
        }

        // FIX: Sommiamo l'importo calcolato alla chiave corretta
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