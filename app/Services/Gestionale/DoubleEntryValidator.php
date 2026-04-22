<?php

namespace App\Services\Gestionale;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Exception;

class DoubleEntryValidator
{
    /**
     * Verifica che una scrittura contabile quadri perfettamente al centesimo.
     * Se rileva uno sbilancio, lancia un'eccezione che blocca il salvataggio (Rollback).
     *
     * @param int $scritturaId
     * @throws Exception
     */
    public static function validateOrFail(int $scritturaId): void
    {
        // Raggruppiamo e sommiamo DARE e AVERE direttamente via SQL per massima efficienza
        $totali = DB::table('righe_scritture')
            ->select('tipo_riga', DB::raw('SUM(importo) as totale'))
            ->where('scrittura_id', $scritturaId)
            ->groupBy('tipo_riga')
            ->pluck('totale', 'tipo_riga');

        $dare = (int) ($totali['dare'] ?? 0);
        $avere = (int) ($totali['avere'] ?? 0);

        if ($dare !== $avere) {
            $differenza = abs($dare - $avere);

            // Audit Trail: Logghiamo l'anomalia critica
            Log::critical("🛡️ [AUDIT CONTABILE] Bloccato salvataggio scrittura sbilanciata!", [
                'scrittura_id' => $scritturaId,
                'dare'         => $dare,
                'avere'        => $avere,
                'differenza'   => $differenza,
                'user_id'      => Auth::id() ?? 'system'
            ]);

            throw new Exception(
                sprintf(
                    "Errore di integrità contabile: Sbilancio rilevato tra DARE (€ %s) e AVERE (€ %s). L'operazione è stata annullata a tutela del bilancio.",
                    number_format($dare / 100, 2, ',', '.'),
                    number_format($avere / 100, 2, ',', '.')
                )
            );
        }
    }
}