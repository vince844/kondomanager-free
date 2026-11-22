<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Verbale;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\Verbale\GestiDelVerbaleRequest;
use App\Http\Requests\Assemblee\Verbale\RegistraIlVerbaleRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Condominio;
use App\Models\Documento;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Verbale\ChiScriveIlVerbale;
use App\Services\Assemblee\Verbale\ComposizioneDelVerbale;
use App\Services\Assemblee\Verbale\GestiDelVerbale;
use App\Services\Assemblee\Verbale\LetturaDelVerbale;
use App\Services\Assemblee\Verbale\ModelliDelVerbale;
use App\Services\Assemblee\Verbale\RegistrazioneDelVerbale;
use App\Services\Assemblee\Verbale\RegistroDeiVerbali;
use App\Services\Assemblee\Verbale\StampeDelVerbale;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use App\Services\Assemblee\Verbale\VersioniDelVerbale;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\PDF\PdfService;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as RispostaHttp;

/**
 * La pagina «Il verbale» e i suoi gesti (P1 del progetto del verbale): la pagina (Inertia, «Vede le assemblee»), la lettura periodica in JSON (con chi ha la
 * pagina aperta), i salvataggi del testo (JSON con il loro numero, «Gestisce le assemblee»), «Genera» e «Rigenera dal modello», «Aggiorna le parti segnate»,
 * le versioni («Vedi», «Ripristina», la scelta fra due versioni), chi scrive, «Stampa la bozza da firmare», e con «Registra il verbale sottoscritto» la
 * registrazione, la copia (scaricarla, sostituirla), l'annullamento, la pagina per il libro di carta. In ogni metodo la guardia del condominio e
 * dell'assemblea; il verbale è dell'assemblea della rotta (`scopeBindings`).
 */
class VerbaleController extends Controller
{
    use HandleFlashMessages;
    use HasCondomini, HasEsercizio;

    public function __construct(private readonly LetturaDelVerbale $lettura) {}

    public function show(Request $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $verbale = $request->filled('verbale') ? (int) $request->query('verbale') : null;

        return Inertia::render('gestionale/assemblee/Verbale', [
            'condominio' => ['id' => (int) $condominio->id, 'nome' => $condominio->nome],
            'condomini' => $this->getCondomini(),
            // Il menu del gestionale in alto porta alle pagine dell'esercizio: senza, i collegamenti vanno a «esercizi/undefined/…».
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'ritorno' => $this->ritorno($request, $condominio, $assemblea),
            'verbale' => fn () => $this->lettura->perLaPagina($assemblea, $verbale, $request->user(), $this->dispositivo($request)),
        ]);
    }

    /** La stessa prop in JSON, per la lettura periodica: con `dispositivo` (e `parte`, `scrivo`) rinnova chi ha la pagina aperta. */
    public function stato(Request $request, Condominio $condominio, Assemblea $assemblea): JsonResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $id = $request->filled('verbale') ? (int) $request->query('verbale') : null;
        $dispositivo = $this->dispositivo($request);
        $dati = $this->lettura->perLaPagina($assemblea, $id, $request->user(), $dispositivo);
        if ($dispositivo !== null && $dati['verbale'] !== null) {
            $verbale = VerbaleDellAssemblea::query()->findOrFail($dati['verbale']['id']);
            $parte = (string) $request->query('parte', 'intero');
            app(ChiScriveIlVerbale::class)->presenza($verbale, $parte, $dispositivo, $request->user(),
                $request->boolean('scrivo') && AccessoAlleAssemblee::gestisce($request->user()));
            $dati['verbale']['chi_scrive'][$parte] = app(ChiScriveIlVerbale::class)->stato($verbale, $parte, $dispositivo);
        }

