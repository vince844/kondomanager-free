<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Enums\Assemblee\OrigineAssemblea;
use App\Enums\Assemblee\RuoloCollegamento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\DeliberaAManoRequest;
use App\Http\Requests\Assemblee\SpostaDeliberaRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * «Scegli la delibera» e «Registra una delibera a mano» (§3 P4 e P5; decisioni 500, 502, 562, 563) e i gesti su una delibera registrata a mano, «Sposta in
 * un'altra riunione» ed «Elimina» (R2, R17).
 *
 * «Scegli la delibera» e la finestra della delibera a mano rispondono con un JSON (standard 16: lo stesso componente in tredici posti). La scheda della
 * pagina arriva come tipo e id e passa dal risolutore (R18): una scheda di un altro condominio è 404. Niente si collega qui: il collegamento lo fa la pagina,
 * dopo il clic (R3).
 */
class DelibereController extends Controller
{
    use HandleFlashMessages;

    public function scegli(Request $request, Condominio $condominio): JsonResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $dati = $request->validate([
            'oggetto_tipo' => ['nullable', 'string', Rule::in(array_keys(SchedaDelCondominio::TIPI)), 'required_with:oggetto_id'],
            'oggetto_id' => ['nullable', 'integer', 'required_with:oggetto_tipo'],
            'ruolo' => ['required', Rule::in(RuoloCollegamento::valori())],
            'cerca' => ['nullable', 'string', 'max:100'],
        ], [], ['oggetto_tipo' => 'la scheda', 'oggetto_id' => 'la scheda', 'ruolo' => 'il ruolo della delibera', 'cerca' => 'la ricerca']);

