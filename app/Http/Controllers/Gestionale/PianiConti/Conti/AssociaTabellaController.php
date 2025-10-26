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

class AssociaTabellaController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): RedirectResponse
    {
        try {
            DB::beginTransaction();

            $data = $request->validate([
                'tabella_millesimale_id'    => 'required|exists:tabelle,id',
                'coefficiente'              => 'required|numeric|min:0|max:100', 
                'percentuale_proprietario'  => 'required|integer|min:0|max:100',
                'percentuale_inquilino'     => 'required|integer|min:0|max:100',
                'percentuale_usufruttuario' => 'required|integer|min:0|max:100',
            ]);

            // Verifica che la somma delle percentuali sia 100
            $sommaPercentuali = $data['percentuale_proprietario'] + 
                            $data['percentuale_inquilino'] + 
                            $data['percentuale_usufruttuario'];
            
            if ($sommaPercentuali != 100) {
                throw new \Exception("La somma delle percentuali deve essere 100%. Attuale: {$sommaPercentuali}%");
            }

            // Verifica che la tabella appartenga al condominio
            $tabella = Tabella::where('id', $data['tabella_millesimale_id'])
                ->where('condominio_id', $condominio->id)
                ->first();

            if (!$tabella) {
                throw new \Exception('Tabella millesimale non trovata per questo condominio');
            }

            // Crea l'associazione
            $contoTabellaId = DB::table('conto_tabella_millesimale')->insertGetId([
                'conto_id'     => $conto->id,
                'tabella_id'   => $tabella->id,
                'coefficiente' => $data['coefficiente'],
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            // Crea le ripartizioni
            $ripartizioni = [
                [
                    'soggetto' => 'proprietario',
                    'percentuale' => $data['percentuale_proprietario']
                ],
                [
                    'soggetto' => 'inquilino', 
                    'percentuale' => $data['percentuale_inquilino']
                ],
                [
                    'soggetto' => 'usufruttuario',
                    'percentuale' => $data['percentuale_usufruttuario']
                ]
            ];

            foreach ($ripartizioni as $ripartizione) {
                if ($ripartizione['percentuale'] > 0) {
                    DB::table('conto_tabella_ripartizioni')->insert([
                        'conto_tabella_millesimale_id' => $contoTabellaId,
                        'soggetto'                     => $ripartizione['soggetto'],
                        'percentuale'                  => $ripartizione['percentuale'],
                        'created_at'                   => now(),
                        'updated_at'                   => now(),
                    ]);
                }
            }

            DB::commit();

            return redirect()->back()->with('message', [
                'message' => 'Tabella associata con successo!',
                'type' => 'success'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Errore durante l\'associazione della tabella:', [
                'condominio_id' => $condominio->id,
                'esercizio_id'  => $esercizio->id,
                'piano_conto_id' => $pianoConto->id,
                'conto_id'      => $conto->id,
                'error'         => $e->getMessage()
            ]);

            return redirect()->back()->with('message', [
                'message' => 'Errore durante l\'associazione della tabella: ' . $e->getMessage(),
                'type' => 'error'
            ]);
        }
    }
}
