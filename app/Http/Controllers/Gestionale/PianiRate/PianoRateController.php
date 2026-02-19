<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Enums\StatoPianoRate;
use App\Events\Gestionale\PianoRateStatusUpdated;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoRate\CreatePianoRateRequest;
use App\Http\Requests\Gestionale\PianoRate\PianoRateIndexRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\PianiRate\PianoRateResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Services\Gestionale\SaldoEsercizioService;
use App\Services\PianoRateCreatorService;
use App\Services\PianoRateQuoteService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PianoRateController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    public function __construct(
        private readonly PianoRateQuoteService $pianoRateQuoteService,
        private readonly PianoRateCreatorService $pianoRateCreatorService,
        private readonly SaldoEsercizioService $saldoService,
    ) {}

    public function index(PianoRateIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $validated = $request->validated();
        
        $pianiRate = PianoRate::with(['gestione'])
            ->where('condominio_id', $condominio->id)
            ->whereHas('gestione.esercizi', fn($q) => $q->where('esercizio_id', $esercizio->id))
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        return Inertia::render('gestionale/pianiRate/PianiRateList', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'esercizi' => $esercizi,
            'condomini' => CondominioResource::collection($this->getCondomini()),
            'pianiRate' => PianoRateResource::collection($pianiRate)->resolve(),
            'meta' => [
                'current_page' => $pianiRate->currentPage(),
                'last_page' => $pianiRate->lastPage(),
                'per_page' => $pianiRate->perPage(),
                'total' => $pianiRate->total()
            ],
            'filters' => $request->only(['nome']),
        ]);
    }

    public function create(Condominio $condominio, Esercizio $esercizio): Response
    {
        $condomini = $this->getCondomini();
        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $gestioni = Gestione::whereHas('esercizi', fn($q) => $q->where('esercizio_id', $esercizio->id))
            ->with(['esercizi' => fn($q) => $q->where('esercizio_id', $esercizio->id)])
            ->get();

        $saldoInfo = $this->saldoService->calcolaSaldoApplicabile($condominio, $esercizio, null);

        return Inertia::render('gestionale/pianiRate/PianiRateNew', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'esercizi' => $esercizi,
            'condomini' => $condomini,
            'gestioni' => $gestioni,
            'saldoInfo' => $saldoInfo,
        ]);
    }

    public function store(CreatePianoRateRequest $request, Condominio $condominio, Esercizio $esercizio)
    {
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            // 1. Validazione Gestione
            $gestione = Gestione::findOrFail($validated['gestione_id']);
            $this->pianoRateCreatorService->verificaGestione($validated['gestione_id']);

            // 2. Analisi Saldi
            $saldoInfo = $this->saldoService->calcolaSaldoApplicabile($condominio, $esercizio, null);
            $haMovimenti = $saldoInfo['has_movimenti'] ?? false;
            
            if (!$haMovimenti && $saldoInfo['saldo'] == 0) {
                $esisteManuale = DB::table('saldi')
                    ->where('condominio_id', $condominio->id)
                    ->where('esercizio_id', $esercizio->id)
                    ->where('saldo_iniziale', '!=', 0)
                    ->exists();
                if ($esisteManuale) $haMovimenti = true;
            }

            $applicareSaldi = ($saldoInfo['applicabile'] && $haMovimenti);

            // 3. Creazione Core del Piano
            $pianoRate = $this->pianoRateCreatorService->creaPianoRate($validated, $condominio);

            // --- HELPER: Calcola il vero totale sommando i sottoconti ---
            $calcolaVeroTotale = function ($conto) use (&$calcolaVeroTotale) {
                if ($conto->relationLoaded('sottoconti') && $conto->sottoconti->isNotEmpty()) {
                    return $conto->sottoconti->sum(fn($sub) => $calcolaVeroTotale($sub));
                }
                return $conto->importo ?? 0;
            };

            // 4. Gestione Capitoli e Sync
            $capitoliConfig = $validated['capitoli_config'] ?? [];
            $syncData = [];

            if (!empty($capitoliConfig)) {
                // CASO A: Wizard manuale
                foreach ($capitoliConfig as $conf) {
                    $importoCents = (isset($conf['importo']) && $conf['importo'] !== '') 
                        ? MoneyHelper::toCents($conf['importo']) 
                        : null;
                    
                    $syncData[$conf['id']] = ['importo' => $importoCents, 'note' => $conf['note'] ?? null];
                }
            } elseif (!empty($validated['capitoli_ids'])) {
                // CASO B: Selezione rapida
                // FIX: Aggiungiamo 'with' per caricare i sottoconti e calcolare il totale
                $conti = Conto::with('sottoconti')->findMany($validated['capitoli_ids']);
                foreach ($conti as $c) {
                    $syncData[$c->id] = [
                        'importo' => $calcolaVeroTotale($c), // <--- Mettiamo il totale reale!
                        'note' => 'Selezione rapida (Intero)'
                    ];
                }
            } else {
                // CASO C: Inclusione automatica di tutto il bilancio
                $capitoliOrfani = $gestione->pianoConto->conti()
                    ->whereNull('parent_id')
                    ->whereDoesntHave('pianiRate', fn($q) => $q->where('attivo', true))
                    ->with('sottoconti') // <--- IMPORTANTE: per calcolare la somma
                    ->get();
                
                foreach ($capitoliOrfani as $c) {
                    $syncData[$c->id] = [
                        'importo' => $calcolaVeroTotale($c), // <--- Mettiamo il totale reale!
                        'note' => 'Inclusione automatica (Tutto il bilancio)'
                    ];
                }
            }
            
            $pianoRate->capitoli()->sync($syncData);
            $pianoRate->load('capitoli');

            // 5. Ricorrenza
            if (!empty($validated['recurrence_enabled'])) {
                $this->pianoRateCreatorService->creaRicorrenza($pianoRate, $validated);
            }

            // 6. Applicazione Saldi
            if ($applicareSaldi) {
                $this->saldoService->marcaSaldoApplicato($gestione, $saldoInfo['saldo']);
                $gestione->refresh();
                $pianoRate->setRelation('gestione', $gestione);
            }

            // 7. Generazione Rate
            $statistiche = [];
            if (!empty($validated['genera_subito'])) {
                $statistiche = app(GeneratePianoRateAction::class)->execute($pianoRate, $applicareSaldi);
            }

            DB::commit();
            return $this->redirectSuccess($condominio, $esercizio, $pianoRate, $validated, $statistiche);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Errore store piano rate", ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with($this->flashError($e->getMessage()));
        }
    }
    
    public function show(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): Response
    {
        $pianoRate->load([
            'rate.rateQuote.anagrafica', 
            'rate.rateQuote.immobile', 
            'gestione.pianoConto', 
            'capitoli.sottoconti',
            'budgetMovements.sourceConto',
            'budgetMovements.destinationConto',
            'budgetMovements.user'
        ]);
        
        // --- LOGICA ORFANI / AUDIT ---
        $orfani = [];
        if ($pianoRate->gestione && $pianoRate->gestione->pianoConto) {
            $orfaniRaw = $pianoRate->gestione->pianoConto->conti()
                ->whereNull('parent_id')
                ->whereDoesntHave('pianiRate', fn($q) => $q->where('piani_rate.attivo', true))
                ->where('id', '!=', $pianoRate->capitoli->pluck('id')->toArray()) 
                ->get();
            
            $orfani = $orfaniRaw->map(fn($c) => [
                'id' => $c->id, 
                'nome' => $c->nome, 
                'importo' => $c->importo
            ])->values()->toArray();
        }
        
        $coperturaData = [
            'scoperto_count' => count($orfani), 
            'orfani' => $orfani
        ];
        
        // --- LOGICA RATE PURE (Timeline) ---
        $ratePure = $pianoRate->rate()
            ->orderBy('numero_rata')
            ->get()
            ->map(fn($rata) => [
                'id' => $rata->id, 
                'numero_rata' => $rata->numero_rata, 
                'is_emessa' => $rata->rateQuote()->whereNotNull('scrittura_contabile_id')->exists(), 
                'totale_rata' => MoneyHelper::fromCents($rata->importo_totale)
            ]);

        // --- NEW: LOGICA SPOSTA SPESA (V 1.10) ---
        
        // 1. Sources: Voci nel piano con residuo > 0
        $sources = $pianoRate->capitoli->map(function ($conto) {
            $importoReale = $conto->pivot->importo ?? $conto->importo; 
            return [
                'id' => $conto->id,
                'nome' => $conto->nome,
                'importo_residuo' => $importoReale,
                'formatted_residuo' => number_format($importoReale / 100, 2, ',', '.')
            ];
        });

        // Destinations (CORRETTO PER LA TUA STRUTTURA)
        $destinations = [];
        
        // Recuperiamo l'ID dal modello collegato tramite hasOne
        // Non usiamo $gestione->piano_conto_id ma $gestione->pianoConto->id
        $pianoContoId = $pianoRate->gestione->pianoConto?->id;

        if ($pianoContoId) {
            $destinations = Conto::where('piano_conto_id', $pianoContoId) // Usiamo l'ID trovato
                // ->where('tipo', '!=', 'capitolo') // Scommenta se vuoi filtrare i capitoli padre
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(fn($c) => [
                    'id' => $c->id,
                    'nome' => $c->nome,
                ]);
        } else {
            // Debug silenzioso nel log se qualcosa non va
            Log::warning("Sposta Spesa: Nessun Piano Conto trovato per la gestione {$pianoRate->gestione_id}");
        }

        return Inertia::render('gestionale/pianiRate/PianiRateShow', [
            'condominio' => $condominio, 
            'esercizio' => $esercizio, 
            'pianoRate' => new PianoRateResource($pianoRate),
            'ratePure' => $ratePure, 
            'quotePerAnagrafica' => $this->pianoRateQuoteService->quotePerAnagrafica($pianoRate),
            'quotePerImmobile' => $this->pianoRateQuoteService->quotePerImmobile($pianoRate),
            'needsMigration' => false, 
            'copertura' => $coperturaData,
            'sources' => $sources,
            'destinations' => $destinations
        ]);
    }

    public function updateStato(Request $request, Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate)
    {
        $validated = $request->validate([ 'approvato' => 'required|boolean' ]);
        
        $vecchioStato = $pianoRate->stato;
        $nuovoStato = $validated['approvato'] ? StatoPianoRate::APPROVATO : StatoPianoRate::BOZZA;
        
        $pianoRate->update(['stato' => $nuovoStato]);
        
        PianoRateStatusUpdated::dispatch(
            $condominio, 
            $esercizio, 
            $pianoRate, 
            Auth::user(), 
            $vecchioStato, 
            $nuovoStato
        );
        
        return back()->with($this->flashSuccess('Stato aggiornato con successo.'));
    }

    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): RedirectResponse
    {
        try {
            $pianoRate->delete();
            return to_route('admin.gestionale.esercizi.piani-rate.index', [
                'condominio' => $condominio->id, 
                'esercizio' => $esercizio->id
            ])->with($this->flashSuccess(__('gestionale.success_delete_piano_rate')));
        } catch (\Throwable $e) {
            return to_route('admin.gestionale.esercizi.piani-rate.index', [
                'condominio' => $condominio->id, 
                'esercizio' => $esercizio->id
            ])->with($this->flashError(__('gestionale.error_delete_piano_rate')));
        }
    }

    public function detachCapitolo(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate, $capitoloId)
    {
        // 1. Controlli Incassi (Invariato)
        if ($pianoRate->rate()->whereHas('rateQuote', fn($q) => $q->where('importo_pagato', '>', 0))->exists()) {
            return back()->with($this->flashError("Impossibile modificare: ci sono incassi registrati."));
        }
        
        // 2. Controlli Emissioni (Invariato)
        if ($pianoRate->rate()->whereHas('rateQuote', fn($q) => $q->whereNotNull('scrittura_contabile_id'))->exists()) {
            return back()->with($this->flashError("Annulla le emissioni prima di modificare le voci."));
        }

        // --- 3. IL GUARDIANO GLOBALE (FIX DEFINITIVO) ---
        // Controlliamo se la voce è coinvolta in QUALSIASI movimento, 
        // indipendentemente da quale piano rate ha originato l'azione.
        
        $isInvolved = \App\Models\Gestionale\BudgetMovement::query()
            ->where(function ($query) use ($capitoloId) {
                $query->where('source_conto_id', $capitoloId)
                      ->orWhere('destination_conto_id', $capitoloId);
            })
            ->exists();

        if ($isInvolved) {
            return back()->with($this->flashError(
                "Impossibile rimuovere: questa voce è vincolata da movimenti di budget (anche da altri piani rate). " .
                "Devi prima annullare i movimenti o restituire i fondi, poi potrai cancellarla."
            ));
        }
        // ---------------------------------------------------

        try {
            DB::beginTransaction();
            
            $pianoRate->capitoli()->detach($capitoloId);
            $pianoRate->rate()->delete();
            
            app(GeneratePianoRateAction::class)->execute($pianoRate, null); 
            
            DB::commit();
            return back()->with($this->flashSuccess("Voce rimossa e ricalcolata."));
            
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with($this->flashError("Errore durante la rimozione: " . $e->getMessage()));
        }
    }

    protected function redirectSuccess(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate, array $validated, array $statistiche = []) 
    {
        $message = !empty($validated['genera_subito']) 
            ? "Piano rate creato e generato con successo!" 
            : "Piano rate creato con successo!";
            
        return redirect()->route('admin.gestionale.esercizi.piani-rate.show', [
            'condominio' => $condominio->id, 
            'esercizio' => $esercizio->id, 
            'pianoRate' => $pianoRate->id
        ])->with('success', $message);
    }
}