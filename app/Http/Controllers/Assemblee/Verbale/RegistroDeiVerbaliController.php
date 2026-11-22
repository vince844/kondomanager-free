<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Verbale;

use App\Enums\Assemblee\EsitoSeduta;
use App\Http\Controllers\Controller;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\RigaDiNomina;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Verbale\RegistroDeiVerbali;
use App\Services\Assemblee\Verbale\RegistroDiNominaERevoca;
use App\Services\Assemblee\Verbale\StampeDelVerbale;
use App\Services\PDF\PdfService;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as RispostaHttp;

/**
 * Il registro dei verbali e il registro di nomina e revoca (P3 del progetto del verbale): la pagina (le schede «Verbali» e «Nomina e revoca», l'anno, il
 * registro del perimetro), «Da dove parte il registro», la stampa, «Incollata nel libro», la pagina dell'annotazione per il libro di carta, e le righe di
 * nomina e revoca (la nomina in corso, una riga a mano, «Conferma la riga», «Correggi», «Annulla» con il perché). Leggere con «Vede le assemblee»; scrivere
 * con «Registra il verbale sottoscritto».
 */
class RegistroDeiVerbaliController extends Controller
{
    use HandleFlashMessages;
    use HasCondomini, HasEsercizio;

    public function __construct(private readonly RegistroDeiVerbali $registro, private readonly RegistroDiNominaERevoca $nomine) {}

    public function index(Request $request, Condominio $condominio): Response
    {
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $registro = RegistroDeiVerbali::delCondominio($condominio, $request->query('registro'));
        $anno = $request->filled('anno') ? (int) $request->query('anno') : null;

        return Inertia::render('gestionale/assemblee/RegistroDeiVerbali', [
            'condominio' => ['id' => (int) $condominio->id, 'nome' => $condominio->nome],
            'condomini' => $this->getCondomini(),
            // Il menu del gestionale in alto porta alle pagine dell'esercizio: senza, i collegamenti vanno a «esercizi/undefined/…».
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'ritorno' => $this->ritorno($request, $condominio),
            'registro' => fn () => $this->datiDelRegistro($condominio, $registro, $anno, $request),
        ]);
    }

    /**
     * Il ritorno da dove si è venuti (decisione 657): l'indirizzo porta `da_assemblea` o `da_verbale`, un numero; il server lo cerca fra le assemblee e i verbali
     * di questo condominio e costruisce lui l'indirizzo. Senza, o con un numero che non è di questo condominio, si torna all'archivio delle assemblee: dalla
     * richiesta non si prende mai un indirizzo.
     *
     * @return array{url: string, testo: string}
     */
    private function ritorno(Request $request, Condominio $condominio): array
    {
        $numero = fn (string $chiave): ?int => ctype_digit((string) $request->query($chiave)) ? (int) $request->query($chiave) : null;
        if (($id = $numero('da_verbale')) !== null
            && ($verbale = VerbaleDellAssemblea::query()->where('condominio_id', $condominio->id)->whereKey($id)->first()) !== null) {
            return ['url' => route('admin.gestionale.assemblee.verbale.pagina', [$condominio, $verbale->assemblea_id, 'verbale' => $verbale->id]), 'testo' => 'Il verbale'];
        }
        if (($id = $numero('da_assemblea')) !== null && Assemblea::query()->where('condominio_id', $condominio->id)->whereKey($id)->exists()) {
            return ['url' => route('admin.gestionale.assemblee.show', [$condominio, $id]), 'testo' => 'L\'assemblea'];
        }

        return ['url' => route('admin.gestionale.assemblee.index', $condominio), 'testo' => 'Assemblee'];
    }

