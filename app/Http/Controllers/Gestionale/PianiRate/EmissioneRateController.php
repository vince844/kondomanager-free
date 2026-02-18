<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Enums\CategoriaEventoEnum;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\RigaScrittura;
use App\Enums\StatoPianoRate;
use App\Enums\VisibilityStatus;
use App\Events\Gestionale\RataEmessa;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Services\Gestionale\InboxService;
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
        Log::info("--- START EMISSIONE RATE ---", [
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

                    $totaleRataCentesimi = 0; 

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
                        
                        // Default: usa l'importo standard (fallback per vecchie rate)
                        $importoDaRegistrare = $quota->importo;

                        // 1. TENTA LETTURA DAL JSON (Versione 1.8+)
                        // In contabilità dobbiamo registrare il DEBITO LORDO (Quota Pura),
                        // ignorando il fatto che sia coperto dal saldo (credito).
                        if (!empty($quota->regole_calcolo)) {
                            $json = is_string($quota->regole_calcolo) ? json_decode($quota->regole_calcolo) : (object)$quota->regole_calcolo;
                            
                            if (isset($json->importi->quota_pura_gestione)) {
                                $importoDaRegistrare = (int) $json->importi->quota_pura_gestione;
                            }
                        }

                        // 2. Controllo di sicurezza: Registriamo solo debiti positivi
                        // Se la spesa reale è <= 0, non emettiamo nulla.
                        if ($importoDaRegistrare <= 0) continue;

                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoCrediti->id,
                            'anagrafica_id'      => $quota->anagrafica_id,
                            'immobile_id'        => $quota->immobile_id,
                            'rata_id'            => $rata->id,
                            'tipo_riga'          => 'dare',
                            'importo'            => $importoDaRegistrare, // <--- USIAMO IL VALORE LORDO
                            'note'               => "Quota " . $rata->descrizione
                        ]);

                        $quota->update(['scrittura_contabile_id' => $scrittura->id]);
                        
                        // Sommiamo al totale scrittura l'importo EFFETTIVAMENTE registrato
                        $totaleRataCentesimi += $importoDaRegistrare;
                    }

                    if ($totaleRataCentesimi > 0) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoGestione->id,
                            'tipo_riga'          => 'avere',
                            'importo'            => $totaleRataCentesimi,
                            'note'               => "Totale emissione " . $rata->descrizione
                        ]);
                    }

                    // ... (resto della logica eventi/task invariata) ...
                    $userEvents = Evento::where('meta->type', 'scadenza_rata_condomino')
                        ->where('meta->context->rata_id', $rata->id)
                        ->get();

                    foreach ($userEvents as $evt) {
                        $meta = $evt->meta;
                        $meta['is_emitted'] = true;
                        $evt->update(['meta' => $meta]);
                    }

                    RataEmessa::dispatch($rata);

                    Evento::whereJsonContains('meta->context->rata_id', $rata->id)
                        ->whereJsonContains('meta->type', 'emissione_rata')
                        ->delete(); 
                }
            });

            // CACHE BUSTER INTELLIGENTE (Fix Multi-Admin)
            InboxService::clearAdminCache();

            return back()->with($this->flashSuccess('Rate emesse correttamente.'));

        } catch (\Throwable $e) {
            Log::error("Errore emissione rate: " . $e->getMessage());

            if (str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'numero_protocollo_unique')) {
                return back()->with($this->flashError(
                    'Errore di numerazione: Il sistema ha tentato di usare un numero di protocollo già esistente.'
                ));
            }

            return back()->with($this->flashError('Si è verificato un errore tecnico durante l\'emissione.'));
        }
    }

    // ... (metodo destroy invariato) ...
    public function destroy(Request $request, Condominio $condominio, PianoRate $pianoRate, Rata $rata)
    {
        // ... (il tuo codice destroy va bene così com'è) ...
        $haPagamenti = DB::table('rate_quote')
            ->where('rata_id', $rata->id)
            ->where('importo_pagato', '>', 0)
            ->exists();

        if ($haPagamenti) {
            return back()->with($this->flashError('Impossibile annullare: ci sono già incassi registrati.'));
        }

        $esercizio = $this->getEsercizioCorrente($condominio);

        if (!$esercizio) {
            return back()->with($this->flashError('Nessun esercizio aperto trovato per generare il link del task.'));
        }

        try {
            DB::transaction(function () use ($rata, $condominio, $pianoRate, $request, $esercizio) { 
                
                $scrittureIds = $rata->rateQuote()->pluck('scrittura_contabile_id')->filter()->unique();
                $rata->rateQuote()->update(['scrittura_contabile_id' => null]);

                if ($scrittureIds->isNotEmpty()) {
                    RigaScrittura::whereIn('scrittura_id', $scrittureIds)->delete();
                    ScritturaContabile::whereIn('id', $scrittureIds)->forceDelete(); 
                }

                $userEvents = Evento::where('meta->type', 'scadenza_rata_condomino')
                    ->where('meta->context->rata_id', $rata->id)
                    ->get();

                foreach ($userEvents as $evt) {
                    $meta = $evt->meta;
                    $meta['is_emitted'] = false; 
                    $evt->update(['meta' => $meta]);
                }
                
                $catAdmin = CategoriaEvento::where('name', CategoriaEventoEnum::SCADENZE_AMMINISTRATIVE->value)->first();
                $dataPromemoria = $rata->data_scadenza->copy()->subDays(7)->setTime(9, 0);
                
                Evento::firstOrCreate(
                    [
                        'title' => "Emettere rata {$rata->numero_rata} - {$condominio->nome}",
                        'meta->context->rata_id' => $rata->id, 
                        'meta->type' => 'emissione_rata'
                    ],
                    [
                        'start_time' => $dataPromemoria,
                        'end_time'   => $dataPromemoria->copy()->addHour(),
                        'created_by' => $request->user()->id,
                        'description' => "Ricordati di emettere le ricevute per questa rata entro la scadenza. (Riemissione dopo annullamento)",
                        'category_id' => $catAdmin?->id,
                        'visibility'  => VisibilityStatus::HIDDEN->value, 
                        'is_approved' => true,
                        'meta' => [
                            'type'            => 'emissione_rata',
                            'requires_action' => true, 
                            'context' => [
                                'piano_rate_id' => $pianoRate->id,
                                'rata_id'       => $rata->id
                            ],
                            'gestione'          => $pianoRate->gestione->nome ?? 'Gestione',
                            'condominio_nome'   => $condominio->nome,
                            'totale_rata'       => $rata->importo_totale,
                            'anagrafiche_count' => $rata->rateQuote->unique('anagrafica_id')->count(),
                            'scadenza_reale'    => $rata->data_scadenza->toDateString(),
                            'numero_rata'       => $rata->numero_rata,
                            'piano_nome'        => $pianoRate->nome,
                            'action_url'        => route('admin.gestionale.esercizi.piani-rate.show', [
                                'condominio' => $condominio->id,
                                'esercizio'  => $esercizio->id, 
                                'pianoRate'  => $pianoRate->id
                            ])
                        ],
                    ]
                );
                
                $evento = Evento::where('meta->context->rata_id', $rata->id)
                                ->where('meta->type', 'emissione_rata')
                                ->first();
                                
                if ($evento) {
                    $evento->condomini()->syncWithoutDetaching([$condominio->id]);
                    if ($request->user()->anagrafica_id) {
                        $evento->anagrafiche()->syncWithoutDetaching([$request->user()->anagrafica_id]);
                    }
                }
            });


            // CACHE BUSTER INTELLIGENTE (Fix Multi-Admin)
            InboxService::clearAdminCache();

            return back()->with($this->flashSuccess('Emissione annullata. La rata è tornata in bozza e il promemoria è stato ripristinato.'));

        } catch (\Throwable $e) {
            Log::error("Errore annullamento: " . $e->getMessage());
            return back()->with($this->flashError('Si è verificato un errore durante l\'annullamento.'));
        }
    }
}