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

/**
 * Controller responsabile per l'emissione e l'annullamento delle rate condominiali.
 * Gestisce la creazione delle scritture contabili in Prima Nota (Ciclo Attivo)
 * e la visibilità/notifica degli eventi nello scadenziario dei condòmini.
 */
class EmissioneRateController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    /**
     * Emette una o più rate di un piano approvato.
     * Genera le scritture contabili e gestisce l'emissione "Silenziosa" 
     * per evitare l'invio prematuro di notifiche (Finestra di Vulnerabilità).
     *
     * @param Request $request
     * @param Condominio $condominio
     * @param PianoRate $pianoRate
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, Condominio $condominio, PianoRate $pianoRate)
    {
        Log::info("--- START EMISSIONE RATE ---", [
            'condominio_id' => $condominio->id,
            'rate_ids' => $request->rate_ids,
            'invia_notifiche' => $request->invia_notifiche
        ]);
  
        if ($pianoRate->stato !== StatoPianoRate::APPROVATO) {
            return back()->with($this->flashError('Devi approvare il piano rate prima di poter emettere le rate.'));
        }

        $request->validate([
            'rate_ids' => 'required|array|min:1',
            'rate_ids.*' => 'exists:rate,id',
            'data_emissione' => 'required|date',
            'descrizione_personalizzata' => 'nullable|string|max:255',
            'invia_notifiche' => 'boolean' // Validazione del nuovo interruttore
        ]);

        $esercizio = $this->getEsercizioCorrente($condominio);
        $inviaNotifiche = $request->boolean('invia_notifiche', true); // Default a true se non passato
        
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
            DB::transaction(function () use ($request, $condominio, $pianoRate, $esercizio, $contoCrediti, $contoGestione, $inviaNotifiche) {
                
                $rateSelezionate = Rata::with('rateQuote')
                    ->where('piano_rate_id', $pianoRate->id)
                    ->whereIn('id', $request->rate_ids)
                    ->get();

                foreach ($rateSelezionate as $rata) {
                    if ($rata->rateQuote->whereNotNull('scrittura_contabile_id')->isNotEmpty()) continue;

                    $totaleRataCentesimi = 0; 

                    // 1. Scrittura Testata
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

                    // 2. Scrittura Righe (Dettaglio quote)
                    foreach ($rata->rateQuote as $quota) {
                        
                        $importoDaRegistrare = $quota->importo;

                        if (!empty($quota->regole_calcolo)) {
                            $json = is_string($quota->regole_calcolo) ? json_decode($quota->regole_calcolo) : (object)$quota->regole_calcolo;
                            
                            if (isset($json->importi->quota_pura_gestione)) {
                                $importoDaRegistrare = (int) $json->importi->quota_pura_gestione;
                            }
                        }

                        if ($importoDaRegistrare <= 0) continue;

                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoCrediti->id,
                            'anagrafica_id'      => $quota->anagrafica_id,
                            'immobile_id'        => $quota->immobile_id,
                            'rata_id'            => $rata->id,
                            'tipo_riga'          => 'dare',
                            'importo'            => $importoDaRegistrare, 
                            'note'               => "Quota " . $rata->descrizione
                        ]);

                        $quota->update(['scrittura_contabile_id' => $scrittura->id]);
                        $totaleRataCentesimi += $importoDaRegistrare;
                    }

                    // 3. Chiusura in Avere (Gestione)
                    if ($totaleRataCentesimi > 0) {
                        $scrittura->righe()->create([
                            'conto_contabile_id' => $contoGestione->id,
                            'tipo_riga'          => 'avere',
                            'importo'            => $totaleRataCentesimi,
                            'note'               => "Totale emissione " . $rata->descrizione
                        ]);
                    }

                    // 4. Gestione Eventi Condòmini (Finestra di Vulnerabilità)
                    $userEvents = Evento::where('meta->type', 'scadenza_rata_condomino')
                        ->where('meta->context->rata_id', $rata->id)
                        ->get();

                    foreach ($userEvents as $evt) {
                        $meta = $evt->meta;
                        $meta['is_emitted'] = true; // Contabilmente è emessa
                        $meta['is_published'] = $inviaNotifiche; // Se falso, l'utente non lo sa ancora
                        
                        $evt->update([
                            'meta' => $meta,
                            // Nascondiamo fisicamente l'evento se è un'emissione silenziosa
                            'visibility' => $inviaNotifiche ? VisibilityStatus::PRIVATE->value : VisibilityStatus::HIDDEN->value
                        ]);
                    }

                    // 5. Invio Notifiche (SOLO SE RICHIESTO)
                    if ($inviaNotifiche) {
                        RataEmessa::dispatch($rata);
                    }

                    // 6. Pulizia Task Admin (Promemoria Emissione)
                    Evento::whereJsonContains('meta->context->rata_id', $rata->id)
                        ->whereJsonContains('meta->type', 'emissione_rata')
                        ->delete(); 
                }
            });

            InboxService::clearAdminCache();

            $msg = $inviaNotifiche 
                ? 'Rate emesse e notificate correttamente ai condòmini.' 
                : 'Rate emesse in modalità silenziosa. I condòmini non vedranno gli importi finché non li pubblicherai.';

            return back()->with($this->flashSuccess($msg));

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

    /**
     * Annulla l'emissione di una singola rata.
     * Rimuove la scrittura contabile e ripristina lo stato dell'evento utente in "Bozza".
     *
     * @param Request $request
     * @param Condominio $condominio
     * @param PianoRate $pianoRate
     * @param Rata $rata
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Request $request, Condominio $condominio, PianoRate $pianoRate, Rata $rata)
    {
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
                
                // 1. Sgancio e rimozione Scritture
                $scrittureIds = $rata->rateQuote()->pluck('scrittura_contabile_id')->filter()->unique();
                $rata->rateQuote()->update(['scrittura_contabile_id' => null]);

                if ($scrittureIds->isNotEmpty()) {
                    RigaScrittura::whereIn('scrittura_id', $scrittureIds)->delete();
                    ScritturaContabile::whereIn('id', $scrittureIds)->forceDelete(); 
                }

                // 2. Ripristino Eventi Utente
                $userEvents = Evento::where('meta->type', 'scadenza_rata_condomino')
                    ->where('meta->context->rata_id', $rata->id)
                    ->get();

                foreach ($userEvents as $evt) {
                    $meta = $evt->meta;
                    $meta['is_emitted'] = false; 
                    $meta['is_published'] = false; // Reset stato pubblicazione
                    
                    $evt->update([
                        'meta' => $meta,
                        'visibility' => VisibilityStatus::PRIVATE->value // Torna "Bozza" visibile ma non pagabile
                    ]);
                }
                
                // 3. Rigenerazione Task Admin (Promemoria Emissione)
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

            InboxService::clearAdminCache();

            return back()->with($this->flashSuccess('Emissione annullata. La rata è tornata in bozza e il promemoria è stato ripristinato.'));

        } catch (\Throwable $e) {
            Log::error("Errore annullamento: " . $e->getMessage());
            return back()->with($this->flashError('Si è verificato un errore durante l\'annullamento.'));
        }
    }

    /**
     * Sblocca la visibilità delle rate emesse in modalità "Silenziosa".
     * Le rende visibili nell'app e invia finalmente le notifiche ai condòmini.
     */
    public function publishSilent(Request $request, Condominio $condominio, $esercizio, PianoRate $pianoRate)
    {
        try {
            DB::transaction(function () use ($pianoRate) {
    
                // 1. Trova tutti gli eventi "Silenziosi" usando i meta flag
                $hiddenEvents = Evento::where('meta->type', 'scadenza_rata_condomino')
                    ->where('meta->context->piano_rate_id', $pianoRate->id)
                    ->where('meta->is_emitted', true)
                    ->where('meta->is_published', false)
                    ->get();

                if ($hiddenEvents->isEmpty()) {
                    return;
                }

                $rataIds = [];

                // 2. Sblocca la visibilità
                foreach ($hiddenEvents as $evt) {
                    $meta = $evt->meta;
                    $meta['is_published'] = true;
                    
                    $evt->update([
                        'meta' => $meta,
                        'visibility' => VisibilityStatus::PRIVATE->value
                    ]);
                    
                    // Raccoglie l'ID della rata per inviare la notifica collettiva
                    if (isset($meta['context']['rata_id'])) {
                        $rataIds[] = $meta['context']['rata_id'];
                    }
                }

                // 3. Spedisce le notifiche Push/Email per ogni rata sbloccata
                $uniqueRataIds = array_unique($rataIds);
                foreach ($uniqueRataIds as $rataId) {
                    $rata = Rata::find($rataId);
                    if ($rata) {
                        RataEmessa::dispatch($rata);
                    }
                }
            });

            return back()->with($this->flashSuccess('Rate pubblicate! I condòmini ora le vedono e hanno ricevuto le notifiche.'));

        } catch (\Throwable $e) {
            Log::error("Errore pubblicazione rate silenziose: " . $e->getMessage());
            return back()->with($this->flashError('Errore durante la pubblicazione delle rate.'));
        }
    }
}