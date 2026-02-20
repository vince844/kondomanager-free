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
use App\Models\Segnalazione;
use App\Services\RecurrenceService;
use Illuminate\Http\Request;
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
        // MODIFICA QUI: Aggiungiamo il filtro per escludere le rate condòmini
        $events = $this->recurrenceService->getEventsInNextDays(
            days: 30,
            filters: [
                'exclude_type' => 'scadenza_rata_condomino'
            ]
        )->take(3);
        
        $stats = [
            'total_condomini'     => Condominio::count(),
            'segnalazioni_aperte' => Segnalazione::whereIn('stato', ['aperta', 'in lavorazione'])->count(),
            'scadenze_imminenti'  => $this->recurrenceService->getEventsInNextDays(days: 7)->count(),
            'storage' => [
                'used_bytes'     => Documento::sum('file_size') ?? 0,
                'used_formatted' => FileHelper::formatBytes(Documento::sum('file_size') ?? 0),
                'total_files'    => Documento::count(),
            ],
        ];

        return Inertia::render('dashboard/Dashboard', [
            'stats' => $stats,
            'segnalazioni'  => SegnalazioneResource::collection(
                Segnalazione::with(['createdBy.anagrafica', 'assignedTo', 'condominio', 'anagrafiche'])
                    ->limit(3)->latest()->get()
            ),
            'comunicazioni' => ComunicazioneResource::collection(
                Comunicazione::with(['createdBy.anagrafica', 'condomini', 'anagrafiche'])
                    ->limit(3)->latest()->get()
            ),
            'documenti' => DocumentoResource::collection(
                Documento::with(['createdBy.anagrafica', 'condomini', 'anagrafiche', 'categoria'])
                    ->whereNull('documentable_type')
                    ->latest()->limit(3)->get()
            ),
            'eventi' => EventoResource::collection($events),
        ]);
    }
}