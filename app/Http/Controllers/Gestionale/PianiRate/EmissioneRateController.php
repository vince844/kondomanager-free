<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\RigaScrittura; // <--- Ora lo usiamo ovunque
use App\Enums\StatoPianoRate;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmissioneRateController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    public function store(Request $request, Condominio $condominio, PianoRate $pianoRate)
    {
        Log::info("--- START EMISSIONE RATE (Modello RigaScrittura) ---", [
            'condominio_id' => $condominio->id,
            'rate_ids' => $request->rate_ids
        ]);
  
        if ($pianoRate->stato !== StatoPianoRate::APPROVATO) {
            return back()->with($this->flashError('Devi approvare il piano rate prima di poter emettere le rate.'));
        }

        $request->validate([
            'rate_ids' => 'required|array|min:1',
            'rate_ids.*' => 'exists:rate,id',
            'data_emissione' => 'required|date',
            'descrizione_personalizzata' => 'nullable|string|max:255',
        ]);

        $esercizio = $this->getEsercizioCorrente($condominio);
        
        // Recupero conti fondamentali
        $contoCrediti = ContoContabile::where('condominio_id', $condominio->id)
            ->where('ruolo', 'crediti_condomini')
            ->first();
        $contoGestione = ContoContabile::where('condominio_id', $condominio->id)
            ->where('ruolo', 'gestione_rate')
            ->first();

        if (!$contoCrediti || !$contoGestione) {
            return back()->with($this->flashError('Mancano i conti contabili (Crediti o Gestione Rate).'));
        }

        try {
            DB::transaction(function () use ($request, $condominio, $pianoRate, $esercizio, $contoCrediti, $contoGestione) {
                
                $rateSelezionate = Rata::with('rateQuote')
                    ->where('piano_rate_id', $pianoRate->id)
                    ->whereIn('id', $request->rate_ids)
                    ->get();

                foreach ($rateSelezionate as $rata) {
                    if ($rata->rateQuote->whereNotNull('scrittura_contabile_id')->isNotEmpty()) continue;

                    $totaleRataCentesimi = 0; // Cambiato da float a integer

                    $scrittura = ScritturaContabile::create([
                        'condominio_id'      => $condominio->id,
                        'esercizio_id'       => $esercizio->id,
                        'gestione_id'        => $pianoRate->gestione_id,
                        'data_registrazione' => now(),
                        'data_competenza'    => $request->data_emissione,
                        'causale'            => $request->descrizione_personalizzata ?: "Emissione " . $rata->descrizione,
                        'tipo_movimento'     => 'emissione_rata',
                        'stato'              => 'registrata',
                    ]);

                    foreach ($rata->rateQuote as $quota) {
                        if ($quota->importo <= 0) continue;

                        // 🔥 CORREZIONE: $quota->importo è già in centesimi (integer)
                        $importoCentesimi = $quota->importo; 

                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoCrediti->id,
                            'anagrafica_id'      => $quota->anagrafica_id,
                            'immobile_id'        => $quota->immobile_id,
                            'rata_id'            => $rata->id,
                            'tipo_riga'          => 'dare',
                            'importo'            => $importoCentesimi,
                            'note'               => "Quota " . $rata->descrizione
                        ]);

                        $quota->update(['scrittura_contabile_id' => $scrittura->id]);
                        $totaleRataCentesimi += $importoCentesimi;
                    }

                    // 3. Riga AVERE (Gestione Rate)
                    if ($totaleRataCentesimi > 0) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoGestione->id,
                            'tipo_riga'          => 'avere',
                            'importo'            => $totaleRataCentesimi,
                            'note'               => "Totale emissione " . $rata->descrizione
                        ]);
                    }
                }
            });

            return back()->with($this->flashSuccess('Rate emesse correttamente.'));

        } catch (\Throwable $e) {
            Log::error("Errore emissione: " . $e->getMessage());
            return back()->with($this->flashError('Errore tecnico: ' . $e->getMessage()));
        }
    }

    public function destroy(Request $request, Condominio $condominio, PianoRate $pianoRate, Rata $rata)
    {
        // Check preventivo su incassi
        $haPagamenti = DB::table('rate_quote')
            ->where('rata_id', $rata->id)
            ->where('importo_pagato', '>', 0)
            ->exists();

        if ($haPagamenti) {
            return back()->with($this->flashError('Impossibile annullare: ci sono già incassi registrati.'));
        }

        try {
            DB::transaction(function () use ($rata) {
                // Trova gli ID delle scritture da eliminare
                $scrittureIds = $rata->rateQuote()->pluck('scrittura_contabile_id')->filter()->unique();

                // 1. Scollega le quote (set null)
                $rata->rateQuote()->update(['scrittura_contabile_id' => null]);

                if ($scrittureIds->isNotEmpty()) {
                    
                    // 2. Cancella le RIGHE usando il Modello (Molto meglio!)
                    // Eloquent capisce da solo che la tabella è 'righe_scritture'
                    
                    RigaScrittura::whereIn('scrittura_id', $scrittureIds)->delete();

                    // 3. Cancella le TESTATE
                    ScritturaContabile::whereIn('id', $scrittureIds)->delete();
                }
            });

            return back()->with($this->flashSuccess('Emissione annullata.'));

        } catch (\Throwable $e) {
            Log::error("Errore annullamento: " . $e->getMessage());
            return back()->with($this->flashError('Errore: ' . $e->getMessage()));
        }
    }
}