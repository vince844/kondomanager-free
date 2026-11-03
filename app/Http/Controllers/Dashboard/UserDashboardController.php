<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Portale\ComunicazionePortaleResource;
use App\Http\Resources\Portale\DocumentoPortaleResource;
use App\Http\Resources\Portale\EventoPortaleResource;
use App\Http\Resources\Portale\SegnalazionePortaleResource;
use App\Models\Evento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\SegnalazioneService;
use App\Services\ComunicazioneService;
use App\Services\DocumentoService;
use App\Services\RecurrenceService;
use Inertia\Response;
use App\Traits\CalculatesFinancialWaterfall;

class UserDashboardController extends Controller
{
    use CalculatesFinancialWaterfall; 
    
    public function __construct(
        private SegnalazioneService $segnalazioneService,
        private ComunicazioneService $comunicazioneService,
        private DocumentoService $documentoService,
        private RecurrenceService $recurrenceService
    ) {}
    
    public function __invoke(Request $request): Response
    {
        try {
            $user = Auth::user();

            if (!$user || !$user->anagrafica) {
                abort(403, __('auth.not_authenticated'));
            }

            $anagrafica = $user->anagrafica;
            $condominioIds = $anagrafica->condomini->pluck('id');
            
            $segnalazioni = $this->segnalazioneService->getSegnalazioni(
                anagrafica: $anagrafica,
                condominioIds: $condominioIds,
                validated: [],
                limit: 3 
            );

            $comunicazioni = $this->comunicazioneService->getComunicazioni(
                anagrafica: $anagrafica,
                condominioIds: $condominioIds,
                validated: [],
                limit: 3 
            );

            $documenti = $this->documentoService->getDocumenti(
                anagrafica: $anagrafica,
                condominioIds: $condominioIds,
                validated: [],
                limit: 3
            );

            // 1. Fetch dell'intera collection: il waterfall deve vedere tutti gli eventi. Le scadenze
            // arrivano solo a chi apre l'agenda (decisione 136.3): la stessa domanda della sua pagina,
            // `EventoPolicy::viewAny`; senza, la dashboard non le mostra.
            $eventiCollection = Gate::forUser($user)->allows('viewAny', Evento::class)
                ? $this->recurrenceService->getEventsInNextDays(
                    days: 90, // ampliato come per la dashboard admin
                    anagrafica: $anagrafica,
                    condominioIds: $condominioIds
                )
                : collect();

            // 2. Waterfall applicato sull'intera collection (invariato)
            $eventiProcessati = $this->applyFinancialWaterfall(
                $eventiCollection,
                $anagrafica->id
            );

            // 3. Paginazione manuale del risultato processato
            $perPage = 10;
            $page = (int) $request->query('page', 1);
            $total = $eventiProcessati->count();

            $eventiPaginati = new \Illuminate\Pagination\LengthAwarePaginator(
                $eventiProcessati->forPage($page, $perPage)->values(),
                $total,
                $perPage,
                $page,
                [
                    'path' => \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPath(),
                    'query' => $request->query(),
                ]
            );

        } catch (\Exception $e) {
            Log::error('Error getting dashboard widgets: ' . $e->getMessage());
            abort(500, 'Unable to fetch reports.');
        }

        // Coda 260: al portale le risorse minime (autore e condominio con id e nome, nessun dato di
        // contatto di altri, nessuna nota del condominio).
        return Inertia::render('dashboard/UserDashboard', [
            'segnalazioni'  => SegnalazionePortaleResource::collection($segnalazioni),
            'comunicazioni' => ComunicazionePortaleResource::collection($comunicazioni),
            'documenti'     => DocumentoPortaleResource::collection($documenti),
            'eventi'        => Inertia::scroll(EventoPortaleResource::collection($eventiPaginati)),
        ]);
    }
}