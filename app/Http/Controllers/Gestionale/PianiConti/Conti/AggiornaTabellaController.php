<?php

namespace App\Http\Controllers\Gestionale\PianiConti\Conti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Tabella;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggiornaTabellaController extends Controller
{
    public function __invoke(
        Request $request,
        Condominio $condominio,
        Esercizio $esercizio,
        PianoConto $pianoConto,
        Conto $conto,
        Tabella $tabella
    ): RedirectResponse {
        try {
            DB::beginTransaction();

            $data = $request->validate([
                'coefficiente'              => 'required|numeric|min:0|max:100',
                'percentuale_proprietario'  => 'required|integer|min:0|max:100',
                'percentuale_inquilino'     => 'required|integer|min:0|max:100',
                'percentuale_usufruttuario' => 'required|integer|min:0|max:100',
            ]);

            // Verifica somma percentuali soggetti = 100
            $sommaPercentuali = $data['percentuale_proprietario']
                              + $data['percentuale_inquilino']
                              + $data['percentuale_usufruttuario'];

            if ($sommaPercentuali != 100) {
                throw new \Exception("La somma delle percentuali deve essere 100%. Attuale: {$sommaPercentuali}%");
            }

            // Recupera l'associazione esistente
            $contoTabella = DB::table('conto_tabella_millesimale')
                ->where('conto_id', $conto->id)
                ->where('tabella_id', $tabella->id)
                ->first();

            if (!$contoTabella) {
                throw new \Exception('Associazione tabella-conto non trovata.');
            }

            // -------------------------------------------------------
            // BLOCCO HARD: somma delle ALTRE tabelle + nuovo valore <= 100
            // Escludiamo la riga corrente (quella che stiamo modificando)
            // -------------------------------------------------------
            $sommaAltri = DB::table('conto_tabella_millesimale')
                ->where('conto_id', $conto->id)
                ->where('id', '!=', $contoTabella->id)
                ->sum('coefficiente');

            $nuovaSomma = $sommaAltri + $data['coefficiente'];

            if ($nuovaSomma > 100) {
                $maxConsentito = 100 - $sommaAltri;
                throw new \Exception(
                    "Impossibile aggiornare: la somma dei coefficienti supererebbe il 100% " .
                    "(altre tabelle: {$sommaAltri}%). " .
                    "Valore massimo consentito per questa tabella: {$maxConsentito}%."
                );
            }
            // -------------------------------------------------------

            // Aggiorna il coefficiente
            DB::table('conto_tabella_millesimale')
                ->where('id', $contoTabella->id)
                ->update([
                    'coefficiente' => $data['coefficiente'],
                    'updated_at'   => now(),
                ]);

            // Ricrea le ripartizioni (delete + insert)
            DB::table('conto_tabella_ripartizioni')
                ->where('conto_tabella_millesimale_id', $contoTabella->id)
                ->delete();

            $ripartizioni = [
                ['soggetto' => 'proprietario',  'percentuale' => $data['percentuale_proprietario']],
                ['soggetto' => 'inquilino',      'percentuale' => $data['percentuale_inquilino']],
                ['soggetto' => 'usufruttuario',  'percentuale' => $data['percentuale_usufruttuario']],
            ];

            foreach ($ripartizioni as $r) {
                if ($r['percentuale'] > 0) {
                    DB::table('conto_tabella_ripartizioni')->insert([
                        'conto_tabella_millesimale_id' => $contoTabella->id,
                        'soggetto'                     => $r['soggetto'],
                        'percentuale'                  => $r['percentuale'],
                        'created_at'                   => now(),
                        'updated_at'                   => now(),
                    ]);
                }
            }

            DB::commit();

            return redirect()->back()->with('message', [
                'message' => 'Associazione aggiornata con successo!',
                'type'    => 'success',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Errore durante l\'aggiornamento della tabella:', [
                'condominio_id'  => $condominio->id,
                'esercizio_id'   => $esercizio->id,
                'piano_conto_id' => $pianoConto->id,
                'conto_id'       => $conto->id,
                'tabella_id'     => $tabella->id,
                'error'          => $e->getMessage(),
            ]);

            return redirect()->back()->with('message', [
                'message' => 'Errore: ' . $e->getMessage(),
                'type'    => 'error',
            ]);
        }
    }
}