<?php

namespace App\Http\Controllers\Gestionale\PianiConti\Conti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Tabella;
use App\Services\Gestionale\BloccoDellaVoce;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssociaTabellaController extends Controller
{
    public function __invoke(Request $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): RedirectResponse
    {
        // Una voce che un piano approvato, emesso o chiuso tiene, con la sua riga o con quella di un capitolo sopra di lei, non cambia tabella né
        // percentuali: il motore la ripartirebbe ad altre persone (`Conto::pianiCheBloccanoLaVoce`, la stessa regola del salvataggio della voce).
        if ($rifiuto = BloccoDellaVoce::rifiutoPerLaVoce($conto)) {
            return redirect()->back()->with('message', ['message' => $rifiuto, 'type' => 'error']);
        }

        try {
            DB::beginTransaction();

            $data = $request->validate([
                'tabella_millesimale_id'    => 'required|exists:tabelle,id',
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

            // Verifica che la tabella appartenga al condominio
            $tabella = Tabella::where('id', $data['tabella_millesimale_id'])
                ->where('condominio_id', $condominio->id)
                ->first();

            if (!$tabella) {
                throw new \Exception('Tabella millesimale non trovata per questo condominio.');
            }

            // -------------------------------------------------------
            // BLOCCO HARD: somma coefficienti esistenti + nuovo ≤ 100
            // -------------------------------------------------------
            $sommaEsistente = DB::table('conto_tabella_millesimale')
                ->where('conto_id', $conto->id)
                ->sum('coefficiente');

            $nuovaSomma = $sommaEsistente + $data['coefficiente'];

            if ($nuovaSomma > 100) {
                $residuo = 100 - $sommaEsistente;
                throw new \Exception(
                    "Impossibile associare la tabella: la somma dei coefficienti supererebbe il 100% " .
                    "(attuale: {$sommaEsistente}%, aggiunta: {$data['coefficiente']}%). " .
                    "Residuo disponibile: {$residuo}%."
                );
            }
            // -------------------------------------------------------

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
                ['soggetto' => 'proprietario',  'percentuale' => $data['percentuale_proprietario']],
                ['soggetto' => 'inquilino',      'percentuale' => $data['percentuale_inquilino']],
                ['soggetto' => 'usufruttuario',  'percentuale' => $data['percentuale_usufruttuario']],
            ];

            foreach ($ripartizioni as $r) {
                if ($r['percentuale'] > 0) {
                    DB::table('conto_tabella_ripartizioni')->insert([
                        'conto_tabella_millesimale_id' => $contoTabellaId,
                        'soggetto'                     => $r['soggetto'],
                        'percentuale'                  => $r['percentuale'],
                        'created_at'                   => now(),
                        'updated_at'                   => now(),
                    ]);
                }
            }

            DB::commit();

            return redirect()->back()->with('message', [
                'message' => 'Tabella associata con successo!',
                'type'    => 'success',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Errore durante l\'associazione della tabella:', [
                'condominio_id'  => $condominio->id,
                'esercizio_id'   => $esercizio->id,
                'piano_conto_id' => $pianoConto->id,
                'conto_id'       => $conto->id,
                'error'          => $e->getMessage(),
            ]);

            return redirect()->back()->with('message', [
                'message' => 'Errore: ' . $e->getMessage(),
                'type'    => 'error',
            ]);
        }
    }
}