        return response()->json($dati);
    }

    /** I salvataggi del testo di un dispositivo: `{ora_del_server, risposte}`, una risposta per gesto, nell'ordine. */
    public function gesti(GestiDelVerbaleRequest $request, Condominio $condominio, Assemblea $assemblea): JsonResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        $dati = $request->validated();
        $risposte = app(GestiDelVerbale::class)->ricevi($assemblea, (string) $dati['dispositivo'], array_values((array) $dati['gesti']), $request->user());
        app(\App\Services\Assemblee\Verbale\CompitiDelVerbale::class)->sincronizza($assemblea);

        return response()->json(['ora_del_server' => now()->utc()->toIso8601ZuluString(), 'risposte' => $risposte]);
    }

    /** «Genera il verbale dal modello» (e «Rigenera»): la riunione (`seduta_id`) e il modello (`modello_id`, vuoto per il testo del programma). */
    public function genera(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        $this->gestisce($request);
        $dati = $request->validate(['seduta_id' => ['required', 'integer'], 'modello_id' => ['nullable', 'integer']]);
        $seduta = SedutaDellAssemblea::query()->where('assemblea_id', $assemblea->id)->whereKey((int) $dati['seduta_id'])->first() ?? abort(404);
        $verbale = VerbaliDellAssemblea::perLaSeduta($seduta, $request->user());
        $rigenera = $verbale->stato?->value === 'bozza';
        $verbale = app(ComposizioneDelVerbale::class)->genera($verbale, ModelliDelVerbale::scelto($condominio, $dati['modello_id'] ?? null), $request->user());

        return back()->with($this->flashSuccess(($rigenera ? 'Verbale rigenerato' : 'Verbale generato') . ' dal modello «' . $verbale->modello_nome
            . '»: le parti con i dati della sala sono segnate.'));
    }

    public function aggiornaSala(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        $this->gestisce($request);
        app(ComposizioneDelVerbale::class)->aggiornaLeParti($verbaleDellAssemblea, $request->user());

        return back()->with($this->flashSuccess('Parti con i dati della sala aggiornate: il testo di prima resta fra le versioni.'));
    }

    /** «Vedi» una versione, in JSON. */
    public function versione(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea, string|int $versione): JsonResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));

        return response()->json(app(VersioniDelVerbale::class)->vedi($verbaleDellAssemblea, (int) $versione));
    }

    public function ripristina(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea, string|int $versione): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        $this->gestisce($request);
        $v = app(VersioniDelVerbale::class)->ripristina($verbaleDellAssemblea, (int) $versione, $request->user());

        return back()->with($this->flashSuccess('Versione ripristinata: è la versione ' . $v->numero . '; il testo di prima resta fra le versioni.'));
    }

    /** La scelta su una versione in attesa: `questa`, `quella`, `uniscile` (con `documento`), `vista`, `metti` (la discussione arrivata dopo la generazione). */
    public function scegli(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea, string|int $versione): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        $this->gestisce($request);
        $dati = $request->validate(['scelta' => ['required', Rule::in(['questa', 'quella', 'uniscile', 'vista', 'metti'])], 'documento' => ['nullable', 'array']]);
        app(VersioniDelVerbale::class)->scegli($verbaleDellAssemblea, (int) $versione, $dati['scelta'], $dati['documento'] ?? null, $request->user());

        return back()->with($this->flashSuccess('Scelta fatta: resta nel registro delle modifiche.'));
    }

    /** Chi scrive: il dispositivo prende la parte (se è libera) o lascia (`lascia`). JSON. */
    public function scrivo(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): JsonResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        $this->gestisce($request);
        $dati = $this->datiDiChiScrive($request);
        $chi = app(ChiScriveIlVerbale::class);
        if ($request->boolean('lascia')) {
            $chi->lascia($verbaleDellAssemblea, $dati['parte'], $dati['dispositivo']);

            return response()->json($chi->stato($verbaleDellAssemblea, $dati['parte'], $dati['dispositivo']));
        }

        return response()->json($chi->presenza($verbaleDellAssemblea, $dati['parte'], $dati['dispositivo'], $request->user(), true));
    }

    /** «Chiedi di scrivere». JSON. */
    public function chiedi(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): JsonResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        $this->gestisce($request);
        $dati = $this->datiDiChiScrive($request);

        return response()->json(app(ChiScriveIlVerbale::class)->chiedi($verbaleDellAssemblea, $dati['parte'], $dati['dispositivo'], $request->user()));
    }

    /** La risposta di chi scrive: `lascia` o `continuo`. JSON. */
    public function rispondi(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): JsonResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        $this->gestisce($request);
        $dati = $this->datiDiChiScrive($request) + $request->validate(['risposta' => ['required', Rule::in(['lascia', 'continuo'])]]);

        return response()->json(app(ChiScriveIlVerbale::class)->rispondi($verbaleDellAssemblea, $dati['parte'], $dati['dispositivo'], $dati['risposta']));
    }

    /** «Stampa la bozza da firmare»: il PDF. Con le cifre che non tornano serve `anche_con_le_cifre`. */
    public function bozza(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RispostaHttp
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        $this->gestisce($request);
        $esito = app(RegistrazioneDelVerbale::class)->stampaLaBozza($verbaleDellAssemblea, $request->user(), $request->boolean('anche_con_le_cifre'));

        return response($esito['pdf'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'
            . PdfService::nomeFile('verbale-bozza', (string) $condominio->nome, $verbaleDellAssemblea->seduta?->fissata_il?->format('Y-m-d'), 'v' . $esito['versione']) . '"',
            'X-Versione' => (string) $esito['versione'], 'X-Impronta' => $esito['impronta']]);
    }

    /** «Registra il verbale sottoscritto». */
    public function registra(RegistraIlVerbaleRequest $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        $dati = $request->validated();
        $v = app(RegistrazioneDelVerbale::class)->registra($verbaleDellAssemblea, $request->file('copia'), isset($dati['numero']) ? (int) $dati['numero'] : null,
            (string) $dati['sottoscritto_il'], $dati['partenza'] ?? null, $request->user(), $request->boolean('senza_la_bozza'), $request->boolean('salto'));

        return back()->with($this->flashSuccess('Verbale n. ' . $v->numero . ' registrato: adesso si trasmette.'));
    }

    /**
     * La copia sottoscritta: com'è, da scaricare; con `?come=pdf` come PDF da guardare nel browser (dal `.p7m` il PDF dentro la busta, dall'immagine una
     * pagina A4), o 404 se non si apre come PDF.
     */
    public function scaricaLaCopia(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea)
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $doc = $verbaleDellAssemblea->documento_id === null ? null : Documento::query()->find($verbaleDellAssemblea->documento_id);
        abort_if($doc === null || ! app(ArchivioDocumenti::class)->esiste($doc->path), 404);
        if ($request->query('come') === 'pdf') {
            $pdf = app(\App\Services\Assemblee\Verbale\PdfDellaCopia::class)->daDocumento($doc);
            abort_if($pdf === null, 404);

            return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'
                . PdfService::nomeFile('verbale-' . ($verbaleDellAssemblea->numero ?? 'copia'), (string) $condominio->nome, null, 'sottoscritto') . '"']);
        }

        return app(ArchivioDocumenti::class)->scarica($doc->path, $doc->nomeDiScaricamento());
    }

    /**
     * Il ritorno da dove si è venuti (decisione 659): l'indirizzo porta `da=sala` o `da=registro`; il server costruisce lui l'indirizzo, dalla sala di questa
     * assemblea o dal registro di questo condominio. Ogni altra parola, o nessuna, torna alla pagina dell'assemblea: dalla richiesta non si prende mai un indirizzo.
     *
     * @return array{url: string, testo: string}
     */
    private function ritorno(Request $request, Condominio $condominio, Assemblea $assemblea): array
    {
        return match ((string) $request->query('da')) {
            'sala' => ['url' => route('admin.gestionale.assemblee.sala', [$condominio, $assemblea]), 'testo' => 'La sala'],
            'registro' => ['url' => route('admin.gestionale.assemblee.registro', [$condominio]), 'testo' => 'Il registro'],
            default => ['url' => route('admin.gestionale.assemblee.show', [$condominio, $assemblea]), 'testo' => 'L\'assemblea'],
        };
    }

    /** «Sostituisci la copia», con il perché. */
    public function sostituisciLaCopia(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        $request->validate(['copia' => ['required', 'file', 'max:' . LimiteCaricamento::regolaMax(10)], 'perche' => ['required', 'string', 'max:1000']],
            ['copia.required' => 'Scegli la copia nuova.', 'perche.required' => 'Scrivi perché sostituisci la copia.']);
        app(RegistrazioneDelVerbale::class)->sostituisciLaCopia($verbaleDellAssemblea, $request->file('copia'), (string) $request->input('perche'), $request->user());

        return back()->with($this->flashSuccess('Copia sostituita: il numero e il giorno della firma restano, la copia di prima resta fra le versioni.'));
    }

    /** «Annulla la registrazione», con il perché. */
    public function annulla(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        $request->validate(['motivo' => ['required', 'string', 'max:2000']], ['motivo.required' => 'Scrivi perché annulli la registrazione.']);
        $numero = $verbaleDellAssemblea->numero;
        app(RegistrazioneDelVerbale::class)->annulla($verbaleDellAssemblea, (string) $request->input('motivo'), $request->user());

        return back()->with($this->flashSuccess('Registrazione n. ' . $numero . ' annullata: la riga resta nel registro, e il testo torna in bozza.'));
    }

    /** La pagina per il libro di carta del verbale registrato (decisione 595 (2)). */
    public function paginaDelLibro(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RispostaHttp
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        abort_if($verbaleDellAssemblea->numero === null, 404);

        return response(app(StampeDelVerbale::class)->perIlLibro($verbaleDellAssemblea), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . PdfService::nomeFile('verbale-' . $verbaleDellAssemblea->numero, (string) $condominio->nome, null, 'libro') . '"']);
    }

    /** «Incollata nel libro»: il giorno (vuoto toglie il segno). */
    public function libro(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea);
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        $giorno = $request->validate(['giorno' => ['nullable', 'date_format:Y-m-d']])['giorno'] ?? null;
        app(RegistroDeiVerbali::class)->segnaNelLibro($condominio, 'verbale', (int) $verbaleDellAssemblea->id, $giorno, $request->user());

        return back()->with($this->flashSuccess($giorno === null ? 'Pagina da incollare.' : 'Pagina segnata incollata nel libro di carta.'));
    }

    /** @return array{parte: string, dispositivo: string} */
    private function datiDiChiScrive(Request $request): array
    {
        return $request->validate(['parte' => ['required', 'string', 'max:30', 'regex:/^(intero|punto:\d+)$/'], 'dispositivo' => ['required', 'string', 'max:64']]);
    }

    private function dispositivo(Request $request): ?string
    {
        $d = trim((string) $request->query('dispositivo', ''));

        return $d === '' ? null : mb_substr($d, 0, 64);
    }

    private function gestisce(Request $request): void
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
    }

    private function assicuraLAssemblea(Condominio $condominio, Assemblea $assemblea): void
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
    }
}
