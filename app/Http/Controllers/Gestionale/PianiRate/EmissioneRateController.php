<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\RigaScrittura;
use App\Enums\StatoPianoRate;
use App\Enums\VisibilityStatus;
use App\Events\Gestionale\RataEmessa;
use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Services\Gestionale\CreditoService;
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
                    } else {
                        // Nessuna quota da emettere (es. rata di soli conguagli a credito):
                        // la testata resterebbe una scrittura SENZA RIGHE, che il
                        // DoubleEntryValidator approva (0 = 0) ma che sporca il giornale e
                        // brucia un numero di protocollo. Peggio: nessuna quota riceve
                        // scrittura_contabile_id, quindi il guard anti-doppia-emissione non
                        // scatta e ogni nuova emissione ne accumula un'altra.
                        $scrittura->forceDelete();
                        continue;
                    }

                    // Quadratura dell'emissione: la somma delle quote a DARE deve
                    // corrispondere esattamente alla chiusura in AVERE. Un arrotondamento
                    // sbagliato sulle quote qui viene intercettato subito, invece di
                    // propagarsi a tutte le rate del piano e comparire nel rendiconto.
                    DoubleEntryValidator::validateOrFail($scrittura->id);

                    // 4. Gestione Eventi Condòmini (Rendiamo la query robusta)
                    $rataId = (int) $rata->id;
                    $userEvents = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                        ->where(function($q) use ($rataId) {
                            $q->where('meta->context->rata_id', $rataId)
                            ->orWhere('meta->context->rata_id', (string) $rataId);
                        })
                        ->get();

                    foreach ($userEvents as $evt) {
                        $meta = $evt->meta;
                        $meta['is_emitted'] = true;
                        $meta['is_published'] = $inviaNotifiche; 
                        
                        $evt->update([
                            'meta' => $meta,
                            'visibility' => $inviaNotifiche ? VisibilityStatus::PRIVATE->value : VisibilityStatus::HIDDEN->value
                        ]);
                    }

                    // 5. Invio Notifiche
                    if ($inviaNotifiche) {
                        RataEmessa::dispatch($rata);
                    }

                    // 6. Pulizia Task Admin (CORRETTO: usiamo where standard per i path JSON)
                    Evento::where('tipo', EventoTipo::EMISSIONE_RATA->value)
                        ->where(function($q) use ($rataId) {
                            $q->where('meta->context->rata_id', $rataId)
                            ->orWhere('meta->context->rata_id', (string) $rataId);
                        })
                        ->delete();

                }

                // Aggiorniamo lo stato massivo nel DB
                Rata::whereIn('id', $request->rate_ids)->update(['stato' => 'emessa']);
            });

            InboxService::clearAdminCache();

            $msg = $inviaNotifiche
                ? 'Rate emesse e notificate correttamente ai condòmini.'
                : 'Rate emesse in modalità silenziosa. I condòmini non vedranno gli importi finché non li pubblicherai.';

            // Proposta compensazione: se qualche intestatario delle rate appena
            // emesse ha un credito disponibile (saldo a credito o strapagamento),
            // lo segnaliamo così l'amministratore può compensare subito.
            $suggerimentoCrediti = $this->buildSuggerimentoCrediti($condominio, $request->rate_ids);

            $risposta = back()->with($this->flashSuccess($msg));

            // In una chiave propria, non accodato a $msg: il banner di `flash.message` viene
            // dipinto e poi cancellato dal modale di conferma dell'emissione, quindi un
            // suggerimento scritto lì non fa in tempo a essere letto. Da qui lo raccoglie il
            // modale, che resta finché non lo si chiude ed è dove l'amministratore guarda.
            return $suggerimentoCrediti
                ? $risposta->with('suggerimento_crediti', $suggerimentoCrediti)
                : $risposta;

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
     * Verifica se gli intestatari delle rate appena emesse hanno crediti
     * disponibili (saldi a credito non consumati o quote strapagate) e
     * costruisce il testo del suggerimento di compensazione da accodare
     * al messaggio flash. Ritorna null se nessuno ha credito.
     */
    private function buildSuggerimentoCrediti(Condominio $condominio, array $rateIds): ?string
    {
        $anagraficheIds = RataQuote::whereIn('rata_id', $rateIds)
            ->whereNotNull('anagrafica_id')
            ->pluck('anagrafica_id')
            ->unique();

        if ($anagraficheIds->isEmpty()) {
            return null;
        }

        $crediti = app(CreditoService::class)->perCondominio($condominio->id, $anagraficheIds->all());

        if ($crediti->isEmpty()) {
            return null;
        }

        // Contano solo quelli il cui credito copre DAVVERO qualcosa: segnalare un credito
        // che non ha niente da compensare manda l'amministratore su una pagina dove non c'è
        // nulla da fare, che è il difetto che questa versione sta chiudendo.
        $compensabili = $crediti->filter(fn($c) => $c['compensabile']['importo_cents'] > 0)->values();

        if ($compensabili->isEmpty()) {
            return null;
        }

        // Con un solo condòmino si può essere precisi: si dice quale rata copre.
        if ($compensabili->count() === 1) {
            $c = $compensabili->first();

            return 'Nota: ' . $c['nome'] . ' ha ' . MoneyHelper::format($c['compensabile']['importo_cents'])
                . ' di credito spendibile subito. ' . $c['compensabile']['frase']
                . ' Lo compensi da "Nuovo incasso".';
        }

        $elenco = $compensabili->take(3)
            ->map(fn($c) => $c['nome'] . ' (' . MoneyHelper::format($c['compensabile']['importo_cents']) . ')')
            ->join(', ');

        if ($compensabili->count() > 3) {
            $elenco .= ' e altri ' . ($compensabili->count() - 3);
        }

        return 'Nota: ' . $compensabili->count() . ' condòmini hanno un credito che copre rate già aperte — '
            . $elenco . '. Li compensi da "Nuovo incasso".';
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
                
                // 1. Sgancio e rimozione Scritture (Perfetto, non toccato)
                $scrittureIds = $rata->rateQuote()->pluck('scrittura_contabile_id')->filter()->unique();
                $rata->rateQuote()->update(['scrittura_contabile_id' => null]);

                if ($scrittureIds->isNotEmpty()) {
                    RigaScrittura::whereIn('scrittura_id', $scrittureIds)->delete();
                    ScritturaContabile::whereIn('id', $scrittureIds)->forceDelete(); 
                }

                // Riportiamo la rata in stato di bozza
                $rata->update(['stato' => 'bozza']);

                $rataId = (int) $rata->id; // Cast sicuro

                // 2. Ripristino Eventi Utente (Query Robusta Applicata)
                $userEvents = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                    ->where(function($q) use ($rataId) {
                        $q->where('meta->context->rata_id', $rataId)
                          ->orWhere('meta->context->rata_id', (string) $rataId);
                    })
                    ->get();

                foreach ($userEvents as $evt) {
                    $meta = $evt->meta;
                    $meta['is_emitted'] = false; 
                    $meta['is_published'] = false; 
                    
                    $evt->update([
                        'meta' => $meta,
                        'visibility' => VisibilityStatus::PRIVATE->value 
                    ]);
                }
                
                // 3. Rigenerazione Task Admin tramite Builder
                $dataPromemoria = $rata->data_scadenza->copy()->subDays(7)->setTime(9, 0);
                
                $eventoAdmin = InboxService::createTask(
                    tipo: EventoTipo::EMISSIONE_RATA,
                    title: "Emettere rata {$rata->numero_rata} - {$condominio->nome}",
                    description: "Ricordati di emettere le ricevute per questa rata entro la scadenza. (Riemissione dopo annullamento)",
                    scadenza: $dataPromemoria,
                    createdByUserId: $request->user()->id,
                    condominioId: $condominio->id,
                    context: [
                        'piano_rate_id' => $pianoRate->id,
                        'rata_id'       => $rataId 
                    ],
                    actionUrl: route('admin.gestionale.esercizi.piani-rate.show', [
                        'condominio' => $condominio->id,
                        'esercizio'  => $esercizio->id, 
                        'pianoRate'  => $pianoRate->id
                    ]),
                    extraMeta: [
                        'gestione'          => $pianoRate->gestione->nome ?? 'Gestione',
                        'condominio_nome'   => $condominio->nome,
                        'totale_rata'       => $rata->importo_totale,
                        'anagrafiche_count' => $rata->rateQuote->unique('anagrafica_id')->count(),
                        'scadenza_reale'    => $rata->data_scadenza->toDateString(),
                        'numero_rata'       => $rata->numero_rata,
                        'piano_nome'        => $pianoRate->nome,
                    ]
                );
                
                $eventoAdmin->condomini()->syncWithoutDetaching([$condominio->id]);
                if ($request->user()->anagrafica_id) {
                    $eventoAdmin->anagrafiche()->syncWithoutDetaching([$request->user()->anagrafica_id]);
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
            $idPiano = (int) $pianoRate->id;

            // 1. Trova gli eventi usando la ricerca robusta per ID Piano
            $hiddenEvents = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)
                ->where(function($q) use ($idPiano) {
                    $q->where('meta->context->piano_rate_id', $idPiano)
                      ->orWhere('meta->context->piano_rate_id', (string) $idPiano);
                })
                ->where('visibility', VisibilityStatus::HIDDEN->value)
                ->get();

            if ($hiddenEvents->isEmpty()) {
                return back()->with($this->flashWarning('Nessuna rata nascosta trovata.'));
            }

            DB::transaction(function () use ($hiddenEvents) {
                $rataIds = [];

                foreach ($hiddenEvents as $evt) {
                    $meta = $evt->meta;
                    $meta['is_published'] = true; 
                    $meta['is_emitted'] = true; // Assicuriamoci che ci sia!
                    
                    // RETE DI SICUREZZA: Controlliamo se nel frattempo l'admin l'ha incassata
                    if (isset($meta['context']['rata_id'])) {
                        $rataId = $meta['context']['rata_id'];
                        $rataIds[] = $rataId;
                        
                        // FIX: Recuperiamo l'ID di Marta (o del condomino a cui appartiene l'evento)
                        $paganteId = $evt->anagrafiche->first()->id ?? null;
                        
                        if ($paganteId) {
                            // Ora sommiamo SOLO i soldi di questo specifico condomino
                            $importoPagato = DB::table('rate_quote')
                                ->where('rata_id', $rataId)
                                ->where('anagrafica_id', $paganteId) 
                                ->sum('importo_pagato');
                                
                            $importoTotale = DB::table('rate_quote')
                                ->where('rata_id', $rataId)
                                ->where('anagrafica_id', $paganteId) 
                                ->sum('importo');

                            if ($importoPagato > 0 && $importoPagato >= $importoTotale) {
                                $meta['status'] = 'paid'; 
                            } elseif ($importoPagato > 0) {
                                $meta['status'] = 'partial'; 
                            }
                            
                            $meta['importo_pagato'] = $importoPagato;
                            $meta['importo_restante'] = max(0, $importoTotale - $importoPagato);
                        }
                    }

                    $evt->update([
                        'meta' => $meta,
                        'visibility' => VisibilityStatus::PRIVATE->value 
                    ]);
                }

                // 2. Notifiche (Dispatch una sola volta per rata id)
                foreach (array_unique($rataIds) as $rId) {
                    $rata = Rata::find($rId);
                    if ($rata) RataEmessa::dispatch($rata);
                }
            });

            return back()->with($this->flashSuccess('Rate pubblicate! I condòmini ora le vedono.'));

        } catch (\Throwable $e) {
            Log::error("Errore sblocco rate: " . $e->getMessage());
            return back()->with($this->flashError('Errore durante la pubblicazione.'));
        }
    }
}