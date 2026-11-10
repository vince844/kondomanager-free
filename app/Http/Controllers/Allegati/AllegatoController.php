<?php

namespace App\Http\Controllers\Allegati;

use App\Http\Controllers\Controller;
use App\Http\Requests\Allegati\AllegatiIndexRequest;
use App\Http\Requests\Allegati\ImpostazioniAllegatiRequest;
use App\Http\Requests\Allegati\StoreAllegatiRequest;
use App\Http\Requests\Allegati\UpdateAllegatoRequest;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Services\Allegati\AllegatoService;
use App\Services\Documenti\ArchivioDocumenti;
use App\Settings\GeneralSettings;
use App\Support\Allegati\RegoleAllegati;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AllegatoController extends Controller
{
    use HandleFlashMessages;

    public function __construct(
        private readonly AllegatoService $allegatoService,
        private readonly ArchivioDocumenti $archivio,
    ) {}

    public function storeSegnalazione(StoreAllegatiRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::allowIf(RegoleAllegati::puoCaricare($request->user(), $segnalazione));

        $this->allegatoService->carica(
            $request->user(),
            $segnalazione,
            (array) $request->file('allegati', [])
        );

        return back()->with($this->flashSuccess('Allegati caricati.'));
    }

    public function storeComunicazione(StoreAllegatiRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::allowIf(RegoleAllegati::puoCaricare($request->user(), $comunicazione));

        $this->allegatoService->carica(
            $request->user(),
            $comunicazione,
            (array) $request->file('allegati', [])
        );

        return back()->with($this->flashSuccess('Allegati caricati.'));
    }

    public function apri(Request $request, Documento $allegato): StreamedResponse
    {
        $elemento = RegoleAllegati::elementoDellAllegato($allegato);
        abort_if($elemento === null, 404);
        Gate::allowIf(RegoleAllegati::puoVedere($request->user(), $allegato, $elemento));
        abort_unless($this->archivio->esiste($allegato->path), 404);

        return $this->archivio->apri(
            $allegato->path,
            $allegato->nomeDiScaricamento(),
            (string) $allegato->mime_type
        );
    }

    public function scarica(Request $request, Documento $allegato): StreamedResponse
    {
        $elemento = RegoleAllegati::elementoDellAllegato($allegato);
        abort_if($elemento === null, 404);
        Gate::allowIf(RegoleAllegati::puoVedere($request->user(), $allegato, $elemento));
        abort_unless($this->archivio->esiste($allegato->path), 404);

        return $this->archivio->scarica(
            $allegato->path,
            $allegato->nomeDiScaricamento(),
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    public function update(UpdateAllegatoRequest $request, Documento $allegato): RedirectResponse
    {
        $elemento = RegoleAllegati::elementoDellAllegato($allegato);
        abort_if($elemento === null, 404);
        Gate::allowIf(RegoleAllegati::puoModificare($request->user(), $allegato, $elemento));

        $this->allegatoService->rinomina(
            $request->user(),
            $allegato,
            (string) $request->validated('nome')
        );

        return back()->with($this->flashSuccess('Allegato rinominato.'));
    }

    public function destroy(Request $request, Documento $allegato): RedirectResponse
    {
        $elemento = RegoleAllegati::elementoDellAllegato($allegato);
        abort_if($elemento === null, 404);
        Gate::allowIf(RegoleAllegati::puoEliminare($request->user(), $allegato, $elemento));

        $this->allegatoService->elimina($request->user(), $allegato, $elemento);

        return back()->with($this->flashSuccess('Allegato eliminato.'));
    }

    public function approva(Request $request, Documento $allegato): RedirectResponse
    {
        $elemento = RegoleAllegati::elementoDellAllegato($allegato);
        abort_if($elemento === null, 404);
        Gate::allowIf(RegoleAllegati::puoApprovare($request->user(), $allegato, $elemento));

        $this->allegatoService->approva($request->user(), $allegato, $elemento);

        return back()->with($this->flashSuccess('Allegato approvato.'));
    }

    public function impostazioniSegnalazione(ImpostazioniAllegatiRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::allowIf(RegoleAllegati::puoImpostare($request->user(), $segnalazione));
        $dati = $request->validated();

        $this->allegatoService->aggiornaImpostazioni(
            $segnalazione,
            (string) $dati['allegati_accesso'],
            (bool) $dati['allegati_moderati']
        );

        return back()->with($this->flashSuccess('Impostazioni degli allegati aggiornate.'));
    }

    public function impostazioniComunicazione(ImpostazioniAllegatiRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::allowIf(RegoleAllegati::puoImpostare($request->user(), $comunicazione));
        $dati = $request->validated();

        $this->allegatoService->aggiornaImpostazioni(
            $comunicazione,
            (string) $dati['allegati_accesso'],
            (bool) $dati['allegati_moderati']
        );

        return back()->with($this->flashSuccess('Impostazioni degli allegati aggiornate.'));
    }

    public function index(AllegatiIndexRequest $request): InertiaResponse
    {
        $user = $request->user();
        Gate::allowIf(RegoleAllegati::puoAprirePagina($user));

        $filtri = $request->validated();
        $perPage = (int) ($filtri['per_page'] ?? app(GeneralSettings::class)->default_per_page ?: 10);
        $consentite = config('pagination.consentite', [10, 15, 25, 50, 100]);
        if (! in_array($perPage, $consentite, true)) {
            $perPage = (int) (app(GeneralSettings::class)->default_per_page ?: 10);
        }

        $allegati = $this->allegatoService
            ->queryPerPagina($user, $filtri)
            ->orderByRaw('CASE WHEN is_approved = 0 OR is_published = 0 THEN 0 ELSE 1 END ASC')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Documento $d) => $this->allegatoService->rigaPagina($user, $d));

        return Inertia::render('allegati/AllegatiList', [
            'allegati'  => $allegati,
            'filtri'    => [
                'stato'         => (string) ($filtri['stato'] ?? ''),
                'tipo'          => (string) ($filtri['tipo'] ?? ''),
                'condominio_id' => isset($filtri['condominio_id']) && $filtri['condominio_id'] !== '' ? (int) $filtri['condominio_id'] : null,
                'autore'        => (string) ($filtri['autore'] ?? ''),
                'dal'           => (string) ($filtri['dal'] ?? ''),
                'al'            => (string) ($filtri['al'] ?? ''),
            ],
            'condomini' => Condominio::query()->orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    public function approvaInBlocco(Request $request): RedirectResponse
    {
        $user = $request->user();
        Gate::allowIf(RegoleAllegati::puoAprirePagina($user));

        $ids = (array) $request->input('ids', []);
        $this->allegatoService->approvaInBlocco($user, $ids);

        return back()->with($this->flashSuccess('Allegati approvati.'));
    }

    public function eliminaInBlocco(Request $request): RedirectResponse
    {
        $user = $request->user();
        Gate::allowIf(RegoleAllegati::puoAprirePagina($user));

        $ids = (array) $request->input('ids', []);
        $this->allegatoService->eliminaInBlocco($user, $ids);

        return back()->with($this->flashSuccess('Allegati eliminati.'));
    }

    public function zip(AllegatiIndexRequest $request): BinaryFileResponse
    {
        $user = $request->user();
        Gate::allowIf(RegoleAllegati::puoAprirePagina($user));

        $filtri = $request->validated();
        $tmpFile = $this->allegatoService->creaZipTemporaneo($user, $filtri);

        return response()
            ->download($tmpFile, 'allegati.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
