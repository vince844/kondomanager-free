<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Http\Resources\Evento\EventoResource;
use App\Http\Resources\Segnalazioni\SegnalazioneResource;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Services\RecurrenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use App\Helpers\FileHelper;

class DashboardController extends Controller
{
    public function __construct(
        private RecurrenceService $recurrenceService,
    ) {}

    public function __invoke(Request $request): Response
    {
        // Ogni blocco arriva solo a chi può aprire il suo elenco nel pannello (decisione 137), con la stessa domanda
        // dell'elenco: `view` sul modello vuoto per segnalazioni, comunicazioni e documenti (è ciò che l'elenco riceve
        // dalla rotta), `viewAny` per le scadenze. La pagina nasconde i riquadri di chi non ha il permesso, e i dati di un
        // blocco che non si apre non arrivano nemmeno alla pagina. Le cifre in testa sono conteggi e restano a tutti.
        $puo = fn (string $abilita, object|string $modello): bool => Gate::forUser($request->user())->allows($abilita, $modello);

        $stats = [
            'total_condomini'     => Condominio::count(),
            'segnalazioni_aperte' => Segnalazione::whereIn('stato', ['aperta', 'in lavorazione'])->count(),
            'scadenze_imminenti'  => $this->recurrenceService->getEventsInNextDays(days: 7)->count(),
            'storage' => [
                'used_bytes'     => Documento::sum('file_size') ?? 0,
                'used_formatted' => FileHelper::formatBytes(Documento::sum('file_size') ?? 0, 2, true),
                'total_files'    => Documento::count(),
            ],
        ];

        $blocchi = [];

        if ($puo('view', new Segnalazione())) {
            $blocchi['segnalazioni'] = SegnalazioneResource::collection(
                Segnalazione::with(['createdBy.anagrafica', 'assignedTo', 'condominio', 'anagrafiche'])
                    ->limit(3)->latest()->get()
            );
        }

        if ($puo('view', new Comunicazione())) {
            $blocchi['comunicazioni'] = ComunicazioneResource::collection(
                Comunicazione::with(['createdBy.anagrafica', 'condomini', 'anagrafiche'])
                    ->limit(3)->latest()->get()
            );
        }

        if ($puo('view', new Documento())) {
            $blocchi['documenti'] = DocumentoResource::collection(
                Documento::with(['createdBy.anagrafica', 'condomini', 'anagrafiche', 'categorie'])
                    ->whereNull('documentable_type')
                    ->latest()->limit(3)->get()
            );
        }

        if ($puo('viewAny', Evento::class)) {
            $events = $this->recurrenceService->getEventsInNextDays(
                days: 90,
                filters: ['exclude_type' => 'scadenza_rata_condomino'],
                page: (int) $request->query('page', 1),
                perPage: 10,
            );

            $blocchi['eventi'] = Inertia::scroll(EventoResource::collection($events));
        }

        return Inertia::render('dashboard/Dashboard', ['stats' => $stats] + $blocchi);
    }
}