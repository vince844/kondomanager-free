<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Enums\StatoPianoRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoRate\CreatePianoRateRequest;
use App\Http\Requests\Gestionale\PianoRate\PianoRateIndexRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\PianiRate\PianoRateResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Services\PianoRateCreatorService;
use App\Services\PianoRateQuoteService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request; // Importante
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PianoRateController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    public function __construct(
        private PianoRateQuoteService $pianoRateQuoteService,
        private PianoRateCreatorService $pianoRateCreatorService,
    ) {}

    public function index(PianoRateIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $validated = $request->validated();
        $pianiRate = PianoRate::with(['gestione'])
            ->where('condominio_id', $condominio->id)
            ->whereHas('gestione.esercizi', fn($q) => $q->where('esercizio_id', $esercizio->id))
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));
        $esercizi = $condominio->esercizi()->orderBy('data_inizio', 'desc')->get(['id', 'nome', 'stato']);

        return Inertia::render('gestionale/pianiRate/PianiRateList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'condomini'  => CondominioResource::collection($this->getCondomini()),
            'pianiRate'  => PianoRateResource::collection($pianiRate)->resolve(),
            'meta' => [
                'current_page' => $pianiRate->currentPage(),
                'last_page'    => $pianiRate->lastPage(),
                'per_page'     => $pianiRate->perPage(),
                'total'        => $pianiRate->total(),
            ],
            'filters' => $request->only(['nome']),
        ]);
    }

    public function create(Condominio $condominio, Esercizio $esercizio): Response
    {
        $condomini = $this->getCondomini();
        $esercizi = $condominio->esercizi()->orderBy('data_inizio', 'desc')->get(['id', 'nome', 'stato']);
        $gestioni = Gestione::whereHas('esercizi', fn($q) => $q->where('esercizio_id', $esercizio->id))
            ->with(['esercizi' => fn($q) => $q->where('esercizio_id', $esercizio->id)])
            ->get();

        return Inertia::render('gestionale/pianiRate/PianiRateNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'condomini'  => $condomini,
            'gestioni'   => $gestioni,
        ]);
    }

    public function store(CreatePianoRateRequest $request, Condominio $condominio, Esercizio $esercizio)
    {
        $validated = $request->validated();
        try {
            DB::beginTransaction();
            $this->pianoRateCreatorService->verificaGestione($validated['gestione_id']);
            $pianoRate = $this->pianoRateCreatorService->creaPianoRate($validated, $condominio);
            if (!empty($validated['recurrence_enabled'])) {
                $this->pianoRateCreatorService->creaRicorrenza($pianoRate, $validated);
            }
            $statistiche = [];
            if (!empty($validated['genera_subito'])) {
                $statistiche = app(GeneratePianoRateAction::class)->execute($pianoRate);
            }
            DB::commit();
            return $this->redirectSuccess($condominio, $esercizio, $pianoRate, $validated, $statistiche);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Errore creazione piano rate", ['errore' => $e->getMessage()]);
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * MODIFICATO SOLO PER AGGIUNGERE $ratePure
     */
    public function show(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): Response
    {
        $pianoRate->load([
            'rate.rateQuote.anagrafica',
            'rate.rateQuote.immobile',
        ]);

        // [AGGIUNTO] Recupero dati emissione per il modale
        $ratePure = $pianoRate->rate()
            ->orderBy('numero_rata')
            ->get()
            ->map(function($rata) {
                return [
                    'id' => $rata->id,
                    'numero_rata' => $rata->numero_rata,
                    'is_emessa' => $rata->rateQuote()->whereNotNull('scrittura_contabile_id')->exists(),
                    'totale_rata' => $rata->importo_totale / 100, // Accessor manuale se non hai modificato il model
                ];
            });

        return Inertia::render('gestionale/pianiRate/PianiRateShow', [
            'condominio'         => $condominio,
            'esercizio'          => $esercizio,
            'pianoRate'          => new PianoRateResource($pianoRate),
            'ratePure'           => $ratePure, // [AGGIUNTO]
            'quotePerAnagrafica' => $this->pianoRateQuoteService->quotePerAnagrafica($pianoRate),
            'quotePerImmobile'   => $this->pianoRateQuoteService->quotePerImmobile($pianoRate),
        ]);
    }

    /**
     * [NUOVO METODO]
     */
    public function updateStato(Request $request, Condominio $condominio, PianoRate $pianoRate)
    {
            // 1. Validazione input booleano
            $validated = $request->validate([
                'approvato' => 'required|boolean'
            ]);

            // 2. Determina il nuovo stato usando l'ENUM
            // Laravel convertirà automaticamente questo Enum nella stringa corretta per il DB
            $nuovoStato = $validated['approvato'] 
                ? StatoPianoRate::APPROVATO 
                : StatoPianoRate::BOZZA;

            // 3. Controllo di Sicurezza: Impedisci il ritorno a "Bozza" se ci sono rate emesse
            // Confrontiamo usando l'Enum
            if ($nuovoStato === StatoPianoRate::BOZZA) {
                
                // Verifica se esistono rate con una scrittura contabile collegata
                $haRateEmesse = $pianoRate->rate()
                    ->whereHas('rateQuote', function($q) {
                        $q->whereNotNull('scrittura_contabile_id');
                    })
                    ->exists();

                if ($haRateEmesse) {
                    return back()->withErrors(['error' => 'Impossibile riportare in Bozza: esistono rate già emesse in contabilità. Annulla prima le emissioni.']);
                    dd("BLOCCATO: Ci sono rate emesse, non posso tornare in bozza!");
                }
            }

            // 4. Aggiornamento
            // Grazie al cast nel Model, puoi passare direttamente l'oggetto Enum
            $pianoRate->update(['stato' => $nuovoStato]);

            // 5. Successo
            return back()->with('success', 'Stato del piano aggiornato con successo.');
    }


    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): RedirectResponse
    {
        try {
            $pianoRate->delete();
            return to_route('admin.gestionale.esercizi.piani-rate.index', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoRate' => $pianoRate->id
                ])->with($this->flashSuccess(__('gestionale.success_delete_piano_rate')));
        } catch (\Throwable $e) {
            Log::error('Error deleting piano rate', ['message' => $e->getMessage()]);
            return to_route('admin.gestionale.esercizi.piani-rate.index', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoRate' => $pianoRate->id
                ])->with($this->flashError(__('gestionale.error_delete_piano_rate')));
        }
    }

    protected function redirectSuccess(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate, array $validated, array $statistiche = []) {
        $message = $validated['genera_subito'] ? "Piano rate creato e generato!" : "Piano rate creato!";
        return redirect()->route('admin.gestionale.esercizi.piani-rate.show', [
            'condominio' => $condominio->id,
            'esercizio'  => $esercizio->id,
            'pianoRate'  => $pianoRate->id
        ])->with('success', $message);
    }
}