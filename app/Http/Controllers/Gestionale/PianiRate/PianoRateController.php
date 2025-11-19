<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Actions\PianoRate\GeneratePianoRateAction;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PianoRateController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    /**
     * PianoRateController constructor.
     *
     * Initializes services used for:
     * - grouping quotes (PianoRateQuoteService)
     * - creating plans & recurrence (PianoRateCreatorService)
     *
     * @param  PianoRateQuoteService   $pianoRateQuoteService
     * @param  PianoRateCreatorService $pianoRateCreatorService
     * 
     * @since 1.7.0
     */
    public function __construct(
        private PianoRateQuoteService $pianoRateQuoteService,
        private PianoRateCreatorService $pianoRateCreatorService,
    ) {}

    /**
     * Display a paginated list of rate plans for a given condominium and exercise.
     *
     * Includes:
     * - Rate plans with their associated 'gestione'
     * - Available exercises for switching
     * - List of all condomini for navigation
     *
     * @param  PianoRateIndexRequest $request
     * @param  Condominio            $condominio
     * @param  Esercizio             $esercizio
     * @return Response
     * 
     * @since 1.7.0
     */
    public function index(PianoRateIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $validated = $request->validated();

        $pianiRate = PianoRate::with(['gestione'])
            ->where('condominio_id', $condominio->id)
            ->whereHas('gestione.esercizi', fn($q) => 
                $q->where('esercizio_id', $esercizio->id)
            )
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

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

    /**
     * Render the view for creating a new rate plan.
     *
     * Provides:
     * - Available exercises for navigation
     * - Available gestioni linked to the current esercizio
     * - List of condomini available to the user
     *
     * @param  Condominio $condominio
     * @param  Esercizio  $esercizio
     * @return Response
     * 
     * @since 1.7.0
     */
    public function create(Condominio $condominio, Esercizio $esercizio): Response
    {
        $condomini = $this->getCondomini();

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $gestioni = Gestione::whereHas('esercizi', fn($q) =>
                $q->where('esercizio_id', $esercizio->id)
            )
            ->with(['esercizi' => fn($q) =>
                $q->where('esercizio_id', $esercizio->id)
            ])
            ->get();

        return Inertia::render('gestionale/pianiRate/PianiRateNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'condomini'  => $condomini,
            'gestioni'   => $gestioni,
        ]);
    }

    /**
     * Store a newly created rate plan.
     *
     * Workflow:
     * - Validate Gestione through the creator service
     * - Create the PianoRate record
     * - Optionally create recurrence (RRULE)
     * - Optionally generate all Rata and RataQuote (PianoRateGenerator)
     * - Wrap everything in a database transaction
     *
     * On success:
     * - Redirects to the "show" page with success message
     *
     * On failure:
     * - Rolls back transaction
     * - Logs the error and returns back with the message
     *
     * @param  CreatePianoRateRequest $request
     * @param  Condominio             $condominio
     * @param  Esercizio              $esercizio
     * @return \Illuminate\Http\RedirectResponse
     * 
     * @since 1.7.0
     */
    public function store(CreatePianoRateRequest $request, Condominio $condominio, Esercizio $esercizio)
    {
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $this->pianoRateCreatorService->verificaGestione($validated['gestione_id']);

            $pianoRate = $this->pianoRateCreatorService->creaPianoRate($validated, $condominio);

            // create recurrence if enabled
            if (!empty($validated['recurrence_enabled'])) {
                $this->pianoRateCreatorService->creaRicorrenza($pianoRate, $validated);
            }

            $statistiche = [];

            // generate rate plan immediately if requested
            if (!empty($validated['genera_subito'])) {
                $statistiche = app(GeneratePianoRateAction::class)->execute($pianoRate);
            }

            DB::commit();

            return $this->redirectSuccess($condominio, $esercizio, $pianoRate, $validated, $statistiche);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error("Errore creazione piano rate", [
                'condominio_id' => $condominio->id,
                'esercizio_id'  => $esercizio->id,
                'errore'        => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Display the details of a specific rate plan, including:
     * - Plan metadata
     * - Rate list with quotes
     * - Quotes grouped by Anagrafica
     * - Quotes grouped by Immobile
     *
     * @param  Condominio $condominio
     * @param  Esercizio  $esercizio
     * @param  PianoRate  $pianoRate
     * @return Response
     * 
     * @since 1.7.0
     */
    public function show(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): Response
    {
        $pianoRate->load([
            'rate.rateQuote.anagrafica',
            'rate.rateQuote.immobile',
        ]);

        return Inertia::render('gestionale/pianiRate/PianiRateShow', [
            'condominio'         => $condominio,
            'esercizio'          => $esercizio,
            'pianoRate'          => new PianoRateResource($pianoRate),
            'quotePerAnagrafica' => $this->pianoRateQuoteService->quotePerAnagrafica($pianoRate),
            'quotePerImmobile'   => $this->pianoRateQuoteService->quotePerImmobile($pianoRate),
        ]);
    }

    /**
     * Remove the specified payment plan from storage
     *
     * Permanently deletes a PianoRate record and handles any exceptions that may occur
     * during the deletion process. Logs detailed error information for debugging.
     *
     * @param Condominio $condominio The condominium context
     * @param Esercizio $esercizio The financial exercise context  
     * @param PianoRate $pianoRate The payment plan model to delete
     * @return RedirectResponse Redirects to payment plans index with status message
     * 
     * @throws \Throwable Captures and logs any unexpected errors during deletion
     * 
     * @uses \Illuminate\Support\Facades\Log For error logging
     * 
     * @since 1.7.0
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): RedirectResponse
    {
        try {

            $pianoRate->delete();

            return to_route('admin.gestionale.esercizi.piani-rate.index', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoRate' => $pianoRate->id
                ])
                ->with($this->flashSuccess(__('gestionale.success_delete_piano_rate')));
                
        } catch (\Throwable $e) {

            Log::error('Error deleting piano rate', [
                'condominio_id'  => $condominio->id,
                'esercizio_id'   => $esercizio->id,
                'piano_rate_id' => $pianoRate->id,
                'message'        => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.piani-rate.index', [
                    'condominio' => $condominio->id,
                    'esercizio'  => $esercizio->id,
                    'pianoRate' => $pianoRate->id
                ])
                ->with($this->flashError(__('gestionale.error_delete_piano_rate')));
        }
    }

    /**
     * Redirect helper used after successfully creating a rate plan.
     *
     * Includes dynamic success message depending on whether
     * rate generation was requested.
     *
     * @param  Condominio $condominio
     * @param  Esercizio  $esercizio
     * @param  PianoRate  $pianoRate
     * @param  array      $validated
     * @param  array      $statistiche
     * @return \Illuminate\Http\RedirectResponse
     * 
     * @since 1.7.0
     */
    protected function redirectSuccess(
        Condominio $condominio,
        Esercizio $esercizio,
        PianoRate $pianoRate,
        array $validated,
        array $statistiche = []
    ) {
        $message = $validated['genera_subito']
            ? "Piano rate creato e generato con successo! Rate create: {$statistiche['rate_create']}, Quote create: {$statistiche['quote_create']}"
            : "Piano rate creato con successo! Genera le rate quando sei pronto.";

        return redirect()
            ->route('admin.gestionale.esercizi.piani-rate.show', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
                'pianoRate'  => $pianoRate->id
            ])
            ->with('success', $message);
    }
}
