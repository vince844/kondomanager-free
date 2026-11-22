<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Convocazione;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\Convocazione\FoglioFirmatoRequest;
use App\Http\Requests\Assemblee\Convocazione\MezzoDelConvocatoRequest;
use App\Http\Requests\Assemblee\Convocazione\PersoneDellaConvocazioneRequest;
use App\Http\Requests\Assemblee\Convocazione\RaccomandateEntroRequest;
use App\Http\Requests\Assemblee\Convocazione\RiconvocaRequest;
use App\Http\Requests\Assemblee\Convocazione\SpedizioneRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Assemblee\Convocazione\MezziScelti;
use App\Services\Assemblee\Convocazione\ProvaDellaConvocazione;
use App\Services\Assemblee\Convocazione\TerminiDellaConvocazione;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\PDF\PdfService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Il passo «Convocazione» della pagina dell'assemblea (§3 P13 del progetto della tappa 2): l'anteprima e la stampa delle lettere, le PEC, la spedizione, la
 * stampa della prova, il foglio delle consegne a mano, l'avviso in bacheca, la data delle raccomandate, la riconvocazione. In ogni metodo la guardia del
 * condominio dell'assemblea; i permessi con {@see AccessoAlleAssemblee} («Gestisce le assemblee» per ciò che scrive e per le stampe, che scrivono il registro
 * degli invii; «Vede le assemblee» per l'anteprima e la prova). Gli id che arrivano nel corpo passano da {@see SchedaDelCondominio::risolvi()} o dal servizio,
 * che rende 404 per un invio di un'altra assemblea.
 */
class ConvocazioneController extends Controller
{
    use HandleFlashMessages;

    public function __construct(
        private readonly EmissioneDelleConvocazioni $emissione,
        private readonly ConsegneDellaConvocazione $consegne,
        private readonly TerminiDellaConvocazione $termini,
    ) {}