    /** I dati della pagina. @return array<string, mixed> */
    public function datiDelRegistro(Condominio $condominio, string $registro, ?int $anno, Request $request): array
    {
        return [
            'perimetro' => RegistroDeiVerbali::perimetro($condominio, $registro),
            'registri' => $this->registro->registri($condominio),
            'partenza' => $this->registro->partenza($condominio, $registro),
            'prossimo_numero' => $this->registro->prossimoNumero($condominio, $registro),
            'anni' => $this->registro->anni($condominio, $registro),
            'anno' => $anno,
            'righe' => $this->registro->righe($condominio, $registro, $anno),
            'nomine' => $this->nomine->righe($condominio),
            'senza_la_nomina_in_corso' => $this->nomine->senzaLaNominaInCorso($condominio),
            'amministratore_di_oggi' => $this->nomine->amministratoreDiOggi((int) $condominio->id),
            'puo' => ['registrare' => AccessoAlleAssemblee::registraIlVerbale($request->user())],
            'rotte' => [
                'partenza' => route('admin.gestionale.assemblee.registro.partenza', $condominio),
                'stampa' => route('admin.gestionale.assemblee.registro.stampa', [$condominio, 'registro' => $registro]),
                'libro' => route('admin.gestionale.assemblee.registro.libro', $condominio),
                'nomine' => route('admin.gestionale.assemblee.registro.nomine.store', $condominio),
            ],
        ];
    }

    /** «Da dove parte il registro»: `scelta` (libro, da_uno), `ultimo`, `del`, per il `registro` dato. */
    public function partenza(Request $request, Condominio $condominio): RedirectResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        $dati = $request->validate(['registro' => ['nullable', 'string', 'max:40'], 'scelta' => ['required', 'in:libro,da_uno'],
            'ultimo' => ['nullable', 'integer', 'min:1', 'max:99999'], 'del' => ['nullable', 'date_format:Y-m-d']]);
        $registro = RegistroDeiVerbali::delCondominio($condominio, $dati['registro'] ?? null);
        DB::transaction(fn () => $this->registro->impostaPartenza($condominio, $registro, $dati['scelta'], $dati['ultimo'] ?? null, $dati['del'] ?? null, $request->user()));
        $prossimo = $this->registro->prossimoNumero($condominio, $registro);

