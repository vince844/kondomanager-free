<?php

namespace App\Http\Controllers\Gestionale\PianiConti\Conti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Tabella;
use App\Services\Gestionale\BloccoDellaVoce;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DissociaTabellaController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto, Tabella $tabella): RedirectResponse
    {
        // Una voce che un piano approvato, emesso o chiuso tiene, con la sua riga o con quella di un capitolo sopra di lei, non cambia tabella né
        // percentuali: il motore la ripartirebbe ad altre persone (`Conto::pianiCheBloccanoLaVoce`, la stessa regola del salvataggio della voce).
        if ($rifiuto = BloccoDellaVoce::rifiutoPerLaVoce($conto)) {
            return redirect()->back()->with('message', ['message' => $rifiuto, 'type' => 'error']);
        }

        try {
            DB::beginTransaction();

            // Trova l'associazione conto-tabella
            $contoTabella = DB::table('conto_tabella_millesimale')
                ->where('conto_id', $conto->id)
                ->where('tabella_id', $tabella->id)
                ->first();

            if ($contoTabella) {
                // Rimuovi le ripartizioni
                DB::table('conto_tabella_ripartizioni')
                    ->where('conto_tabella_millesimale_id', $contoTabella->id)
                    ->delete();
                
                // Rimuovi l'associazione
                DB::table('conto_tabella_millesimale')
                    ->where('id', $contoTabella->id)
                    ->delete();
            }

            DB::commit();

            return redirect()->back()->with('message', [
                'message' => 'Tabella rimossa con successo!',
                'type' => 'success'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Errore durante la rimozione della tabella:', [
                'condominio_id' => $condominio->id,
                'esercizio_id'  => $esercizio->id,
                'piano_conto_id' => $pianoConto->id,
                'conto_id'      => $conto->id,
                'tabella_id'    => $tabella->id,
                'error'         => $e->getMessage()
            ]);

            return redirect()->back()->with('message', [
                'message' => 'Errore durante la rimozione della tabella: ' . $e->getMessage(),
                'type' => 'error'
            ]);
        }
    }
}