    /** L'anteprima degli avvisi, con «ANTEPRIMA» in testa: non scrive niente. */
    public function anteprima(PersoneDellaConvocazioneRequest $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));

        return self::pdf($this->emissione->anteprima($assemblea, $this->persone($condominio, $request)),
            PdfService::nomeFile('convocazioni', (string) $condominio->nome, $assemblea->data?->format('Y-m-d'), 'anteprima'));
    }

    /** «Stampa le lettere»: un PDF unico o uno .zip per persona; una riga «stampata» per persona. */
    public function lettere(PersoneDellaConvocazioneRequest $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $esito = $this->emissione->stampaLeLettere($assemblea, $this->persone($condominio, $request), (string) $request->input('formato', 'pdf'), $request->user());
        if ($esito['file'] !== null) {
            return response()->download($esito['file'], $esito['nome'], ['Content-Type' => 'application/zip', 'X-Avvisi' => (string) $esito['quante']])
                ->deleteFileAfterSend();
        }

        return self::pdf((string) $esito['contenuto'], $esito['nome'], ['X-Avvisi' => (string) $esito['quante']]);
    }

    /** «Manda le PEC» dalla casella dello studio. */
    public function pec(PersoneDellaConvocazioneRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $esito = $this->emissione->mandaLePec($assemblea, $this->persone($condominio, $request), $request->user());
        // Con un errore la frase dice quante sono partite e perché le altre no: «Nessuna PEC è partita» solo se non ne è partita nessuna.
        if ($esito['errore'] !== null) {
            return back()->with($this->flashError($esito['frase']));
        }

        return back()->with($this->flashSuccess($esito['frase']));
    }

    /** «Registra la spedizione»: la raccomandata con il numero, il fax, la consegna a mano. */
    public function spedizione(SpedizioneRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $dati = $request->validated();
        try {
            $quante = $this->consegne->registraSpedizione($assemblea, array_map('intval', $dati['invii']), $dati['canale'], $dati['data'],
                (array) ($dati['numeri'] ?? []), $dati['nota'] ?? null, $request->user());
        } catch (\App\Exceptions\Gestionale\LetteraNonEmettibileException $e) {
            return back()->withErrors(['invii' => $e->getMessage()]);
        }

        return back()->with($this->flashSuccess($quante === 1 ? 'Spedizione registrata.' : 'Spedizione registrata per ' . $quante . ' persone.'));
    }

    /**
     * Il mezzo di una persona **per questa assemblea**, dalla riga della tabella dei convocati (decisione 647), con «Ricordalo nella sua scheda» se si vuole
     * anche nella scheda della persona (e nel registro delle modifiche). Solo chi gestisce le assemblee.
     */
    public function mezzo(MezzoDelConvocatoRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $dati = $request->validated();
        $persona = SchedaDelCondominio::risolvi($condominio, 'anagrafica', (int) $dati['anagrafica_id']);
        $mezzo = filled($dati['mezzo'] ?? null) ? (string) $dati['mezzo'] : null;
        app(MezziScelti::class)->scegli($assemblea, $persona, $mezzo, (bool) ($dati['ricorda'] ?? false), $request->user());

        return back();
    }

    /** «Stampa la prova della convocazione». */
    public function prova(Request $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));

        return self::pdf(app(ProvaDellaConvocazione::class)->pdf($assemblea),
            PdfService::nomeFile('prova-della-convocazione', (string) $condominio->nome, $assemblea->data?->format('Y-m-d')));
    }

    /** Il foglio delle consegne a mano da stampare. */
    public function foglio(PersoneDellaConvocazioneRequest $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));

        return self::pdf($this->consegne->foglioDaStampare($assemblea, $this->persone($condominio, $request)),
            PdfService::nomeFile('foglio-delle-consegne', (string) $condominio->nome, $assemblea->data?->format('Y-m-d')));
    }

    /** Il foglio delle consegne a mano firmato: una scansione per tutte le sue righe. */
    public function foglioFirmato(FoglioFirmatoRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $consegne = array_map(fn ($c) => ['invio_id' => (int) $c['invio_id'], 'giorno' => (string) $c['giorno']], (array) $request->validated('consegne'));
        $quante = $this->consegne->foglioFirmato($assemblea, $request->file('foglio'), $consegne, $request->user());

        return back()->with($this->flashSuccess($quante === 1 ? 'Consegna a mano registrata, con il foglio firmato.'
            : 'Consegna a mano registrata per ' . $quante . ' persone, con il foglio firmato.'));
    }

    /**
     * «Avviso anche in bacheca»: apre la pagina della nuova comunicazione già scritta, con le frasi dei punti senza dati personali; la comunicazione si salva per
     * la sua strada, e torna legata all'assemblea.
     */
    public function bacheca(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        if ($assemblea->comunicazione_id !== null) {
            return back()->with($this->flashError('L\'avviso in bacheca c\'è già.'));
        }

        return redirect()->route('admin.comunicazioni.create', ['assemblea' => $assemblea->id]);
    }

    /** «Spedisco le raccomandate entro»: la data scelta dall'amministratore, o vuota per la proposta (decisione 565). */
    public function raccomandateEntro(RaccomandateEntroRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $this->termini->scegli($assemblea, $request->validated('giorno'), $request->user());

        return back();
    }

    /** «Annulla e riconvoca»: l'assemblea si annulla con il motivo, e ne nasce una nuova con l'ordine del giorno e i memo. */
    public function riconvoca(RiconvocaRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $dati = $request->validated();
        $sedute = array_map(fn ($s) => ['convocazione' => $s['convocazione'], 'numero' => (int) ($s['numero'] ?? 1), 'fissata_il' => $s['fissata_il'],
            'luogo' => $s['luogo'] ?? null], $dati['sedute']);
        $nuova = StatiDellAssemblea::annullaERiconvoca($assemblea, $dati['motivo'], $sedute, $request->user());
        $frase = 'L\'assemblea è annullata, e la nuova è in preparazione: ' . $nuova->nome() . '.';
        if (Route::has('admin.gestionale.assemblee.show')) {
            return redirect()->route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $nuova->id])->with($this->flashSuccess($frase));
        }

        return back()->with($this->flashSuccess($frase));
    }

    /**
     * Le persone scelte nel corpo, ognuna del condominio (R18): una di un altro condominio dà 404. Null: tutte quelle da spedire.
     *
     * @return ?list<int>
     */
    private function persone(Condominio $condominio, Request $request): ?array
    {
        $ids = $request->input('anagrafiche');
        if (! is_array($ids) || $ids === []) {
            return null;
        }

        return array_values(array_map(fn ($id) => (int) SchedaDelCondominio::risolvi($condominio, 'anagrafica', (int) $id)->getKey(), $ids));
    }

    /** @param array<string, string> $piu */
    public static function pdf(string $contenuto, string $nome, array $piu = []): Response
    {
        return response($contenuto, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $nome . '"'] + $piu);
    }
}