        return back()->with($this->flashSuccess('Il prossimo verbale registrato qui prende il n. ' . $prossimo . '.'));
    }

    /** La stampa del registro (e di quello di nomina e revoca: le righe confermate, e le annullate con il perché). */
    public function stampa(Request $request, Condominio $condominio): RispostaHttp
    {
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $registro = RegistroDeiVerbali::delCondominio($condominio, $request->query('registro'));
        $anno = $request->filled('anno') ? (int) $request->query('anno') : null;
        $pdf = app(StampeDelVerbale::class)->registro($condominio, RegistroDeiVerbali::perimetro($condominio, $registro), $this->registro->righe($condominio, $registro, $anno),
            $registro === 'condominio' ? $this->nomine->righePerLaStampa($condominio) : [], $anno);

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'
            . PdfService::nomeFile('registro-dei-verbali', (string) $condominio->nome, $anno === null ? null : (string) $anno) . '"']);
    }

    /** «Incollata nel libro» per un verbale (`verbale_id`) o un'annotazione (`seduta_id`). */
    public function libro(Request $request, Condominio $condominio): RedirectResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        $dati = $request->validate(['verbale_id' => ['nullable', 'integer'], 'seduta_id' => ['nullable', 'integer'], 'giorno' => ['nullable', 'date_format:Y-m-d']]);
        abort_if(empty($dati['verbale_id']) && empty($dati['seduta_id']), 404);
        $this->registro->segnaNelLibro($condominio, ! empty($dati['verbale_id']) ? 'verbale' : 'annotazione', (int) ($dati['verbale_id'] ?? $dati['seduta_id']),
            $dati['giorno'] ?? null, $request->user());

        return back()->with($this->flashSuccess(($dati['giorno'] ?? null) === null ? 'Pagina da incollare.' : 'Pagina segnata incollata nel libro di carta.'));
    }

    /** La pagina dell'annotazione di una seduta deserta, per il libro di carta. */
    public function annotazione(Request $request, Condominio $condominio, string|int $seduta): RispostaHttp
    {
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $s = $this->assicuraLaSeduta($condominio, (int) $seduta);

        return response(app(StampeDelVerbale::class)->annotazione($s), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'
            . PdfService::nomeFile('annotazione', (string) $condominio->nome, $s->fissata_il->format('Y-m-d')) . '"']);
    }

    /** Una riga a mano del registro di nomina e revoca (la prima volta, la nomina in corso), con il documento facoltativo. */
    public function nomina(Request $request, Condominio $condominio): RedirectResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        $dati = $request->validate(self::regole() + ['documento' => ['nullable', 'file', 'max:' . LimiteCaricamento::regolaMax(10)]]);
        $riga = $this->nomine->aMano($condominio, $dati, $request->file('documento'), $request->user());

        return back()->with($this->flashSuccess(ucfirst((string) $riga->evento?->parola()) . ' di ' . $riga->amministratore . ' scritta nel registro.'));
    }

    public function correggi(Request $request, Condominio $condominio, RigaDiNomina $rigaDiNomina): RedirectResponse
    {
        $this->assicuraLaRiga($condominio, $rigaDiNomina);
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        $this->nomine->correggi($rigaDiNomina, $request->validate(self::regole(false)), $request->user());

        return back()->with($this->flashSuccess('Riga corretta: la correzione resta nel registro delle modifiche.'));
    }

    public function conferma(Request $request, Condominio $condominio, RigaDiNomina $rigaDiNomina): RedirectResponse
    {
        $this->assicuraLaRiga($condominio, $rigaDiNomina);
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        $this->nomine->conferma($rigaDiNomina, $request->user());

        return back()->with($this->flashSuccess('Riga confermata: entra nel registro di nomina e revoca e nella sua stampa.'));
    }

    public function annulla(Request $request, Condominio $condominio, RigaDiNomina $rigaDiNomina): RedirectResponse
    {
        $this->assicuraLaRiga($condominio, $rigaDiNomina);
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        $dati = $request->validate(['motivo' => ['required', 'string', 'max:1000']], ['motivo.required' => 'Scrivi perché annulli la riga.']);
        $this->nomine->annulla($rigaDiNomina, $dati['motivo'], $request->user());

        return back()->with($this->flashSuccess('Riga annullata: resta nel registro, con il perché.'));
    }

    /** @return array<string, array<int, string>> */
    private static function regole(bool $daDoveObbligatorio = true): array
    {
        return ['evento' => ['nullable', 'in:nomina,revoca'], 'amministratore' => ['required', 'string', 'max:255'], 'dal' => ['required', 'date_format:Y-m-d'],
            'da_dove' => [$daDoveObbligatorio ? 'required' : 'nullable', 'in:carta,verbale,decreto'], 'estremi' => ['nullable', 'string', 'max:255'],
            'decreto' => ['nullable', 'array'], 'decreto.autorita' => ['nullable', 'string', 'max:255'], 'decreto.numero' => ['nullable', 'string', 'max:100'],
            'decreto.data' => ['nullable', 'date_format:Y-m-d'], 'nota' => ['nullable', 'string', 'max:2000']];
    }

    /** La seduta deserta di un'assemblea del condominio dell'indirizzo, o 404. */
    private function assicuraLaSeduta(Condominio $condominio, int $seduta): SedutaDellAssemblea
    {
        $s = SedutaDellAssemblea::query()->whereKey($seduta)->where('esito', EsitoSeduta::DESERTA->value)->with('assemblea')->first() ?? abort(404);
        abort_unless((int) $s->assemblea?->condominio_id === (int) $condominio->id, 404);

        return $s;
    }

    /** La riga di nomina e revoca è del condominio dell'indirizzo (oltre a `scopeBindings()`), o 404. */
    private function assicuraLaRiga(Condominio $condominio, RigaDiNomina $riga): void
    {
        abort_unless((int) $riga->condominio_id === (int) $condominio->id, 404);
    }
}
