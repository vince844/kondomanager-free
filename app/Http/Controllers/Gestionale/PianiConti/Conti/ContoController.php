<?php

namespace App\Http\Controllers\Gestionale\PianiConti\Conti;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoConto\Conto\CreateContoRequest;
use App\Http\Requests\Gestionale\PianoConto\Conto\UpdateContoRequest;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Tabella;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ContoController extends Controller
{
     use HandleFlashMessages;

    public function store(CreateContoRequest $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): RedirectResponse
    {
        try {
            DB::beginTransaction();
            $data = $request->validated();
            $isCapitolo = $data['isCapitolo'];
            $isSottoConto = $data['isSottoConto'];
                
            $nuovoConto = Conto::create([
                'piano_conto_id'        => $pianoConto->id,
                'parent_id'             => $isSottoConto ? ($data['parent_id'] ?? null) : null,
                'codice'                => $data['codice'] ?? null, // NUOVO
                'nome'                  => $data['nome'],
                'descrizione'           => $data['descrizione'] ?? null,
                'tipo'                  => $data['tipo'],
                'tipo_spesa'            => $data['tipo_spesa'] ?? 'standard', // NUOVO
                'importo'               => $isCapitolo ? 0 : MoneyHelper::toCents($data['importo']), 
                'default_fornitore_id'  => $data['default_fornitore_id'] ?? null, // NUOVO
                'note'                  => $data['note'] ?? null,
                'attivo'                => true,
            ]);

            if (!$isCapitolo) {
                if (!empty($data['tabella_millesimale_id'])) {
                    $tabella = Tabella::where('id', $data['tabella_millesimale_id'])->where('condominio_id', $condominio->id)->first();
                    if (!$tabella) throw new \Exception('Tabella millesimale non trovata');
                } 

                $contoTabellaId = DB::table('conto_tabella_millesimale')->insertGetId([
                    'conto_id' => $nuovoConto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100.00, 'created_at' => now(), 'updated_at' => now(),
                ]);

                $ripartizioni = [
                    ['soggetto' => 'proprietario', 'percentuale' => $data['percentuale_proprietario']],
                    ['soggetto' => 'inquilino', 'percentuale' => $data['percentuale_inquilino']],
                    ['soggetto' => 'usufruttuario', 'percentuale' => $data['percentuale_usufruttuario']]
                ];

                if (array_sum(array_column($ripartizioni, 'percentuale')) != 100) throw new \Exception("La somma delle percentuali deve essere 100%");

                foreach ($ripartizioni as $rip) {
                    if ($rip['percentuale'] > 0) {
                        DB::table('conto_tabella_ripartizioni')->insert([
                            'conto_tabella_millesimale_id' => $contoTabellaId, 'soggetto' => $rip['soggetto'], 'percentuale' => $rip['percentuale'], 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
            DB::commit();
            return to_route('admin.gestionale.esercizi.piani-conti.show', [$condominio->id, $esercizio->id, $pianoConto->id])->with($this->flashSuccess(__('gestionale.success_create_conto')));
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with($this->flashError($e->getMessage()));
        }
    }

    public function update(UpdateContoRequest $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): RedirectResponse
    {
        try {
            DB::beginTransaction();
            $data = $request->validated();
            $isCapitolo = $data['isCapitolo'];
            $nuovoImporto = $isCapitolo ? 0 : MoneyHelper::toCents($data['importo']);

            if (!$isCapitolo && $nuovoImporto != $conto->importo) {
                // 1. Blocco per rate approvate/emesse
                $hasHardLock = $conto->pianiRate()->whereIn('stato', ['approvato', 'emesso', 'chiuso'])->exists() ||
                               ($conto->parent && $conto->parent->pianiRate()->whereIn('stato', ['approvato', 'emesso', 'chiuso'])->exists());

                if ($hasHardLock) {
                    return back()->with($this->flashError("Modifica inibita: esistono rate già approvate o emesse."));
                }

                // 2. Blocco Elastico: non puoi scendere sotto l'impegnato
                $impegnato = (int) DB::table('piano_rate_capitoli')->where('conto_id', $conto->id)->sum('importo');

                if ($nuovoImporto < $impegnato) {
                    $giaPianificato = number_format($impegnato / 100, 2, ',', '.');
                    return back()->with($this->flashError("L'importo minimo consentito è € $giaPianificato (già impegnato nei piani rate)."));
                }
            }

            $conto->update([
                'parent_id'             => $data['isSottoConto'] ? ($data['parent_id'] ?? null) : null,
                'codice'                => $data['codice'] ?? null, // NUOVO
                'nome'                  => $data['nome'],
                'descrizione'           => $data['descrizione'] ?? null,
                'tipo'                  => $data['tipo'],
                'tipo_spesa'            => $data['tipo_spesa'] ?? 'standard', // NUOVO
                'importo'               => $nuovoImporto, 
                'default_fornitore_id'  => $data['default_fornitore_id'] ?? null, // NUOVO
                'note'                  => $data['note'] ?? null,
            ]);

            DB::commit();
            return to_route('admin.gestionale.esercizi.piani-conti.show', [$condominio->id, $esercizio->id, $pianoConto->id])
                ->with($this->flashSuccess(__('gestionale.success_update_conto')));
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with($this->flashError($e->getMessage()));
        }
    }

    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto, Conto $conto): RedirectResponse
    {
        if ($conto->sottoconti()->exists()) return back()->with($this->flashError(__('gestionale.error_conto_has_sottoconti')));
        
        $lock = $conto->pianiRate()->where('piani_rate.attivo', true)->exists() || 
                ($conto->parent && $conto->parent->pianiRate()->where('piani_rate.attivo', true)->exists());

        if ($lock) return back()->with($this->flashError("Impossibile eliminare: la voce è ancorata a un piano rate attivo."));

        try {
            DB::beginTransaction();
            $conto->tabelle()->detach(); 
            $conto->delete();
            DB::commit();
            return to_route('admin.gestionale.esercizi.piani-conti.show', [$condominio->id, $esercizio->id, $pianoConto->id])->with($this->flashSuccess(__('gestionale.success_delete_conto')));
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with($this->flashError($e->getMessage()));
        }
    }
}