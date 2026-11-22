<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Verbale;

use App\Http\Controllers\Controller;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Condominio;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione;
use App\Services\Assemblee\Verbale\TerminiDellImpugnazione;
use App\Services\Assemblee\Verbale\TrasmissioneDelVerbale;
use App\Services\PDF\PdfService;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * La trasmissione del verbale registrato («La trasmissione e i trenta giorni», scena 3 del verbale): «Togli» e «Aggiungi una persona» con il perché, «Manda le
 * PEC», «Stampa le lettere», «Registra la spedizione», la prova della trasmissione, e «Segna impugnata» su una delibera. La consegna, la prova e la ricevuta
 * PEC di ogni invio sono quelle degli invii dell'assemblea (`assemblee.invii.*`), aperte al tipo del verbale. «Gestisce le assemblee» per ciò che scrive,
 * «Vede le assemblee» per la prova.
 */
class TrasmissioneController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private readonly TrasmissioneDelVerbale $trasmissione) {}

    public function togli(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea, $request);
        $dati = $request->validate(['anagrafica_id' => ['required', 'integer'], 'perche' => ['required', 'string', 'max:1000']],
            ['perche.required' => 'Scrivi perché la togli: resta nel registro delle modifiche.']);
        $this->trasmissione->togli($verbaleDellAssemblea, (int) $dati['anagrafica_id'], (string) $dati['perche'], $request->user());

        return back()->with($this->flashSuccess('Tolta dalla lista: resta nel registro delle modifiche, con il perché.'));
    }

    public function aggiungi(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea, $request);
        $dati = $request->validate(['anagrafica_id' => ['required', 'integer'], 'punti' => ['nullable', 'array'], 'punti.*' => ['integer'],
            'perche' => ['required', 'string', 'max:1000']], ['perche.required' => 'Scrivi perché la aggiungi: resta nel registro delle modifiche.']);
        $this->trasmissione->aggiungi($verbaleDellAssemblea, (int) $dati['anagrafica_id'], $dati['punti'] ?? null, (string) $dati['perche'], $request->user());

        return back()->with($this->flashSuccess('Aggiunta: riceve il verbale come assente, e i suoi trenta giorni partono dalla consegna.'));
    }

    /** «Manda le PEC» (`anagrafiche` restringe; `di_nuovo` la rimanda a chi ha la copia di prima). */
    public function pec(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea, $request);
        $esito = $this->trasmissione->mandaLePec($verbaleDellAssemblea, $this->anagrafiche($request), $request->user(), $request->boolean('di_nuovo'));

        return back()->with($esito['errore'] !== null ? $this->flashError($esito['frase']) : $this->flashSuccess($esito['frase']));
    }

    /** «Stampa le lettere»: il PDF unico, con la copia unita a ogni lettera. */
    public function lettere(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): Response
    {
        $this->assicuraLAssemblea($condominio, $assemblea, $request);
        $esito = $this->trasmissione->stampaLeLettere($verbaleDellAssemblea, $this->anagrafiche($request), $request->user(), $request->boolean('di_nuovo'));

        return response($esito['contenuto'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $esito['nome'] . '"',
            'X-Lettere' => (string) $esito['quante']]);
    }

    /** «Registra la spedizione» delle lettere del verbale: la raccomandata con il numero, il fax, la consegna a mano. */
    public function spedizione(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea, $request);
        $dati = $request->validate(['invii' => ['required', 'array', 'min:1'], 'invii.*' => ['integer'], 'canale' => ['required', 'in:raccomandata,a_mano,fax'],
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . \App\Helpers\DateHelper::oggiUtente()], 'numeri' => ['nullable', 'array'], 'nota' => ['nullable', 'string', 'max:500']]);
        // Una lettera stampata con la copia di prima non parte: si ristampa.
        $this->trasmissione->controllaLeLettereStampate($verbaleDellAssemblea, array_map('intval', $dati['invii']));
        $quante = app(ConsegneDellaConvocazione::class)->registraSpedizione($assemblea, array_map('intval', $dati['invii']), $dati['canale'], $dati['data'],
            (array) ($dati['numeri'] ?? []), $dati['nota'] ?? null, $request->user(), RegistroInvio::TIPO_VERBALE);

        return back()->with($this->flashSuccess($quante === 1 ? 'Spedizione registrata: 1 lettera.' : 'Spedizione registrata: ' . $quante . ' lettere.'));
    }

    /** La prova della trasmissione, il PDF. */
    public function prova(Request $request, Condominio $condominio, Assemblea $assemblea, VerbaleDellAssemblea $verbaleDellAssemblea): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));

        return response($this->trasmissione->prova($verbaleDellAssemblea), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'
            . PdfService::nomeFile('verbale-' . $verbaleDellAssemblea->numero, (string) $condominio->nome, null, 'prova-della-trasmissione') . '"']);
    }

    /** «Segna impugnata»: il giorno, la nota, l'atto facoltativo. Non ferma niente. */
    public function impugnata(Request $request, Condominio $condominio, Assemblea $assemblea, Delibera $delibera): RedirectResponse
    {
        $this->assicuraLAssemblea($condominio, $assemblea, $request);
        $dati = $request->validate(['giorno' => ['required', 'date_format:Y-m-d'], 'nota' => ['required', 'string', 'max:2000'],
            'atto' => ['nullable', 'file', 'max:' . LimiteCaricamento::regolaMax(10)]]);
        app(TerminiDellImpugnazione::class)->segnaImpugnata($assemblea, $delibera, $dati['giorno'], $dati['nota'], $request->file('atto'), $request->user());

        return back()->with($this->flashSuccess('Delibera segnata impugnata: è un segno, non ferma niente (art. 1137 c.c.).'));
    }

    /** @return ?list<int> */
    private function anagrafiche(Request $request): ?array
    {
        $dati = $request->validate(['anagrafiche' => ['nullable', 'array'], 'anagrafiche.*' => ['integer']]);

        return isset($dati['anagrafiche']) ? array_map('intval', $dati['anagrafiche']) : null;
    }

    private function assicuraLAssemblea(Condominio $condominio, Assemblea $assemblea, Request $request): void
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
    }
}