        return response()->json(DelibereDelCondominio::scegli($condominio, $dati['oggetto_tipo'] ?? null,
            isset($dati['oggetto_id']) ? (int) $dati['oggetto_id'] : null, (string) $dati['ruolo'], $dati['cerca'] ?? null));
    }

    /** La riga sotto il numero del verbale (R8): «Entra nella riunione del 22/10/2026, verbale n. 41», o «Nasce una riunione nuova del …», con le riunioni a mano dello stesso giorno per «Cambia». */
    public function destinazione(Request $request, Condominio $condominio): JsonResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $dati = $request->validate([
            'giorno' => ['nullable', 'date_format:Y-m-d'],
            'numero_verbale' => ['nullable', 'string', 'max:50'],
            'ambito' => ['nullable', 'string', 'in:condominio,palazzina,scala'],
            'palazzina_id' => ['nullable', 'integer'],
            'scala_id' => ['nullable', 'integer'],
        ], [], ['giorno' => 'il giorno della riunione', 'numero_verbale' => 'il numero del verbale']);
        $giorno = $dati['giorno'] ?? null;
        $riunione = $giorno === null ? null : DeliberaAMano::riunioneDiDestinazione($condominio, $dati);
        $stessoGiorno = $giorno === null ? collect() : Assemblea::query()->where('condominio_id', $condominio->id)
            ->where('origine', OrigineAssemblea::A_MANO->value)->whereDate('data', $giorno)->orderBy('id')->get();

        return response()->json([
            'palazzine' => \App\Models\Palazzina::query()->where('condominio_id', $condominio->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'nome' => $p->name])->values()->all(),
            'scale' => \App\Models\Scala::query()->where('condominio_id', $condominio->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'nome' => $s->name])->values()->all(),
            'frase' => $giorno === null ? null : DeliberaAMano::fraseDiDestinazione($riunione, $giorno),
            'riunione' => $riunione === null ? null : ['id' => (int) $riunione->id, 'nome' => $riunione->nome()],
            'riunioni' => $stessoGiorno->map(fn (Assemblea $a) => ['id' => (int) $a->id, 'nome' => $a->nome()
                . (trim((string) $a->numero_verbale) !== '' ? ' · verbale n. ' . trim((string) $a->numero_verbale) : ' · senza numero del verbale')])->values()->all(),
            // «Di che cosa parla» della finestra aperta dall'archivio: «Scegli la delibera» propone per la stessa materia.
            'materie' => collect(\App\Enums\Assemblee\MateriaPunto::cases())->reject(fn ($m) => $m === \App\Enums\Assemblee\MateriaPunto::VARIE)
                ->map(fn ($m) => ['valore' => $m->value, 'nome' => $m->parola()])->values()->all(),
        ]);
    }

    /**
     * Registra la delibera a mano e la rende al componente nella forma del menu, già scelta: il collegamento con la scheda lo fa la pagina quando salva
     * (R3). Il file del verbale c'è solo con «Registra il verbale sottoscritto»; senza, la delibera nasce con «Verbale da caricare».
     */
    public function aMano(DeliberaAManoRequest $request, Condominio $condominio): JsonResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $ruolo = in_array($request->input('ruolo'), RuoloCollegamento::valori(), true) ? (string) $request->input('ruolo') : RuoloCollegamento::DELIBERA->value;
        $delibera = DeliberaAMano::registra($condominio, $request->dati(), $request->file('verbale'), $request->user(), $request->contesto());
        [$si, $perche] = DelibereDelCondominio::siSceglie($delibera, $ruolo);
        $assemblea = $delibera->assemblea;

        return response()->json([
            'delibera' => DelibereDelCondominio::riga($delibera, $si, $perche) + ['livello' => 1, 'perche_proposta' => null, 'conseguenza' => null],
            'assemblea' => ['id' => (int) $assemblea->id, 'nome' => $assemblea->nome(), 'numero_verbale' => $assemblea->numero_verbale,
                'verbale_da_caricare' => $assemblea->verbale_documento_id === null],
            'frase' => 'Delibera registrata a mano: ' . $assemblea->nome() . ($assemblea->verbale_documento_id === null ? ', verbale da caricare.' : ', con il verbale.'),
        ], 201);
    }

    /** «Sposta in un'altra riunione»: in una riunione a mano del condominio (risolta, R18), o in quella del giorno e del numero dati, che nasce se non c'è. */
    public function sposta(SpostaDeliberaRequest $request, Condominio $condominio, Delibera $delibera): RedirectResponse
    {
        abort_unless($delibera->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $destinazione = $request->validated();
        if (! empty($destinazione['riunione_id'])) {
            $destinazione['riunione_id'] = (int) SchedaDelCondominio::risolvi($condominio, 'assemblea', (int) $destinazione['riunione_id'])->getKey();
        }
        $spostata = DeliberaAMano::sposta($delibera, $destinazione, $request->user());
        $verso = $spostata->assemblea;

        return to_route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $verso->id])
            ->with($this->flashSuccess('Delibera spostata nella ' . mb_strtolower($verso->nome()) . '. Le schede che la usano non cambiano.'));
    }

    /**
     * «Elimina» una delibera registrata a mano senza collegamenti (R17); con i collegamenti la frase dice quali schede cambiare prima; con le cose da fare
     * del seguito chiede il `motivo`. Con `con_la_riunione`,
     * l'ultima delibera di una riunione a mano senza il verbale porta via anche la riunione, e la pagina torna all'archivio.
     */
    public function destroy(Request $request, Condominio $condominio, Delibera $delibera): RedirectResponse
    {
        abort_unless($delibera->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $conLaRiunione = $request->boolean('con_la_riunione') && DeliberaAMano::restaVuota($delibera);
        // Il motivo, obbligatorio quando la delibera ha cose da fare (fatte o «Non serve più»): resta nella riga del registro, con la fotografia.
        $motivo = $request->validate(['motivo' => ['nullable', 'string', 'max:2000']])['motivo'] ?? null;
        DeliberaAMano::elimina($delibera, $request->user(), $conLaRiunione, $motivo);

        if ($conLaRiunione) {
            return to_route('admin.gestionale.assemblee.index', ['condominio' => $condominio->id])
                ->with($this->flashSuccess('Delibera eliminata, e con lei la riunione rimasta vuota. Le righe restano nel registro delle modifiche.'));
        }

        return back()->with($this->flashSuccess('Delibera eliminata. La riga resta nel registro delle modifiche.'));
    }
}
